<?php

namespace App\Services;

use App\Models\EtiProduct;
use App\Support\CatalogCode;
use Illuminate\Support\Facades\DB;

/**
 * Copies the installed ETICAD library into the offer catalog.
 * A price already entered, and a row added from the code template, stay as they are.
 */
class EticadCatalogSync
{
    /**
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function sync(): array
    {
        $existing = EtiProduct::query()->get(['catalog_number', 'price', 'name', 'data_source'])->keyBy('catalog_number');
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $buffer = [];
        $now = now()->toDateTimeString();

        DB::connection('eticad')->table('products')->orderBy('id')->chunk(500, function ($rows) use (&$existing, &$imported, &$updated, &$skipped, &$buffer, $now): void {
            foreach ($rows as $row) {
                $code = CatalogCode::normalize((string) ($row->order_no ?? ''));
                if (! CatalogCode::looksLike($code)) {
                    $skipped++;
                    continue;
                }
                $current = $existing->get($code);
                if ($current instanceof EtiProduct && $current->data_source === 'supplement') {
                    $skipped++;
                    continue;
                }
                $parsed = $this->attributes((array) $row);
                $name = $parsed['name'] !== '' ? $parsed['name'] : $code;
                $source = 'eticad';
                $price = null;
                if ($current instanceof EtiProduct) {
                    $price = $current->price;
                    if ($current->data_source === 'price_import' && $current->name !== '' && $current->name !== $code) {
                        $name = $current->name;
                        $source = 'price_import';
                    }
                    $updated++;
                } else {
                    $imported++;
                }
                $modules = $parsed['width_modules'];
                $buffer[] = [
                    'catalog_number' => $code,
                    'eti_code' => $code,
                    'name' => $name,
                    'series' => $parsed['series'],
                    'category' => $parsed['category'],
                    'poles' => $parsed['poles'],
                    'rated_current_a' => $parsed['rated_current_a'],
                    'residual_current_a' => $parsed['residual_current_a'],
                    'rcd_type' => $parsed['rcd_type'],
                    'trip_curve' => $parsed['trip_curve'],
                    'breaking_capacity_ka' => $parsed['breaking_capacity_ka'],
                    'width_modules' => $modules,
                    'width_mm' => $modules * 18,
                    'mounting_type' => $parsed['mounting_type'],
                    'price' => $price,
                    'currency' => 'EUR',
                    'data_source' => $source,
                    'verified' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($buffer) >= 300) {
                    $this->write($buffer);
                    $buffer = [];
                }
            }
        });
        if ($buffer !== []) {
            $this->write($buffer);
        }

        return compact('imported', 'updated', 'skipped');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, series: ?string, category: ?string, poles: ?int, rated_current_a: ?float, residual_current_a: ?float, rcd_type: ?string, trip_curve: ?string, breaking_capacity_ka: ?float, width_modules: int, mounting_type: ?string}
     */
    public function attributes(array $row): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        $full = trim((string) ($row['full_name'] ?? ''));
        $article = trim((string) ($row['article'] ?? ''));
        $details = str_replace(',', '.', trim((string) ($row['article_1'] ?? '')));
        $extra = trim((string) ($row['article_2'] ?? ''));
        $category = $this->category($full);
        $current = $this->number($row['max_current'] ?? null);
        $poles = null;
        $curve = null;
        $residual = null;

        if (preg_match('/(\d+)\s*p(?:\s*\+\s*n)?/i', $details, $match) === 1) {
            $poles = (int) $match[1] + (preg_match('/\+\s*n/i', $details) === 1 ? 1 : 0);
        }
        if (preg_match('/(?:^|[^A-Z])([BCD])\s*(\d+(?:\.\d+)?)/i', $details, $match) === 1) {
            $curve = strtoupper($match[1]);
            $current ??= (float) $match[2];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/', $details, $match) === 1) {
            $right = (float) $match[2];
            if ($right > 0 && $right < 5) {
                $residual = $right;
                $current ??= (float) $match[1];
            }
        }
        if ($poles === null && in_array($category, ['MCB', 'RCD', 'RCBO', 'Switch'], true)) {
            $modules = $this->number($row['din_modules'] ?? null);
            if ($modules !== null && $modules >= 1 && $modules <= 4 && floor($modules) === $modules) {
                $poles = (int) $modules;
            }
        }

        $rcdType = null;
        if (preg_match('/^(AC|A|B|F)$/i', $extra, $match) === 1) {
            $rcdType = strtoupper($match[1]);
        } elseif (in_array($category, ['RCD', 'RCBO'], true) && preg_match('/\b(AC|A|B|F)\b/u', $name, $match) === 1) {
            $rcdType = strtoupper($match[1]);
        }

        $breaking = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*kA/i', $extra, $match) === 1) {
            $breaking = (float) $match[1];
        }

        $modules = $this->number($row['din_modules'] ?? null);
        $width = $modules !== null && $modules > 0 ? max(1, (int) round($modules)) : 1;

        return [
            'name' => $name,
            'series' => $article !== '' ? $article : null,
            'category' => $category,
            'poles' => $poles,
            'rated_current_a' => $current,
            'residual_current_a' => $residual,
            'rcd_type' => $rcdType,
            'trip_curve' => $curve,
            'breaking_capacity_ka' => $breaking,
            'width_modules' => $width,
            'mounting_type' => (string) ($row['modular'] ?? '') === '1' ? 'DIN' : null,
        ];
    }

    private function category(string $full): ?string
    {
        $full = trim($full);

        return match (true) {
            str_contains($full, 'Miniature circuit breaker') => 'MCB',
            $full === 'RCBO' => 'RCBO',
            $full === 'RCCB' => 'RCD',
            str_contains($full, 'Molded Case') => 'MCCB',
            str_contains($full, 'Mot.protec') => 'MOTOR',
            str_contains($full, 'Thermal overload') => 'THERMAL',
            str_contains($full, 'Fuse') => 'FUSE',
            str_contains($full, 'Surge') => 'SPD',
            str_contains(mb_strtolower($full), 'contactor') => 'Contactor',
            $full === 'Distribution box', $full === 'Enclosure' => 'Enclosure',
            str_contains(mb_strtolower($full), 'busbar') => 'Busbar',
            str_contains(mb_strtolower($full), 'switch') => 'Switch',
            default => null,
        };
    }

    private function number(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function write(array $rows): void
    {
        EtiProduct::query()->upsert($rows, ['catalog_number'], [
            'eti_code',
            'name',
            'series',
            'category',
            'poles',
            'rated_current_a',
            'residual_current_a',
            'rcd_type',
            'trip_curve',
            'breaking_capacity_ka',
            'width_modules',
            'width_mm',
            'mounting_type',
            'price',
            'currency',
            'data_source',
            'verified',
            'updated_at',
        ]);
    }
}
