<?php

namespace Tests\Unit;

use App\Services\Offers\AutocadScheduleParser;
use App\Services\Offers\ScheduleTableReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

class ScheduleTableReaderTest extends TestCase
{
    public function test_table_breaker_counts_match_the_green_tree_offer(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR;
        $pdfs = [$root.'E-023.pdf', $root.'E-024.pdf'];
        $offer = $root.'Оферта Зелено дърво.xlsx';
        foreach ([...$pdfs, $offer] as $path) {
            if (! is_file($path)) {
                $this->markTestSkipped($path.' is missing.');
            }
        }

        $reader = new ScheduleTableReader(new AutocadScheduleParser);
        $got = [];
        $roles = [];
        $placed = 0;
        foreach ($pdfs as $path) {
            foreach ($reader->rows($path) as $row) {
                if ($row['x'] > 0 && $row['x'] < 1 && $row['y'] > 0 && $row['y'] < 1) {
                    $placed++;
                }
                $roles[$row['role']] = ($roles[$row['role']] ?? 0) + 1;
                if (! in_array($row['role'], ['breaker', 'mccb'], true)) {
                    continue;
                }
                $key = ($row['kind'] ?? '').'|'.$row['poles'].'|'.$row['current'].'|'.($row['curve'] ?? '');
                $got[$key] = ($got[$key] ?? 0) + 1;
            }
        }

        ksort($got);
        $this->assertSame($this->excelBreakers($offer), $got, json_encode($roles, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(40, $placed);
    }

    public function test_kolarov_schedules_are_read_from_the_breaker_column(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR;
        $paths = [];
        foreach (scandir($root) ?: [] as $name) {
            if (str_starts_with($name, '02-') || str_starts_with($name, '03-')) {
                $paths[$name] = $root.$name;
            }
        }
        if (count($paths) < 2) {
            $this->markTestSkipped('Kolarov board PDFs are missing.');
        }

        $reader = new ScheduleTableReader(new AutocadScheduleParser);
        $boards = [];
        foreach ($paths as $name => $path) {
            $rows = $reader->rows($path);
            $this->assertNotEmpty($rows, $name);
            foreach ($rows as $row) {
                $this->assertGreaterThan(0, $row['x']);
                $this->assertLessThan(1, $row['x']);
                $boards[$name][$row['board']][$row['kind'].'|'.$row['poles'].'|'.$row['current'].'|'.($row['curve'] ?? '')]
                    = ($boards[$name][$row['board']][$row['kind'].'|'.$row['poles'].'|'.$row['current'].'|'.($row['curve'] ?? '')] ?? 0) + 1;
            }
        }

        foreach ($boards as $name => $byBoard) {
            $expected = str_starts_with($name, '02-') ? 'ГЕТ А' : 'ГЕТ Б';
            $this->assertSame([$expected], array_keys($byBoard), $name);
            $this->assertSame(2, $byBoard[$expected]['ПЛК|3|250|'] ?? 0, $name);
            $this->assertGreaterThan(10, $byBoard[$expected]['МАП|1|16|C'] ?? 0, $name);
            $this->assertGreaterThan(0, $byBoard[$expected]['МАП|1|50|C'] ?? 0, $name);
        }
    }

    /**
     * @return array<string, int>
     */
    private function excelBreakers(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheetByName('Табла');
        $ratings = [];
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $sheet->getCell('C'.$row)->getValue());
            $qty = (int) $sheet->getCell('D'.$row)->getValue();
            if ($qty < 1) {
                continue;
            }
            $key = null;
            if (preg_match('/EB2\s+\d+\/\d+\S*\s+(\d+)A\s+(\d+)p/u', $name, $match) === 1) {
                $key = 'ПЛК|'.$match[2].'|'.$match[1].'|';
            } elseif (preg_match('/ETIMAT\s+6\s+(\d+)p\s+([BCD])(\d+)/u', $name, $match) === 1) {
                $key = 'МАП|'.$match[1].'|'.$match[3].'|'.$match[2];
            }
            if ($key !== null) {
                $ratings[$key] = ($ratings[$key] ?? 0) + $qty;
            }
        }
        ksort($ratings);

        return $ratings;
    }
}
