<?php

namespace App\Services;

use App\Models\CatalogImportLog;
use App\Models\EtiProduct;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;

class EplanImportService
{
    public function importFromXml(string $xmlContent, string $sourceFile): CatalogImportLog
    {
        $log = CatalogImportLog::create([
            'source_file' => $sourceFile,
            'status' => 'processing',
        ]);

        try {
            $xml = new SimpleXMLElement($xmlContent);
            $parts = $this->extractParts($xml);

            $imported = 0;
            $updated = 0;
            $skipped = 0;

            DB::transaction(function () use ($parts, &$imported, &$updated, &$skipped): void {
                foreach ($parts as $part) {
                    if (empty($part['catalog_number'])) {
                        $skipped++;

                        continue;
                    }

                    $existing = EtiProduct::where('catalog_number', $part['catalog_number'])->first();
                    $payload = array_merge($part, [
                        'data_source' => 'eplan_xml',
                        'verified' => true,
                    ]);

                    if ($existing) {
                        $existing->update($payload);
                        $updated++;
                    } else {
                        EtiProduct::create($payload);
                        $imported++;
                    }
                }
            });

            $log->update([
                'status' => 'completed',
                'imported_count' => $imported,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'message' => sprintf('Imported %d, updated %d, skipped %d', $imported, $updated, $skipped),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        }

        return $log->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractParts(SimpleXMLElement $xml): array
    {
        $parts = [];

        $nodes = $xml->xpath('//part') ?: $xml->xpath('//Part') ?: $xml->xpath('//product') ?: $xml->xpath('//Product') ?: [];

        if (empty($nodes)) {
            foreach ($xml->children() as $child) {
                if (in_array(strtolower($child->getName()), ['parts', 'products', 'items'], true)) {
                    foreach ($child->children() as $item) {
                        $parsed = $this->parsePartNode($item);
                        if ($parsed) {
                            $parts[] = $parsed;
                        }
                    }
                } else {
                    $parsed = $this->parsePartNode($child);
                    if ($parsed) {
                        $parts[] = $parsed;
                    }
                }
            }
        } else {
            foreach ($nodes as $node) {
                $parsed = $this->parsePartNode($node);
                if ($parsed) {
                    $parts[] = $parsed;
                }
            }
        }

        return $parts;
    }

    private function parsePartNode(SimpleXMLElement $node): ?array
    {
        $attrs = $this->flattenAttributes($node);

        $catalogNumber = $this->firstValue($attrs, [
            'part_number', 'partnumber', 'catalog_number', 'ordernumber', 'order_number', 'article_number', 'articlenumber', 'id',
        ]);

        if (! $catalogNumber) {
            return null;
        }

        $name = $this->firstValue($attrs, ['name', 'description', 'part_name', 'designation']) ?? $catalogNumber;
        $series = $this->firstValue($attrs, ['series', 'product_series', 'manufacturer_series']);
        $category = $this->firstValue($attrs, ['category', 'product_group', 'type']);

        return [
            'catalog_number' => (string) $catalogNumber,
            'name' => (string) $name,
            'series' => $series ? (string) $series : $this->guessSeries((string) $name, (string) $catalogNumber),
            'category' => $category ? (string) $category : null,
            'poles' => $this->parseInt($this->firstValue($attrs, ['poles', 'pole_count', 'number_of_poles'])),
            'rated_current_a' => $this->parseDecimal($this->firstValue($attrs, ['rated_current', 'current', 'nominal_current', 'in_a'])),
            'trip_curve' => $this->firstValue($attrs, ['trip_curve', 'curve', 'characteristic']),
            'breaking_capacity_ka' => $this->parseDecimal($this->firstValue($attrs, ['breaking_capacity', 'icu', 'short_circuit_capacity'])),
            'width_modules' => $this->parseInt($this->firstValue($attrs, ['width_modules', 'module_width', 'modules'])) ?? 1,
            'width_mm' => $this->parseDecimal($this->firstValue($attrs, ['width_mm', 'width'])),
            'height_mm' => $this->parseDecimal($this->firstValue($attrs, ['height_mm', 'height'])),
            'depth_mm' => $this->parseDecimal($this->firstValue($attrs, ['depth_mm', 'depth'])),
            'heat_dissipation_w' => $this->parseDecimal($this->firstValue($attrs, ['heat_dissipation', 'power_loss', 'pd_w'])),
            'mounting_type' => $this->firstValue($attrs, ['mounting', 'mounting_type']),
            'raw_attributes' => $attrs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenAttributes(SimpleXMLElement $node): array
    {
        $result = [];

        foreach ($node->attributes() as $key => $value) {
            $result[strtolower((string) $key)] = (string) $value;
        }

        foreach ($node->children() as $child) {
            $key = strtolower((string) $child->getName());
            $value = trim((string) $child);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function firstValue(array $attrs, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! empty($attrs[$key])) {
                return (string) $attrs[$key];
            }
        }

        return null;
    }

    private function parseInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) preg_replace('/[^\d]/', '', $value);
    }

    private function parseDecimal(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '.', preg_replace('/[^\d.,-]/', '', $value));
    }

    private function guessSeries(string $name, string $catalogNumber): ?string
    {
        $haystack = strtoupper($name.' '.$catalogNumber);
        $series = ['ETIBOX', 'ETIMAT', 'ETIBREAK', 'ETITEC', 'ETICON', 'ETICONNECT', 'EFI', 'KZS', 'ETIPOWER'];

        foreach ($series as $candidate) {
            if (str_contains($haystack, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
