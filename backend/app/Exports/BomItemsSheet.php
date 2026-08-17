<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class BomItemsSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private readonly array $bom)
    {
    }

    public function title(): string
    {
        return 'Количествена сметка';
    }

    public function headings(): array
    {
        return ['Кат. номер', 'Наименование', 'Серия', 'Количество', 'Ед.', 'Ед. цена', 'Общо', 'Валута', 'Данни'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->bom['items'] as $item) {
            $rows[] = [
                $item['catalog_number'],
                $item['name'],
                $item['series'] ?? '',
                $item['quantity'],
                $item['unit'],
                $item['unit_price'],
                $item['line_total'],
                $item['currency'],
                $item['verified'] ? 'потвърдено' : 'непотвърдено',
            ];
        }

        $rows[] = [];
        $rows[] = [
            'Общо',
            '',
            '',
            $this->bom['totals']['item_count'].' поз.',
            '',
            '',
            $this->bom['totals']['subtotal'],
            $this->bom['totals']['currency'],
            '',
        ];

        return $rows;
    }
}
