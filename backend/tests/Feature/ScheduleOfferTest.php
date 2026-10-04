<?php

namespace Tests\Feature;

use App\Services\Offers\AutocadScheduleParser;
use App\Services\Offers\OfferDraft;
use App\Services\Offers\OfferWorkbookWriter;
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
        $parsed = $this->quantities($this->draft());
        $reference = $this->referenceQuantities();

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
        $this->assertNotEmpty($files);
        $sheet = IOFactory::load($files[0])->getSheet(1);
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
}
