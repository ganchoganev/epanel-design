<?php

namespace App\Services;

use App\Models\EtiProduct;
use App\Support\CatalogCode;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The offer reads eti_products. This file carries that table from the Windows
 * machine, where ETICAD was loaded, to the Linux server, which has no ETICAD.
 */
class CatalogFile
{
    /** @var list<string> */
    public const COLUMNS = [
        'Код',
        'Наименование',
        'Серия',
        'Категория',
        'Полюси',
        'Ток A',
        'Крива',
        'Отключваща способност kA',
        'Дефектен ток A',
        'Тип ДТЗ',
        'Модули',
        'Цена EUR',
        'Източник',
    ];

    public function export(): string
    {
        $target = storage_path('app/offers/katalog.csv');
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Каталогът не можа да се запише.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, ';');
        EtiProduct::query()->orderBy('catalog_number')->chunk(500, function ($products) use ($handle): void {
            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->catalog_number,
                    $product->name,
                    $product->series,
                    $product->category,
                    $product->poles,
                    $product->rated_current_a,
                    $product->trip_curve,
                    $product->breaking_capacity_ka,
                    $product->residual_current_a,
                    $product->rcd_type,
                    $product->width_modules,
                    $product->price,
                    $product->data_source,
                ], ';');
            }
        });
        fclose($handle);

        return $target;
    }

    /**
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function import(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath() ?: '', 'rb');
        if ($handle === false) {
            throw new RuntimeException('Файлът не се отвори.');
        }
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            throw new RuntimeException('Файлът е празен.');
        }
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine) ?? $headerLine;
        $delimiter = substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
        $header = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), str_getcsv($headerLine, $delimiter));
        if (! in_array('код', $header, true) || ! in_array('наименование', $header, true)) {
            fclose($handle);
            throw new RuntimeException('Файлът трябва да е сваленият каталог: колони „Код“ и „Наименование“.');
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $batch = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $value = function (string $name) use ($header, $row): string {
                $index = array_search($name, $header, true);

                return $index === false ? '' : trim((string) ($row[$index] ?? ''));
            };
            $code = CatalogCode::normalize($value('код'));
            if (! CatalogCode::looksLike($code)) {
                if (implode('', $row) !== '') {
                    $skipped++;
                }
                continue;
            }
            $batch[] = [
                'catalog_number' => $code,
                'eti_code' => $code,
                'name' => $value('наименование') !== '' ? $value('наименование') : $code,
                'series' => $this->nullable($value('серия')),
                'category' => $this->nullable($value('категория')),
                'poles' => $this->int($value('полюси')),
                'rated_current_a' => $this->decimal($value('ток a')),
                'trip_curve' => $this->nullable($value('крива')),
                'breaking_capacity_ka' => $this->decimal($value('отключваща способност ka')),
                'residual_current_a' => $this->decimal($value('дефектен ток a')),
                'rcd_type' => $this->nullable($value('тип дтз')),
                'width_modules' => $this->int($value('модули')) ?? 1,
                'price' => $this->decimal($value('цена eur')),
                'currency' => 'EUR',
                'data_source' => $this->nullable($value('източник')) ?? 'eticad',
                'verified' => true,
            ];
            if (count($batch) >= 300) {
                [$imported, $updated] = $this->write($batch, $imported, $updated);
                $batch = [];
            }
        }
        fclose($handle);
        if ($batch !== []) {
            [$imported, $updated] = $this->write($batch, $imported, $updated);
        }
        if ($imported + $updated === 0) {
            throw new RuntimeException('Няма ред с код.');
        }

        return compact('imported', 'updated', 'skipped');
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @return array{0: int, 1: int}
     */
    private function write(array $batch, int $imported, int $updated): array
    {
        $now = now()->toDateTimeString();
        $codes = array_column($batch, 'catalog_number');
        $existing = array_flip(EtiProduct::query()->whereIn('catalog_number', $codes)->pluck('catalog_number')->all());
        $rows = [];
        foreach ($batch as $row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            if (isset($existing[$row['catalog_number']])) {
                $updated++;
            } else {
                $imported++;
            }
        }
        EtiProduct::query()->upsert($rows, ['catalog_number'], [
            'eti_code',
            'name',
            'series',
            'category',
            'poles',
            'rated_current_a',
            'trip_curve',
            'breaking_capacity_ka',
            'residual_current_a',
            'rcd_type',
            'width_modules',
            'price',
            'currency',
            'data_source',
            'verified',
            'updated_at',
        ]);

        return [$imported, $updated];
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function decimal(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function int(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
