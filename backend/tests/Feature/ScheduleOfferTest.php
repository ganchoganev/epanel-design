<?php

namespace Tests\Feature;

use App\Models\ScheduleMap;
use App\Services\Offers\AutocadScheduleParser;
use App\Services\Offers\OfferDraft;
use App\Services\Offers\OfferWorkbookWriter;
use App\Services\Offers\ScheduleCircuit;
use App\Services\Offers\ScheduleClaudeReader;
use App\Services\Offers\ScheduleOfferMapper;
use Database\Seeders\EtiCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ScheduleOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_b_pdf_maps_only_real_eti_codes(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        $draft = $this->draft();

        $this->assertSame([], $draft->unmatched);
        $placed = 0;
        foreach ($draft->placements as $mark) {
            $this->assertGreaterThanOrEqual(0, $mark['x']);
            $this->assertLessThanOrEqual(1, $mark['x']);
            $this->assertGreaterThanOrEqual(0, $mark['y']);
            $this->assertLessThanOrEqual(1, $mark['y']);
            $placed++;
        }
        $expected = 0;
        foreach ($draft->boards as $board) {
            foreach ($board->lines as $line) {
                $expected += $line->quantity;
            }
        }
        $this->assertSame($expected, $placed);
        $this->assertEquals(
            [
                '004671073' => 1,
                '001900336' => 1,
                '001900334' => 1,
                '001900030' => 9,
                '001900034' => 4,
                '001900035' => 21,
            ],
            $this->quantities($draft)['ГЕТ Б секция 1']
        );
        $this->assertEquals(
            [
                '004671073' => 1,
                '001900030' => 12,
                '001900034' => 5,
                '001900035' => 19,
                '001900036' => 1,
            ],
            $this->quantities($draft)['ГЕТ Б секция 2']
        );

        foreach ($draft->boards as $board) {
            foreach ($board->lines as $line) {
                $this->assertNotContains($line->catalogNumber, ['002423114', '002423314']);
            }
        }
    }

    public function test_reference_offer_diff_is_only_sv_and_known_count_gaps(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        $reference = $this->referenceQuantities();
        if ($reference === []) {
            $this->markTestSkipped('В test/ няма ръчна оферта с табло ГЕТ Б.');
        }
        $parsed = $this->quantities($this->draft());

        $diff = [];
        foreach ($reference as $board => $lines) {
            if (! str_contains($board, 'ГЕТ Б')) {
                continue;
            }
            foreach ($lines as $code => $qty) {
                $got = $parsed[$board][$code] ?? 0;
                if ($got !== $qty) {
                    $diff[] = $board.' '.$code.' excel='.$qty.' pdf='.$got;
                }
            }
            foreach ($parsed[$board] ?? [] as $code => $qty) {
                if (! isset($lines[$code])) {
                    $diff[] = $board.' '.$code.' excel=0 pdf='.$qty;
                }
            }
        }
        sort($diff);

        $this->assertSame([
            'ГЕТ Б секция 1 001900035 excel=22 pdf=21',
            'ГЕТ Б секция 1 002423114 excel=34 pdf=0',
            'ГЕТ Б секция 1 002423314 excel=4 pdf=0',
            'ГЕТ Б секция 2 002423114 excel=37 pdf=0',
            'ГЕТ Б секция 2 002423314 excel=2 pdf=0',
        ], $diff);
    }

    public function test_single_line_diagram_reads_rating_text_as_breakers(): void
    {
        $path = dirname(base_path()).DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR.'E-023.pdf';
        if (! is_file($path)) {
            $this->markTestSkipped('E-023.pdf is not in test/.');
        }

        $this->seed(EtiCatalogSeeder::class);
        $draft = app(ScheduleOfferMapper::class)->map(
            app(AutocadScheduleParser::class)->parse($path)
        );
        $quantities = $this->quantities($draft);

        $this->assertSame(5, $quantities['ГЕТ']['001900036'] ?? 0);
        $this->assertSame(4, $quantities['ГЕТ']['001900030'] ?? 0);
        $this->assertNotEmpty($draft->unmatched);
        $this->assertStringContainsString('ETI', $draft->unmatched[0]['reason']);
    }

    public function test_a_breaker_without_a_curve_is_listed_as_unmapped(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        $draft = app(ScheduleOfferMapper::class)->map([
            new ScheduleCircuit('ГЕТ', 'МАП', 1, 32, null, '32A/1P'),
        ]);

        $this->assertSame([], $draft->boards);
        $this->assertSame('32A/1P', $draft->unmatched[0]['rating']);
        $this->assertStringContainsString('32A/1P', $draft->unmatched[0]['reason']);
        $this->assertStringContainsString('крива', $draft->unmatched[0]['reason']);
    }

    public function test_green_tree_pdfs_match_the_manual_offer_when_claude_is_configured(): void
    {
        if ((string) config('claude.claude_api_key') === '') {
            $this->markTestSkipped('CLAUDE_API_KEY is empty.');
        }

        $root = dirname(base_path()).DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR;
        $pdfs = [$root.'E-023.pdf', $root.'E-024.pdf'];
        $offer = $root.'Оферта Зелено дърво.xlsx';
        foreach ([...$pdfs, $offer] as $path) {
            if (! is_file($path)) {
                $this->markTestSkipped($path.' is missing.');
            }
        }

        $this->seed(EtiCatalogSeeder::class);
        $uploaded = array_map(
            fn (string $path) => new \Illuminate\Http\UploadedFile($path, basename($path), 'application/pdf', null, true),
            $pdfs
        );
        $read = app(ScheduleClaudeReader::class)->read($uploaded);
        $draft = app(ScheduleOfferMapper::class)->map($read['circuits']);
        $readQty = [];
        foreach ($read['circuits'] as $circuit) {
            $key = $circuit->deviceType.'|'.$circuit->poles.'|'.(int) $circuit->current.'|'.($circuit->curve ?? '');
            $readQty[$key] = ($readQty[$key] ?? 0) + 1;
        }

        $diff = [];
        foreach ($this->greenTreeRatings($offer) as $key => $qty) {
            $seen = $readQty[$key] ?? 0;
            if ($seen < $qty) {
                $diff[] = $key.' excel='.$qty.' read='.$seen;
            }
        }
        foreach ($draft->unmatched as $row) {
            $this->assertNotSame('', trim($row['reason']));
        }
        foreach ($read['unread'] as $row) {
            $this->assertNotSame('', trim($row['text']));
            $this->assertNotSame('', trim($row['reason']));
        }

        $gaps = array_map(
            fn (array $row) => $row['board'].' '.$row['device_type'].' '.$row['rating'].' ×'.$row['quantity'].' — '.$row['reason'],
            $draft->unmatched
        );
        $unread = array_map(
            fn (array $row) => $row['board'].' '.$row['text'].' ×'.$row['quantity'].' — '.$row['reason'],
            $read['unread']
        );
        $this->assertSame(
            [],
            $diff,
            "От схемата липсва апарат, който е в офертата:\n".implode("\n", $diff)
            ."\nБез код на ETI:\n".implode("\n", $gaps)
            ."\nНепрочетено:\n".implode("\n", $unread)
        );
    }

    public function test_template_adds_a_code_the_catalog_does_not_have(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Данни');
        $sheet->fromArray(\App\Services\CatalogSupplement::COLUMNS, null, 'A1');
        $sheet->fromArray([
            '009990001',
            'Нов автоматичен прекъсвач 1p C16',
            '002141516',
            'ETIMAT 10',
            'MCB',
            1,
            16,
            'C',
            '',
            '',
            4.2,
            'C/16A/1',
        ], null, 'A2');
        $path = storage_path('framework/testing/supplement.xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $result = app(\App\Services\CatalogSupplement::class)->import(
            new \Illuminate\Http\UploadedFile($path, 'novi-kodove.xlsx', null, null, true)
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['replaced']);
        $this->assertSame(1, $result['linked']);
        $draft = app(ScheduleOfferMapper::class)->map([
            new ScheduleCircuit('Т1', 'МАП', 1, 16, 'C', 'C/16A/1'),
        ]);
        $this->assertSame(1, $this->quantities($draft)['Т1']['009990001'] ?? 0);

        ScheduleMap::query()->delete();
        $byParameters = app(ScheduleOfferMapper::class)->map([
            new ScheduleCircuit('Т1', 'МАП', 1, 16, 'C', '16A/1P/C'),
        ]);
        $this->assertSame(1, $this->quantities($byParameters)['Т1']['009990001'] ?? 0);
    }

    public function test_saved_offer_map_fills_residual_and_motor_rows(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        $draft = app(ScheduleOfferMapper::class)->map([
            new ScheduleCircuit('T1', 'ДТЗ', 2, 40, null, '40A/2P/30mA AC'),
            new ScheduleCircuit('T1', 'ДТЗ', 2, 16, 'C', 'C16A/1+N'),
            new ScheduleCircuit('ТО', 'ТЗ', 3, 0, null, '2,5-4A'),
        ]);

        $this->assertSame([], $draft->unmatched);
        $this->assertSame(1, $this->quantities($draft)['T1']['002062123'] ?? 0);
        $this->assertSame(1, $this->quantities($draft)['T1']['002173124'] ?? 0);
        $this->assertSame(1, $this->quantities($draft)['ТО']['004600080'] ?? 0);
    }

    public function test_residual_rating_matches_one_catalog_row_when_nothing_is_remembered(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        ScheduleMap::query()->delete();
        $draft = app(ScheduleOfferMapper::class)->map([
            new ScheduleCircuit('T1', 'ДТЗ', 2, 40, null, '40A/2P/30mA AC'),
        ]);

        $this->assertSame([], $draft->unmatched);
        $this->assertSame(1, $this->quantities($draft)['T1']['002061112'] ?? 0);
    }

    public function test_workbook_fills_table_sheet_and_keeps_summary_formulas(): void
    {
        $this->seed(EtiCatalogSeeder::class);
        $draft = $this->draft();
        $target = storage_path('framework/testing/offer.xlsx');
        app(OfferWorkbookWriter::class)->write($draft, $target);

        $sheet = IOFactory::load($target)->getSheetByName('Табла');
        $summary = IOFactory::load($target)->getSheetByName('КСС');

        $this->assertSame('ГЕТ Б секция 1', $sheet->getCell('C1')->getValue());
        $this->assertSame('004671073', $sheet->getCell('B3')->getValue());
        $this->assertSame(1, $sheet->getCell('D3')->getValue());
        $this->assertSame('ГЕТ Б секция 2', $sheet->getCell('C21')->getValue());
        $this->assertNull($sheet->getCell('C39')->getValue());
        $this->assertSame('=Табла!C1', $summary->getCell('B2')->getValue());
        $this->assertSame('=D2*E2', $summary->getCell('F2')->getValue());
        $this->assertStringContainsString('SUM', (string) $sheet->getCell('F13')->getValue());
    }

    private function draft(): OfferDraft
    {
        return app(ScheduleOfferMapper::class)->map(
            app(AutocadScheduleParser::class)->parse($this->samplePdf())
        );
    }

    private function samplePdf(): string
    {
        $files = glob(dirname(base_path()).DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        $preferred = array_values(array_filter(
            $files,
            fn (string $path) => str_starts_with(basename($path), '03-')
        ));
        $this->assertNotEmpty($preferred !== [] ? $preferred : $files);

        return $preferred[0] ?? $files[0];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function quantities(OfferDraft $draft): array
    {
        $boards = [];
        foreach ($draft->boards as $board) {
            $boards[$board->name] = [];
            foreach ($board->lines as $line) {
                $boards[$board->name][$line->catalogNumber] = $line->quantity;
            }
        }

        return $boards;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function referenceQuantities(): array
    {
        $files = array_values(array_filter(
            glob(dirname(base_path()).DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR.'*.xlsx') ?: [],
            fn (string $path) => ! str_starts_with(basename($path), '~$')
                && ! str_starts_with(strtolower(basename($path)), 'oferta')
        ));
        $sheet = null;
        foreach ($files as $path) {
            $candidate = IOFactory::load($path)->getSheet(1);
            $found = false;
            for ($row = 1; $row <= min(40, $candidate->getHighestRow()); $row++) {
                if (str_contains((string) $candidate->getCell('C'.$row)->getValue(), 'ГЕТ Б')) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $sheet = $candidate;
                break;
            }
        }
        if ($sheet === null) {
            return [];
        }
        $boards = [];
        $current = null;
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if (trim((string) $sheet->getCell('B'.$row)->getValue()) === 'Табло:') {
                $current = trim((string) $sheet->getCell('C'.$row)->getValue());
                $boards[$current] = [];
                continue;
            }
            $code = trim((string) $sheet->getCell('B'.$row)->getValue());
            if ($current && preg_match('/^\d{6,}$/', $code)) {
                $qty = (int) $sheet->getCell('D'.$row)->getValue();
                $boards[$current][$code] = ($boards[$current][$code] ?? 0) + $qty;
            }
        }

        return $boards;
    }

    /**
     * Breakers from the manual offer, summed across boards.
     * The Excel uses ETIMAT 6 numbers; the check is the rating, because the mapper assigns ETIMAT P6.
     *
     * @return array<string, int>
     */
    private function greenTreeRatings(string $path): array
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

        return $ratings;
    }
}
