<?php

namespace App\Services\Eticad;

/**
 * Real ETICAD distribution boards (ECT), each with its own 2D block.
 * The seeded ETIBOX numbers in the app catalog are not these codes.
 */
class EticadEnclosureCatalog
{
    /**
     * @return list<array{
     *     catalog_number: string,
     *     name: string,
     *     rows: int,
     *     modules_per_row: int,
     *     mounting: string
     * }>
     */
    public function all(): array
    {
        $rows = [
            ['001101001', 'ECT 12 PT', 1, 12, 'вграден'],
            ['001101069', 'ECT 24 PT', 2, 12, 'вграден'],
            ['001101070', 'ECT 36 PT', 3, 12, 'вграден'],
            ['001101022', 'ECT 48 PT', 4, 12, 'вграден'],
            ['001101068', 'ECT 18 PT', 1, 18, 'вграден'],
            ['001101085', 'ECT 2×18 PT', 2, 18, 'вграден'],
            ['001101042', 'ECT 3×18 PT', 3, 18, 'вграден'],
            ['001100320', 'ECT 4×18 PT', 4, 18, 'вграден'],
            ['001100282', 'ECT 1×24 PT', 1, 24, 'вграден'],
            ['001101006', 'ECT 12 PO', 1, 12, 'открит'],
            ['001101072', 'ECT 24 PO', 2, 12, 'открит'],
            ['001101073', 'ECT 36 PO', 3, 12, 'открит'],
            ['001101023', 'ECT 48 PO', 4, 12, 'открит'],
            ['001101071', 'ECT 18 PO', 1, 18, 'открит'],
            ['001101086', 'ECT 2×18 PO', 2, 18, 'открит'],
            ['001101043', 'ECT 3×18 PO', 3, 18, 'открит'],
            ['001100321', 'ECT 4×18 PO', 4, 18, 'открит'],
            ['001100283', 'ECT 1×24 PO', 1, 24, 'открит'],
        ];

        return array_map(fn (array $row) => [
            'catalog_number' => $row[0],
            'name' => $row[1].' ('.$row[2].'×'.$row[3].')',
            'rows' => $row[2],
            'modules_per_row' => $row[3],
            'mounting' => $row[4],
        ], $rows);
    }
}
