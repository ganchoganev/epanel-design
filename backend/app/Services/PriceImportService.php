<?php

namespace App\Services;

use App\Models\EtiProduct;
use App\Models\PriceImportProfile;
use App\Support\CatalogCode;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PriceImportService
{
    /**
     * @return array{headers: array<int, string>, preview_rows: array<int, array<int, string|null>>, suggested_mapping: array<string, int|null>}
     */
    public function preview(UploadedFile $file, int $headerRow = 1, int $previewLimit = 10): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headerIndex = max(1, $headerRow);
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
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $updated = 0;
        $created = 0;
        $notFound = 0;
        $skipped = 0;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex <= $headerRow) {
                continue;
            }

            $values = array_values($row);
            $catalogNumber = CatalogCode::normalize((string) ($values[$columnMapping['catalog_number']] ?? ''));
            $priceRaw = $values[$columnMapping['price']] ?? null;

            if ($catalogNumber === '' || $priceRaw === null || $priceRaw === '') {
                $skipped++;

                continue;
            }

            $currency = 'EUR';
            if (isset($columnMapping['currency'])) {
                $currencyValue = trim((string) ($values[$columnMapping['currency']] ?? ''));
                if ($currencyValue !== '') {
                    $currency = strtoupper($currencyValue);
                }
            }

            $price = (float) str_replace(',', '.', (string) preg_replace('/[^\d.,-]/', '', (string) $priceRaw));
            $product = EtiProduct::where('catalog_number', $catalogNumber)->first();
            if (! $product) {
                EtiProduct::create([
                    'catalog_number' => $catalogNumber,
                    'name' => $catalogNumber,
                    'price' => $price,
                    'currency' => $currency,
                    'data_source' => 'price_import',
                    'verified' => false,
                    'width_modules' => 1,
                ]);
                $created++;

                continue;
            }

            $product->update([
                'price' => $price,
                'currency' => $currency,
                'data_source' => 'price_import',
            ]);

            $updated++;
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
            'price' => null,
            'currency' => null,
        ];

        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower($header);

            if ($mapping['catalog_number'] === null && preg_match('/(кат|catalog|article|part|номер|sku|код)/iu', $normalized)) {
                $mapping['catalog_number'] = $index;
            }

            if ($mapping['price'] === null && preg_match('/(цена|price|единична)/iu', $normalized)) {
                $mapping['price'] = $index;
            }

            if ($mapping['currency'] === null && preg_match('/(валута|currency)/iu', $normalized)) {
                $mapping['currency'] = $index;
            }
        }

        return $mapping;
    }
}
