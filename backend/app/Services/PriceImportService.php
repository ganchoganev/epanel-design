<?php

namespace App\Services;

use App\Models\EtiProduct;
use App\Models\PriceImportProfile;
use App\Support\CatalogCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class PriceImportService
{
    /**
     * @return array{headers: array<int, string>, preview_rows: array<int, array<int, string|null>>, suggested_mapping: array<string, int|null>}
     */
    public function preview(UploadedFile $file, int $headerRow = 1, int $previewLimit = 10): array
    {
        [$rows, $headerIndex] = $this->rows($file, $headerRow);
        $headers = array_values($rows[$headerIndex] ?? []);
        $headers = array_map(fn ($value) => trim((string) $value), $headers);

        $previewRows = [];
        $rowNumber = $headerIndex + 1;
        $count = 0;

        while ($count < $previewLimit && isset($rows[$rowNumber])) {
            $previewRows[] = array_values($rows[$rowNumber]);
            $rowNumber++;
            $count++;
        }

        return [
            'headers' => $headers,
            'preview_rows' => $previewRows,
            'suggested_mapping' => $this->suggestMapping($headers),
        ];
    }

    /**
     * @param  array<string, int>  $columnMapping
     * @return array{updated: int, not_found: int, skipped: int}
     */
    public function import(UploadedFile $file, array $columnMapping, int $headerRow = 1): array
    {
        [$rows, $headerRow] = $this->rows($file, $headerRow);

        $updated = 0;
        $created = 0;
        $notFound = 0;
        $skipped = 0;
        $batch = [];

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerRow) {
                continue;
            }

            $values = array_values($row);
            $catalogNumber = CatalogCode::normalize((string) ($values[$columnMapping['catalog_number']] ?? ''));
            $priceRaw = $values[$columnMapping['price']] ?? null;

            if (! CatalogCode::looksLike($catalogNumber) || $priceRaw === null || $priceRaw === '') {
                $skipped++;

                continue;
            }

            $currency = 'EUR';
            if (isset($columnMapping['currency']) && $columnMapping['currency'] !== null && $columnMapping['currency'] !== '') {
                $currencyValue = trim((string) ($values[$columnMapping['currency']] ?? ''));
                if ($currencyValue !== '') {
                    $currency = strtoupper($currencyValue);
                }
            }

            $price = $this->money((string) $priceRaw);
            if ($price === null) {
                $skipped++;

                continue;
            }
            $batch[] = [
                'catalog_number' => $catalogNumber,
                'name' => $this->mappedText($values, $columnMapping, 'name'),
                'series' => $this->mappedText($values, $columnMapping, 'model'),
                'price' => $price,
                'currency' => $currency,
            ];
            if (count($batch) >= 400) {
                [$created, $updated] = $this->writePrices($batch, $created, $updated);
                $batch = [];
            }
        }
        if ($batch !== []) {
            [$created, $updated] = $this->writePrices($batch, $created, $updated);
        }

        return compact('updated', 'created', 'notFound', 'skipped');
    }

    public function storeProfile(string $name, array $columnMapping, int $headerRow = 1): PriceImportProfile
    {
        return PriceImportProfile::create([
            'name' => $name,
            'column_mapping' => $columnMapping,
            'header_row' => $headerRow,
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    private function suggestMapping(array $headers): array
    {
        $mapping = [
            'catalog_number' => null,
            'name' => null,
            'model' => null,
            'price' => null,
            'currency' => null,
        ];

        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim($header));
            if ($normalized === '') {
                continue;
            }

            if ($mapping['catalog_number'] === null && preg_match('/^(код|кат\.?\s*номер|catalog|article|sku)$/u', $normalized) === 1) {
                $mapping['catalog_number'] = $index;
            }
            if ($mapping['name'] === null && preg_match('/описание|наименование|description/u', $normalized) === 1) {
                $mapping['name'] = $index;
            }
            if ($mapping['model'] === null && preg_match('/^модел$|^model$/u', $normalized) === 1) {
                $mapping['model'] = $index;
            }
            if ($mapping['price'] === null && preg_match('/бруто|цена|price|единична|eur|€/u', $normalized) === 1) {
                $mapping['price'] = $index;
            }
            if ($mapping['currency'] === null && preg_match('/^(валута|currency)$/u', $normalized) === 1) {
                $mapping['currency'] = $index;
            }
        }

        return $mapping;
    }

    /**
     * The current price sheet is the one whose header has a code column and a gross EUR column.
     * When several years are present, the latest year is used.
     *
     * @return array{0: array<int, array<int|string, mixed>>, 1: int}
     */
    /**
     * @param  list<array{catalog_number: string, name: string, series: string, price: float, currency: string}>  $batch
     * @return array{0: int, 1: int}
     */
    private function writePrices(array $batch, int $created, int $updated): array
    {
        $now = now()->toDateTimeString();
        $codes = array_column($batch, 'catalog_number');
        DB::transaction(function () use ($batch, $codes, $now, &$created, &$updated): void {
            $existing = EtiProduct::query()->whereIn('catalog_number', $codes)->pluck('catalog_number')->all();
            $existing = array_flip($existing);
            $inserts = [];
            foreach ($batch as $row) {
                if (isset($existing[$row['catalog_number']])) {
                    $changes = [
                        'price' => $row['price'],
                        'currency' => $row['currency'],
                        'data_source' => 'price_import',
                        'updated_at' => $now,
                    ];
                    if ($row['name'] !== '') {
                        $changes['name'] = $row['name'];
                    }
                    EtiProduct::query()->where('catalog_number', $row['catalog_number'])->update($changes);
                    $updated++;

                    continue;
                }
                $inserts[] = [
                    'catalog_number' => $row['catalog_number'],
                    'name' => $row['name'] !== '' ? $row['name'] : $row['catalog_number'],
                    'series' => $row['series'] !== '' ? $row['series'] : null,
                    'price' => $row['price'],
                    'currency' => $row['currency'],
                    'data_source' => 'price_import',
                    'verified' => false,
                    'width_modules' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $created++;
            }
            if ($inserts !== []) {
                EtiProduct::query()->insert($inserts);
            }
        });

        return [$created, $updated];
    }

    /**
     * Only the price sheet is loaded, and only its values. A full styled workbook
     * of both years otherwise exhausts the PHP memory limit and the browser
     * reports "Failed to fetch".
     *
     * @return array{0: array<int, array<int|string, mixed>>, 1: int}
     */
    private function rows(UploadedFile $file, int $headerRow): array
    {
        ini_set('memory_limit', '512M');
        $path = $file->getRealPath() ?: '';
        $reader = IOFactory::createReaderForFile($path);
        if (! method_exists($reader, 'listWorksheetNames')) {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, true);

            return [$rows, max(1, $headerRow)];
        }

        $best = null;
        foreach ($reader->listWorksheetNames($path) as $name) {
            $rows = $this->sheetRows($path, $name, 8);
            foreach ($rows as $index => $row) {
                if ($index > 8) {
                    break;
                }
                $headers = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), array_values($row));
                $mapping = $this->suggestMapping($headers);
                if ($mapping['catalog_number'] === null || $mapping['price'] === null) {
                    continue;
                }
                $year = 0;
                if (preg_match('/(20\d{2})/', $name.' '.implode(' ', $headers), $match) === 1) {
                    $year = (int) $match[1];
                }
                if ($best === null || $year >= $best['year']) {
                    $best = ['name' => $name, 'header' => (int) $index, 'year' => $year];
                }
                break;
            }
        }
        if ($best === null) {
            $rows = $this->sheetRows($path, $reader->listWorksheetNames($path)[0], null);

            return [$rows, max(1, $headerRow)];
        }

        return [$this->sheetRows($path, $best['name'], null), $best['header']];
    }

    /** @return array<int, array<int|string, mixed>> */
    private function sheetRows(string $path, string $sheetName, ?int $maxRow): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly($sheetName);
        }
        if (method_exists($reader, 'setReadFilter')) {
            $reader->setReadFilter(new class($maxRow) implements IReadFilter
            {
                public function __construct(private ?int $maxRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    if (Coordinate::columnIndexFromString($columnAddress) > 12) {
                        return false;
                    }

                    return $this->maxRow === null || $row <= $this->maxRow;
                }
            });
        }
        $book = $reader->load($path);
        $rows = $book->getActiveSheet()->toArray(null, true, true, true);
        $book->disconnectWorksheets();

        return $rows;
    }

    /** @param  list<mixed>  $values */
    private function mappedText(array $values, array $columnMapping, string $key): string
    {
        if (! isset($columnMapping[$key]) || $columnMapping[$key] === null || $columnMapping[$key] === '') {
            return '';
        }

        return trim((string) ($values[$columnMapping[$key]] ?? ''));
    }

    private function money(string $raw): ?float
    {
        $raw = str_replace(["\xc2\xa0", ' '], '', trim($raw));
        $raw = preg_replace('/[^\d.,-]/', '', $raw) ?? '';
        if ($raw === '' || $raw === '-' || $raw === ',') {
            return null;
        }
        $comma = strrpos($raw, ',');
        $dot = strrpos($raw, '.');
        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } else {
            $raw = str_replace(',', '.', $raw);
        }
        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }
}
