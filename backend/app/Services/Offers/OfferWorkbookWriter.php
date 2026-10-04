<?php

namespace App\Services\Offers;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class OfferWorkbookWriter
{
    public function templatePath(): string
    {
        return resource_path('offers/board-offer-template.xlsx');
    }

    public function write(OfferDraft $draft, string $targetPath): void
    {
        $template = $this->templatePath();
        if (! is_file($template)) {
            throw new RuntimeException('Липсва шаблонът на офертата.');
        }

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getSheetByName('Табла');
        if (! $sheet instanceof Worksheet) {
            throw new RuntimeException('Шаблонът няма лист Табла.');
        }

        $blocks = $this->blocks($sheet);
        if (count($draft->boards) > count($blocks)) {
            throw new RuntimeException('Таблата са повече от местата в шаблона.');
        }

        foreach ($blocks as $index => $block) {
            $board = $draft->boards[$index] ?? null;
            if ($board === null) {
                $sheet->setCellValue('C'.$block['title'], null);
                $sheet->setCellValue('F'.$block['title'], 0);
                foreach ($block['slots'] as $row) {
                    $this->clearSlot($sheet, $row);
                }
                continue;
            }

            if (count($board->lines) > count($block['slots'])) {
                throw new RuntimeException('Табло „'.$board->name.'“ има повече редове, отколкото шаблонът позволява.');
            }

            $sheet->setCellValue('C'.$block['title'], $board->name);
            $sheet->setCellValue('F'.$block['title'], $board->quantity);
            foreach ($block['slots'] as $slotIndex => $row) {
                $line = $board->lines[$slotIndex] ?? null;
                if ($line === null) {
                    $this->clearSlot($sheet, $row);
                    continue;
                }
                $sheet->setCellValue('B'.$row, $line->catalogNumber);
                $sheet->setCellValue('C'.$row, $line->name);
                $sheet->setCellValue('D'.$row, $line->quantity);
                $sheet->setCellValue('E'.$row, $line->unitPrice);
            }
        }

        $directory = dirname($targetPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($targetPath);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @return list<array{title: int, slots: list<int>}>
     */
    private function blocks(Worksheet $sheet): array
    {
        $blocks = [];
        $highest = $sheet->getHighestRow();
        for ($row = 1; $row <= $highest; $row++) {
            if (trim((string) $sheet->getCell('B'.$row)->getValue()) !== 'Табло:') {
                continue;
            }
            $slots = [];
            for ($cursor = $row + 2; $cursor <= $highest; $cursor++) {
                $label = (string) $sheet->getCell('E'.$cursor)->getValue();
                if (str_contains($label, 'ел. компоненти')) {
                    break;
                }
                if (trim((string) $sheet->getCell('B'.$cursor)->getValue()) === 'Табло:') {
                    break;
                }
                $slots[] = $cursor;
            }
            $blocks[] = ['title' => $row, 'slots' => $slots];
        }

        return $blocks;
    }

    private function clearSlot(Worksheet $sheet, int $row): void
    {
        foreach (['B', 'C', 'D', 'E'] as $column) {
            $sheet->setCellValue($column.$row, null);
        }
    }
}
