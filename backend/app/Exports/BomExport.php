<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Workbook with one sheet per document: the bill of materials, the outgoing
 * circuit schedule, the internal wire list and the circuit legend.
 */
class BomExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly array $bom)
    {
    }

    public function sheets(): array
    {
        $sheets = [new BomItemsSheet($this->bom)];

        if (! empty($this->bom['cables'])) {
            $sheets[] = new CableScheduleSheet($this->bom['cables']);
        }
        if (! empty($this->bom['wires'])) {
            $sheets[] = new WireListSheet($this->bom['wires']);
        }
        if (! empty($this->bom['legend'])) {
            $sheets[] = new LegendSheet($this->bom['legend']);
        }

        return $sheets;
    }
}
