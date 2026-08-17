<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CableScheduleSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private readonly array $cables)
    {
    }

    public function title(): string
    {
        return 'Изходящи кръгове';
    }

    public function headings(): array
    {
        return [
            '№', 'Наименование', 'Апарат', 'Кат. номер', 'In [A]', 'Жила',
            'Кабел', 'Сечение [mm²]', 'Дължина [m]', 'Товар [kW]', 'ΔU [%]',
        ];
    }

    public function array(): array
    {
        return array_map(fn (array $cable) => [
            $cable['number'],
            $cable['name'],
            $cable['device_label'],
            $cable['device_catalog'],
            $cable['rated_current_a'],
            $cable['conductors'],
            $cable['cable_type'],
            $cable['cross_section_mm2'],
            $cable['length_m'],
            $cable['load_kw'],
            $cable['voltage_drop_percent'],
        ], $this->cables);
    }
}
