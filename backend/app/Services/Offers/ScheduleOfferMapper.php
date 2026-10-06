<?php

namespace App\Services\Offers;

use App\Models\EtiProduct;
use App\Models\ScheduleMap;
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
            $saved = ScheduleMap::query()
                ->where('signature', ScheduleMap::signature($circuit->rating))
                ->first();
            $product = $saved instanceof ScheduleMap ? null : $this->match($circuit);
            if (! $saved instanceof ScheduleMap && ! $product instanceof EtiProduct) {
                $key = $circuit->board.'|'.$circuit->deviceType.'|'.$circuit->rating;
                if (! isset($unmatched[$key])) {
                $unmatched[$key] = [
                    'board' => $circuit->board,
                    'rating' => $circuit->rating,
                    'device_type' => $circuit->deviceType,
                    'quantity' => 0,
                    'reason' => $this->unmatchedReason($circuit),
                ];
                }
                $unmatched[$key]['quantity']++;
                $placements[] = $this->placement($circuit, count($placements), null, null, null);
                continue;
            }

            if ($saved instanceof ScheduleMap) {
                $code = $saved->catalog_number;
                $name = $saved->name;
                $price = $saved->unit_price !== null ? (float) $saved->unit_price : null;
                $catalog = EtiProduct::query()->where('catalog_number', $code)->first();
                if ($catalog instanceof EtiProduct) {
                    $name = $catalog->name;
                    if ($catalog->price !== null) {
                        $price = (float) $catalog->price;
                    }
                }
            } else {
                $code = $product->catalog_number;
                $name = $product->name;
                $price = $product->price !== null ? (float) $product->price : null;
            }
            if ($saved === null && isset($replacements[$code])) {
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

    /** @return array{id: int, board: string, x: float, y: float, file: string, device_type: string, rating: string, catalog_number: ?string, name: ?string, unit_price: ?float} */
    private function placement(ScheduleCircuit $circuit, int $id, ?string $code, ?string $name, ?float $price): array
    {
        return [
            'id' => $id,
            'board' => $circuit->board,
            'x' => $circuit->pageX,
            'y' => $circuit->pageY,
            'file' => $circuit->sourceFile,
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
        $matches = $this->candidates($circuit);
        if ($matches === null) {
            return null;
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        $added = $matches->where('data_source', 'supplement');
        if ($added->count() === 1) {
            return $added->first();
        }
        $preferred = $matches->filter(
            fn (EtiProduct $row) => $row->series === 'ETIMAT P6' || str_starts_with((string) $row->series, 'EB2')
        );
        if ($preferred->count() === 1) {
            return $preferred->first();
        }
        $standard = $preferred->filter(
            fn (EtiProduct $row) => (float) $row->breaking_capacity_ka === 6.0
        );
        if ($standard->count() === 1) {
            return $standard->first();
        }

        return null;
    }

    private function unmatchedReason(ScheduleCircuit $circuit): string
    {
        $label = $circuit->deviceType.' '.$circuit->rating;
        if ($circuit->deviceType === 'МАП' && $circuit->curve === null) {
            return 'Прочетено '.$label.', но няма крива. Без крива не се избира изделие ETIMAT P6.';
        }

        $matches = $this->candidates($circuit);
        if ($matches === null) {
            return 'Прочетено '.$label.', но този тип не се съпоставя към изделие на ETI.';
        }
        if ($circuit->deviceType === 'ДТЗ') {
            return 'Прочетено '.$label.'. В каталога eti_products няма единствен ред със същите полюси, ток, дефектен ток и тип.';
        }
        $series = $circuit->deviceType === 'ПЛК' ? 'EB2' : 'ETIMAT P6';
        if ($matches->isEmpty()) {
            return 'Прочетено '.$label.', но в каталога няма '.$series.' с тези полюси, ток и крива.';
        }

        return 'Прочетено '.$label.', но в каталога eti_products има повече от едно изделие с тези параметри. Новият код се добавя от шаблона в „Заменени кодове“.';
    }

    private function candidates(ScheduleCircuit $circuit): ?\Illuminate\Support\Collection
    {
        if ($circuit->deviceType === 'МАП') {
            if ($circuit->curve === null) {
                return null;
            }

            return EtiProduct::query()
                ->where('category', 'MCB')
                ->where('poles', $circuit->poles)
                ->where('trip_curve', $circuit->curve)
                ->where('rated_current_a', $circuit->current)
                ->get();
        }
        if ($circuit->deviceType === 'ПЛК') {
            return EtiProduct::query()
                ->where('category', 'MCCB')
                ->where('poles', $circuit->poles)
                ->where('rated_current_a', $circuit->current)
                ->get();
        }
        if ($circuit->deviceType === 'ДТЗ') {
            $parsed = $this->residualRating($circuit->rating);
            if ($parsed === null) {
                return collect();
            }
            $query = EtiProduct::query()
                ->whereIn('category', ['RCD', 'RCBO'])
                ->where('poles', $parsed['poles'])
                ->where('rated_current_a', $parsed['current'])
                ->where('residual_current_a', $parsed['residual']);
            if ($parsed['type'] !== null) {
                $query->where('rcd_type', $parsed['type']);
            }
            if ($parsed['curve'] !== null) {
                $query->where('trip_curve', $parsed['curve']);
            }

            return $query->get();
        }

        return null;
    }

    /**
     * @return array{poles: int, current: float, residual: float, type: ?string, curve: ?string}|null
     */
    private function residualRating(string $rating): ?array
    {
        $rating = str_replace(['А', 'С', 'В'], ['A', 'C', 'B'], trim($rating));
        if (preg_match('/^(\d+)A\/(\d+)P\/(\d+)mA(?:\s+([A-Z]+))?$/i', $rating, $match) === 1) {
            return [
                'poles' => (int) $match[2],
                'current' => (float) $match[1],
                'residual' => ((float) $match[3]) / 1000,
                'type' => isset($match[4]) && $match[4] !== '' ? strtoupper($match[4]) : null,
                'curve' => null,
            ];
        }
        if (preg_match('/^C(\d+)A\/(\d+)\+N$/i', $rating, $match) === 1) {
            return [
                'poles' => (int) $match[2] + 1,
                'current' => (float) $match[1],
                'residual' => 0.03,
                'type' => 'AC',
                'curve' => 'C',
            ];
        }

        return null;
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
