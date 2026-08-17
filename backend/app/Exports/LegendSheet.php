<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LegendSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private readonly array $legend)
    {
    }

    public function title(): string
    {
        return 'Легенда';
    }

    public function headings(): array
    {
        return ['Означение', 'Описание'];
    }

    public function array(): array
    {
        return array_map(fn (array $entry) => [
            $entry['label'] ?? '',
            $entry['description'] ?? '',
        ], $this->legend);
    }
}
