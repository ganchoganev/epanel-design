<?php

namespace App\Services\Offers;

use App\Models\EtiProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns schedule rows into ETI order lines. A code is used only when exactly
 * one catalog row matches the type, poles, current and curve. Load-break
 * switches (SV) are not inferred: the designer PDF has no column for them,
 * and the reference offer quantities do not follow the breaker counts.
 */
class ScheduleOfferMapper
{
    public const SWITCH_NOTE = 'Товарови прекъсвачи SV не се добавят. В PDF няма отделен тип за тях, а бройките в ръчната оферта не следват броя на автоматите.';

    /** @param  list<ScheduleCircuit>  $circuits */
    public function map(array $circuits): OfferDraft
    {
        $replacements = $this->replacements();
        /** @var list<array{id: int, board: string, x: float, y: float, device_type: string, rating: string, catalog_number: ?string, name: ?string, unit_price: ?float}> $placements */
        $placements = [];
        /** @var array<string, array<string, array{line: OfferLine, sort: string}>> $boards */
        $boards = [];
        /** @var array<string, array{board: string, rating: string, device_type: string, quantity: int, reason: string}> $unmatched */
        $unmatched = [];

        foreach ($circuits as $circuit) {
            $product = $this->match($circuit);
            if (! $product instanceof EtiProduct) {
                $key = $circuit->board.'|'.$circuit->deviceType.'|'.$circuit->rating;
                if (! isset($unmatched[$key])) {
                    $unmatched[$key] = [
                        'board' => $circuit->board,
                        'rating' => $circuit->rating,
                        'device_type' => $circuit->deviceType,
                        'quantity' => 0,
                        'reason' => 'Няма единствен код на ETI за този номинал.',
                    ];
                }
                $unmatched[$key]['quantity']++;
                $placements[] = $this->placement($circuit, count($placements), null, null, null);
                continue;
            }

            $code = $product->catalog_number;
            $name = $product->name;
            $price = $product->price !== null ? (float) $product->price : null;
            if (isset($replacements[$code])) {
                $code = $replacements[$code];
                $replacement = EtiProduct::query()->where('catalog_number', $code)->first();
                if ($replacement instanceof EtiProduct) {
                    if ($replacement->name !== $code) {
                        $name = $replacement->name;
                    }
                    if ($replacement->price !== null) {
                        $price = (float) $replacement->price;
                    }
                }
            }
            $placements[] = $this->placement($circuit, count($placements), $code, $name, $price);
            $boards[$circuit->board] ??= [];
            if (! isset($boards[$circuit->board][$code])) {
                $boards[$circuit->board][$code] = [
                    'line' => new OfferLine(
                        $code,
                        $name,
                        0,
                        $price,
                    ),
                    'sort' => sprintf(
                        '%d-%03d-%06d',
                        $circuit->deviceType === 'ПЛК' ? 0 : 1,
                        100 - $circuit->poles,
                        100000 - (int) $circuit->current,
                    ),
                ];
            }
            $existing = $boards[$circuit->board][$code]['line'];
            $boards[$circuit->board][$code]['line'] = new OfferLine(
                $existing->catalogNumber,
                $existing->name,
                $existing->quantity + 1,
                $existing->unitPrice,
            );
        }

        $offerBoards = [];
        foreach ($this->orderedBoardNames(array_keys($boards)) as $name) {
            $rows = $boards[$name];
            uasort($rows, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
            $offerBoards[] = new OfferBoard(
                $name,
                1,
                array_map(fn (array $row) => $row['line'], array_values($rows)),
            );
        }

        return new OfferDraft($offerBoards, array_values($unmatched), self::SWITCH_NOTE, $placements);
    }

    /** @return array{id: int, board: string, x: float, y: float, device_type: string, rating: string, catalog_number: ?string, name: ?string, unit_price: ?float} */
    private function placement(ScheduleCircuit $circuit, int $id, ?string $code, ?string $name, ?float $price): array
    {
        return [
            'id' => $id,
            'board' => $circuit->board,
            'x' => $circuit->pageX,
            'y' => $circuit->pageY,
            'device_type' => $circuit->deviceType,
            'rating' => $circuit->rating,
            'catalog_number' => $code,
            'name' => $name,
            'unit_price' => $price,
        ];
    }

    /** @return array<string, string> */
    private function replacements(): array
    {
        try {
            $rows = DB::connection('eticad')->table('code_replacements')->pluck('to_code', 'from_code');
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $from => $to) {
            $map[(string) $from] = (string) $to;
        }

        return $map;
    }

    private function match(ScheduleCircuit $circuit): ?EtiProduct
    {
        if ($circuit->deviceType === 'МАП') {
            if ($circuit->curve === null) {
                return null;
            }
            $query = EtiProduct::query()
                ->where('series', 'ETIMAT P6')
                ->where('category', 'MCB')
                ->where('poles', $circuit->poles)
                ->where('trip_curve', $circuit->curve)
                ->where('rated_current_a', $circuit->current);
        } elseif ($circuit->deviceType === 'ПЛК') {
            $query = EtiProduct::query()
                ->where('series', 'EB2')
                ->where('category', 'MCCB')
                ->where('poles', $circuit->poles)
                ->where('rated_current_a', $circuit->current);
        } else {
            return null;
        }

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function orderedBoardNames(array $names): array
    {
        usort($names, function (string $a, string $b): int {
            $sectionA = preg_match('/(\d+)\)?$/u', $a, $matchA) ? (int) $matchA[1] : 999;
            $sectionB = preg_match('/(\d+)\)?$/u', $b, $matchB) ? (int) $matchB[1] : 999;

            return $sectionA <=> $sectionB ?: strcmp($a, $b);
        });

        return $names;
    }
}
