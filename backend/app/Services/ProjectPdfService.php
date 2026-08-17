<?php

namespace App\Services;

use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;

class ProjectPdfService
{
    public function __construct(private readonly BomService $bomService)
    {
    }

    public function generate(Project $project): \Barryvdh\DomPDF\PDF
    {
        $bom = $this->bomService->build($project);
        $design = $project->design_data ?? [];
        $panel = $project->panel_config ?? [];
        $wiring = $design['wiring'] ?? [];

        return Pdf::loadView('pdf.project-pack', [
            'project' => $project,
            'bom' => $bom,
            'design' => $design,
            'panel' => $panel,
            'busbars' => $wiring['busbars'] ?? [],
            'bars' => $wiring['bars'] ?? [],
            'technical' => $this->technicalSummary($design),
            'schematic' => $this->schematicImage($design),
            'generatedAt' => now()->format('d.m.Y H:i'),
        ])->setPaper('a4');
    }

    /**
     * The single-line diagram is rendered by the frontend and saved with the
     * design. dompdf draws SVG only from an image source, so it is handed over
     * as a data URI.
     */
    private function schematicImage(array $design): ?string
    {
        $svg = $design['schematicSvg'] ?? null;

        if (! is_string($svg) || ! str_contains($svg, '<svg')) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Figures a designer has to state on the drawing: enclosure data, total
     * heat load, module usage and the phase distribution of the circuits.
     *
     * @return array<string, string>
     */
    private function technicalSummary(array $design): array
    {
        $enclosure = $design['enclosure'] ?? [];
        $components = $design['components'] ?? [];
        $wiring = $design['wiring'] ?? [];

        $rows = (int) ($enclosure['rows'] ?? 0);
        $modulesPerRow = (int) ($enclosure['modulesPerRow'] ?? 0);
        $capacity = $rows * $modulesPerRow;

        $used = 0;
        $heat = 0.0;
        foreach ($components as $component) {
            $used += (int) ($component['widthModules'] ?? 1);
            $heat += (float) ($component['heatDissipationW'] ?? 0);
        }

        $phaseLoad = ['L1' => 0.0, 'L2' => 0.0, 'L3' => 0.0];
        $byUid = [];
        foreach ($components as $component) {
            if (isset($component['uid'])) {
                $byUid[$component['uid']] = $component;
            }
        }
        foreach ($wiring['circuits'] ?? [] as $circuit) {
            $device = $byUid[$circuit['protectiveDeviceUid'] ?? ''] ?? null;
            $current = (float) ($device['ratedCurrentA'] ?? 0);
            $phases = array_values(array_filter(
                $circuit['conductors'] ?? [],
                fn ($c) => in_array($c, ['L1', 'L2', 'L3'], true)
            ));
            if (! $phases) {
                continue;
            }
            $share = $current / count($phases);
            foreach ($phases as $phase) {
                $phaseLoad[$phase] += $share;
            }
        }

        $summary = [
            'Табло' => (string) ($enclosure['name'] ?? '—'),
            'Каталожен номер на таблото' => (string) ($enclosure['catalogNumber'] ?? '—'),
            'Степен на защита' => (string) ($enclosure['ipRating'] ?? 'IP40'),
            'Заземителна система' => (string) ($enclosure['supplySystem'] ?? 'TN-C-S'),
            'Захранване' => ($enclosure['phases'] ?? 3).'-фазно, '.(($enclosure['phases'] ?? 3) === 1 ? '230 V' : '400 V').', 50 Hz',
        ];

        if ($capacity > 0) {
            $summary['Модули'] = sprintf(
                '%d от %d заети (%d%% запълване, %d резервни)',
                $used,
                $capacity,
                (int) round($used / $capacity * 100),
                max(0, $capacity - $used)
            );
        }

        $summary['Отделяна мощност'] = number_format($heat, 1).' W';
        if (! empty($enclosure['thermalLimitW'])) {
            $summary['Отделяна мощност'] .= ' от допустими '.$enclosure['thermalLimitW'].' W при '
                .($enclosure['ambientTempC'] ?? 30).' °C';
        }

        if (array_sum($phaseLoad) > 0) {
            $summary['Натоварване по фази'] = sprintf(
                'L1 %d A · L2 %d A · L3 %d A',
                round($phaseLoad['L1']),
                round($phaseLoad['L2']),
                round($phaseLoad['L3'])
            );
        }

        $summary['Апарати / кръгове / проводници'] = sprintf(
            '%d / %d / %d',
            count($components),
            count($wiring['circuits'] ?? []),
            count($wiring['wires'] ?? [])
        );

        return $summary;
    }
}
