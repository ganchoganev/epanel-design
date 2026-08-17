<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class WireListSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private readonly array $wires)
    {
    }

    public function title(): string
    {
        return 'Клемен списък';
    }

    public function headings(): array
    {
        return ['№ жило', 'Проводник', 'Сечение [mm²]', 'От', 'До', 'Дължина [mm]', 'Забележка'];
    }

    public function array(): array
    {
        return array_map(fn (array $wire) => [
            $wire['wire_number'],
            $wire['conductor'],
            $wire['cross_section_mm2'],
            $wire['from'],
            $wire['to'],
            $wire['length_mm'],
            $wire['note'],
        ], $this->wires);
    }
}
