<?php

namespace App\Services;

use App\Models\EtiProduct;
use App\Models\Project;
use Illuminate\Support\Collection;

class BomService
{
    /**
     * @return array{items: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(Project $project): array
    {
        $design = $project->design_data ?? [];
        $counts = [];

        foreach ($design['components'] ?? [] as $component) {
            $catalogNumber = $component['catalogNumber'] ?? $component['catalog_number'] ?? null;
            if (! $catalogNumber) {
                continue;
            }
            $counts[$catalogNumber] = ($counts[$catalogNumber] ?? 0) + 1;
        }

        if (! empty($design['enclosure']['catalogNumber'])) {
            $enclosureNumber = $design['enclosure']['catalogNumber'];
            $counts[$enclosureNumber] = ($counts[$enclosureNumber] ?? 0) + 1;
        }

        // Wiring parts are ordered items too. Comb busbars and terminal bars are
        // sold in fixed 12-way lengths, so a longer run needs several pieces.
        $wiring = $design['wiring'] ?? [];

        foreach ($wiring['busbars'] ?? [] as $busbar) {
            $catalogNumber = $busbar['catalogNumber'] ?? null;
            if (! $catalogNumber) {
                continue;
            }
            $pieces = max(1, (int) ceil(((int) ($busbar['spanModules'] ?? 1)) / 12));
            $counts[$catalogNumber] = ($counts[$catalogNumber] ?? 0) + $pieces;
        }

        foreach ($wiring['bars'] ?? [] as $bar) {
            $catalogNumber = $bar['catalogNumber'] ?? null;
            if (! $catalogNumber) {
                continue;
            }
            $pieces = max(1, (int) ceil(((int) ($bar['usedWays'] ?? 1)) / 12));
            $counts[$catalogNumber] = ($counts[$catalogNumber] ?? 0) + $pieces;
        }

        foreach ($design['manualItems'] ?? [] as $manualItem) {
            $key = $manualItem['catalogNumber'] ?? $manualItem['name'] ?? uniqid('manual_');
            $counts[$key] = ($counts[$key] ?? 0) + (int) ($manualItem['quantity'] ?? 1);
        }

        $products = EtiProduct::whereIn('catalog_number', array_keys($counts))->get()->keyBy('catalog_number');
        $items = [];
        $subtotal = 0.0;

        foreach ($counts as $catalogNumber => $quantity) {
            /** @var EtiProduct|null $product */
            $product = $products->get($catalogNumber);
            $unitPrice = (float) ($product?->price ?? 0);
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;

            $items[] = [
                'catalog_number' => $catalogNumber,
                'name' => $product?->name ?? $catalogNumber,
                'series' => $product?->series,
                'quantity' => $quantity,
                'unit' => 'бр.',
                'unit_price' => $unitPrice,
                'line_total' => round($lineTotal, 2),
                'currency' => $product?->currency ?? 'EUR',
                'verified' => (bool) ($product?->verified ?? false),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['catalog_number'], $b['catalog_number']));

        return [
            'items' => $items,
            'totals' => [
                'subtotal' => round($subtotal, 2),
                'currency' => 'EUR',
                'item_count' => count($items),
                'component_count' => array_sum($counts),
            ],
            'legend' => $design['legend'] ?? [],
            'cables' => $this->cableSchedule($design),
            'wires' => $this->wireList($design),
        ];
    }

    /**
     * Cable schedule for the outgoing circuits: what leaves the panel, on which
     * protective device, with which cross section and estimated length.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cableSchedule(array $design): array
    {
        $labels = [];
        foreach ($design['components'] ?? [] as $component) {
            if (isset($component['uid'])) {
                $labels[$component['uid']] = $component;
            }
        }

        $rows = [];
        foreach ($design['wiring']['circuits'] ?? [] as $circuit) {
            $device = $labels[$circuit['protectiveDeviceUid'] ?? ''] ?? null;
            $rows[] = [
                'number' => $circuit['number'] ?? '',
                'name' => $circuit['name'] ?? '',
                'device_label' => $device['label'] ?? '',
                'device_catalog' => $device['catalogNumber'] ?? '',
                'rated_current_a' => $device['ratedCurrentA'] ?? null,
                'conductors' => implode('+', $circuit['conductors'] ?? []),
                'cable_type' => $circuit['cableType'] ?? '',
                'cross_section_mm2' => $circuit['cableCrossSectionMm2'] ?? null,
                'length_m' => $circuit['lengthM'] ?? null,
                'load_kw' => $circuit['loadKw'] ?? null,
                'voltage_drop_percent' => $circuit['voltageDropPercent'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Internal wire list used on the shop floor: every wire with its ferrule
     * number, conductor, cross section and the two terminals it connects.
     *
     * @return array<int, array<string, mixed>>
     */
    public function wireList(array $design): array
    {
        $labels = [];
        foreach ($design['components'] ?? [] as $component) {
            if (isset($component['uid'])) {
                $labels[$component['uid']] = $component['label'] ?? $component['uid'];
            }
        }
        $labels['PE-BAR'] = 'PE шина';

        $rows = [];
        foreach ($design['wiring']['wires'] ?? [] as $wire) {
            $from = $wire['from'] ?? [];
            $to = $wire['to'] ?? [];

            $rows[] = [
                'wire_number' => $wire['wireNumber'] ?? '',
                'conductor' => $wire['conductor'] ?? '',
                'cross_section_mm2' => $wire['crossSectionMm2'] ?? null,
                'length_mm' => $wire['lengthMm'] ?? null,
                'from' => sprintf(
                    '%s:%s%s',
                    $labels[$from['componentUid'] ?? ''] ?? ($from['componentUid'] ?? '?'),
                    ($from['side'] ?? '') === 'top' ? 'горе' : 'долу',
                    isset($from['pole']) ? '/'.$from['pole'] : ''
                ),
                'to' => sprintf(
                    '%s:%s%s',
                    $labels[$to['componentUid'] ?? ''] ?? ($to['componentUid'] ?? '?'),
                    ($to['side'] ?? '') === 'top' ? 'горе' : 'долу',
                    isset($to['pole']) ? '/'.$to['pole'] : ''
                ),
                'note' => $wire['note'] ?? '',
            ];
        }

        return $rows;
    }

    public function toCsv(array $bom): string
    {
        $lines = ["Кат. номер;Наименование;Серия;Количество;Ед.;Ед. цена;Общо;Валута"];

        foreach ($bom['items'] as $item) {
            $lines[] = implode(';', [
                $item['catalog_number'],
                str_replace(';', ',', $item['name']),
                $item['series'] ?? '',
                $item['quantity'],
                $item['unit'],
                number_format($item['unit_price'], 2, '.', ''),
                number_format($item['line_total'], 2, '.', ''),
                $item['currency'],
            ]);
        }

        $lines[] = '';
        $lines[] = 'Общо;;'.$bom['totals']['item_count'].' поз.;;;'.number_format($bom['totals']['subtotal'], 2, '.', '').';'.$bom['totals']['currency'];

        if (! empty($bom['cables'])) {
            $lines[] = '';
            $lines[] = 'ИЗХОДЯЩИ КРЪГОВЕ';
            $lines[] = '№;Наименование;Апарат;Кат. номер;In [A];Жила;Кабел;Сечение [mm2];Дължина [m];Товар [kW];dU [%]';
            foreach ($bom['cables'] as $cable) {
                $lines[] = implode(';', [
                    $cable['number'],
                    str_replace(';', ',', (string) $cable['name']),
                    $cable['device_label'],
                    $cable['device_catalog'],
                    $cable['rated_current_a'] ?? '',
                    $cable['conductors'],
                    $cable['cable_type'],
                    $cable['cross_section_mm2'] ?? '',
                    $cable['length_m'] ?? '',
                    $cable['load_kw'] ?? '',
                    $cable['voltage_drop_percent'] ?? '',
                ]);
            }
        }

        if (! empty($bom['wires'])) {
            $lines[] = '';
            $lines[] = 'КЛЕМЕН СПИСЪК';
            $lines[] = '№ жило;Проводник;Сечение [mm2];От;До;Дължина [mm];Забележка';
            foreach ($bom['wires'] as $wire) {
                $lines[] = implode(';', [
                    $wire['wire_number'],
                    $wire['conductor'],
                    $wire['cross_section_mm2'] ?? '',
                    $wire['from'],
                    $wire['to'],
                    $wire['length_mm'] ?? '',
                    str_replace(';', ',', (string) $wire['note']),
                ]);
            }
        }

        return implode("\n", $lines);
    }
}
