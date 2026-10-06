<?php

namespace App\Services;

use App\Models\EtiProduct;
use App\Models\ScheduleMap;
use App\Support\CatalogCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * A filled template adds products ETI has not published yet: the new code,
 * the code it replaces, and the electrical values the offer compares.
 */
class CatalogSupplement
{
    /** @var list<string> */
    public const COLUMNS = [
        'Код',
        'Наименование',
        'Заменя код',
        'Серия',
        'Категория',
        'Полюси',
        'Ток A',
        'Крива',
        'Дефектен ток mA',
        'Тип ДТЗ',
        'Цена EUR',
        'Надпис от схемата',
    ];

    public function preparedPath(): ?string
    {
        foreach ([base_path('../test/novi-kodove.xlsx'), base_path('../test/novi-kodove.xls')] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Данни');
        foreach (self::COLUMNS as $index => $title) {
            $sheet->setCellValue([$index + 1, 1], $title);
        }
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $help = $book->createSheet();
        $help->setTitle('Упътване');
        $help->setCellValue('A1', 'Попълва се само лист „Данни“. Този лист не се чете.');
        $help->setCellValue('A3', 'Код');
        $help->setCellValue('B3', 'Новият код на ETI, както трябва да излезе в офертата.');
        $help->setCellValue('A4', 'Заменя код');
        $help->setCellValue('B4', 'Старият код от каталога. Празно, ако кодът е нов, а не замяна.');
        $help->setCellValue('A5', 'Категория');
        $help->setCellValue('B5', 'MCB, MCCB, RCD или RCBO. Може и МАП, ПЛК, ДТЗ.');
        $help->setCellValue('A6', 'Полюси, ток, крива, дефектен ток, тип');
        $help->setCellValue('B6', 'С тях четенето на схемата намира този код. Дефектният ток е в mA, например 30.');
        $help->setCellValue('A7', 'Надпис от схемата');
        $help->setCellValue('B7', 'Точният текст до апарата, например C/16A/1 или 40A/2P/30mA AC. Следващо четене ползва този код.');
        $help->setCellValue('A9', 'Пример, не го копирай в „Данни“, освен ако кодът е истински:');
        $help->fromArray(self::COLUMNS, null, 'A10');
        $help->fromArray([
            '002062123',
            'Дефектнотокова защита EFI-2 AC 40/003',
            '002061112',
            'EFI-2',
            'RCD',
            2,
            40,
            '',
            30,
            'AC',
            33.57,
            '40A/2P/30mA AC',
        ], null, 'A11');
        $help->getColumnDimension('A')->setWidth(28);
        $help->getColumnDimension('B')->setWidth(88);

        $target = storage_path('app/offers/novi-kodove.xlsx');
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        (new Xlsx($book))->save($target);

        return $target;
    }

    /**
     * @return array{imported: int, replaced: int, linked: int, skipped: int}
     */
    public function import(UploadedFile $file): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getSheetByName('Данни')
            ?? IOFactory::load($file->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $header = null;
        $headerRow = null;
        foreach (array_slice($rows, 0, 5) as $index => $row) {
            $names = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), $row);
            if (in_array('код', $names, true) && in_array('наименование', $names, true)) {
                $header = $names;
                $headerRow = $index;
                break;
            }
        }
        if ($header === null) {
            throw new RuntimeException('Листът трябва да е шаблонът: колона „Код“ и колона „Наименование“. Свали шаблона и попълни лист „Данни“.');
        }

        $imported = 0;
        $replaced = 0;
        $linked = 0;
        $skipped = 0;
        foreach (array_slice($rows, $headerRow + 1) as $row) {
            $value = fn (string $name) => $this->cell($header, $row, $name);
            $code = CatalogCode::normalize($value('код'));
            if (! CatalogCode::looksLike($code)) {
                if (implode('', array_map(fn ($cell) => trim((string) $cell), $row)) !== '') {
                    $skipped++;
                }
                continue;
            }
            $name = $value('наименование');
            if ($name === '') {
                $name = $code;
            }
            $this->storeProduct($code, $name, $value);
            $imported++;

            $old = CatalogCode::normalize($value('заменя код'));
            if (CatalogCode::looksLike($old) && $old !== $code) {
                $this->storeReplacement($old, $code);
                $replaced++;
            }

            $label = $value('надпис от схемата');
            if ($label !== '') {
                ScheduleMap::query()->updateOrCreate(
                    ['signature' => ScheduleMap::signature($label)],
                    [
                        'catalog_number' => $code,
                        'name' => $name,
                        'unit_price' => $this->decimal($value('цена eur')),
                        'source' => 'шаблон',
                    ]
                );
                $linked++;
            }
        }

        if ($imported === 0) {
            throw new RuntimeException('Няма ред с код. Попълни лист „Данни“ и качи файла отново.');
        }

        return compact('imported', 'replaced', 'linked', 'skipped');
    }

    /**
     * @param  list<string>  $header
     * @param  list<mixed>  $row
     */
    private function cell(array $header, array $row, string $name): string
    {
        $index = array_search($name, $header, true);

        return $index === false ? '' : trim((string) ($row[$index] ?? ''));
    }

    /** @param  callable(string): string  $value */
    private function storeProduct(string $code, string $name, callable $value): void
    {
        $existing = EtiProduct::query()->where('catalog_number', $code)->first();
        $poles = $this->int($value('полюси'));
        $category = $this->category($value('категория'), $value('крива'), $value('дефектен ток ma'));
        $attributes = [
            'eti_code' => $code,
            'name' => $name,
            'series' => $value('серия') !== '' ? $value('серия') : null,
            'category' => $category,
            'poles' => $poles,
            'rated_current_a' => $this->decimal($value('ток a')),
            'trip_curve' => $this->curve($value('крива')),
            'residual_current_a' => $this->residual($value('дефектен ток ma')),
            'rcd_type' => $this->rcdType($value('тип дтз')),
            'currency' => 'EUR',
            'data_source' => 'supplement',
            'verified' => true,
            'mounting_type' => 'DIN',
        ];
        $price = $this->decimal($value('цена eur'));
        if ($existing instanceof EtiProduct && $existing->data_source === 'price_import' && $existing->price !== null && $price === null) {
            $attributes['data_source'] = 'price_import';
        } elseif ($price !== null) {
            $attributes['price'] = $price;
        }
        if ($poles !== null && $poles > 0) {
            $attributes['width_modules'] = $poles;
            $attributes['width_mm'] = $poles * 18;
        }
        foreach (['series', 'category', 'poles', 'rated_current_a', 'trip_curve', 'residual_current_a', 'rcd_type'] as $key) {
            if ($attributes[$key] === null) {
                unset($attributes[$key]);
            }
        }

        EtiProduct::query()->updateOrCreate(['catalog_number' => $code], $attributes);
    }

    private function storeReplacement(string $from, string $to): void
    {
        $schema = Schema::connection('eticad');
        if (! $schema->hasTable('code_replacements')) {
            $schema->create('code_replacements', function ($table): void {
                $table->string('from_code', 32)->primary();
                $table->string('to_code', 32);
            });
        }
        DB::connection('eticad')->table('code_replacements')->updateOrInsert(
            ['from_code' => $from],
            ['to_code' => $to]
        );
    }

    private function category(string $value, string $curve, string $residual): ?string
    {
        $value = mb_strtoupper(trim($value));
        $known = [
            'MCB' => 'MCB', 'МАП' => 'MCB',
            'MCCB' => 'MCCB', 'ПЛК' => 'MCCB',
            'RCD' => 'RCD', 'ДТЗ' => 'RCD',
            'RCBO' => 'RCBO', 'КЗС' => 'RCBO',
        ];
        if (isset($known[$value])) {
            return $known[$value];
        }
        if ($value !== '') {
            return $value;
        }
        if ($residual !== '' && $curve !== '') {
            return 'RCBO';
        }
        if ($residual !== '') {
            return 'RCD';
        }
        if ($curve !== '') {
            return 'MCB';
        }

        return null;
    }

    private function curve(string $value): ?string
    {
        $value = strtoupper(str_replace(['С', 'В'], ['C', 'B'], trim($value)));

        return in_array($value, ['B', 'C', 'D'], true) ? $value : null;
    }

    private function rcdType(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return in_array($value, ['AC', 'A', 'B', 'F'], true) ? $value : null;
    }

    private function residual(string $value): ?float
    {
        $milli = $this->decimal($value);

        return $milli === null ? null : $milli / 1000;
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
