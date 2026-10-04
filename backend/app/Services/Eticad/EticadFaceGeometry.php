<?php

namespace App\Services\Eticad;

/**
 * Turns an ETICAD 2D block (DXF) into a front-view SVG.
 * The block also contains leaders and dimensions; the device body is the
 * cluster of the long edges, and only geometry on that body is drawn.
 */
class EticadFaceGeometry
{
    /**
     * @return array{
     *     svg: string,
     *     width_mm: float,
     *     height_mm: float,
     *     rails: list<array{x: float, y: float, width: float}>
     * }|null
     */
    public function fromDxf(string $dxf): ?array
    {
        $entities = $this->entities($dxf);
        if ($entities === []) {
            return null;
        }

        $box = $this->bodyBox($entities);
        if ($box === null) {
            return null;
        }

        $kept = array_values(array_filter(
            $entities,
            fn (array $entity) => $this->hits($entity, $box)
        ));
        if ($kept === []) {
            return null;
        }

        $width = $box['maxX'] - $box['minX'];
        $height = $box['maxY'] - $box['minY'];
        if ($width < 1 || $height < 1) {
            return null;
        }

        $parts = [];
        foreach ($kept as $entity) {
            $drawn = $this->draw($entity);
            if ($drawn !== '') {
                $parts[] = $drawn;
            }
        }
        if ($parts === []) {
            return null;
        }

        $tx = -$box['minX'];
        $ty = $box['maxY'];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '
            .$this->num($width).' '.$this->num($height).'">'
            .'<g transform="matrix(1 0 0 -1 '.$this->num($tx).' '.$this->num($ty).')" fill="none" stroke="#263238" stroke-width="1" vector-effect="non-scaling-stroke">'
            .implode('', $parts)
            .'</g></svg>';

        return [
            'svg' => $svg,
            'width_mm' => round($width, 2),
            'height_mm' => round($height, 2),
            'rails' => $this->rails($entities, $box),
        ];
    }

    /**
     * DIN rows drawn as a pair of horizontal lines one module-multiple wide
     * and about 45 mm apart. Coordinates are millimetres in the SVG viewBox,
     * with y at the centre of the row measured from the top.
     *
     * @param  list<array{type: string, points: list<array{0: float, 1: float}>}>  $entities
     * @param  array{minX: float, minY: float, maxX: float, maxY: float}  $box
     * @return list<array{x: float, y: float, width: float}>
     */
    private function rails(array $entities, array $box): array
    {
        $lines = [];
        foreach ($entities as $entity) {
            if ($entity['type'] !== 'LINE') {
                continue;
            }
            [$a, $b] = $entity['points'];
            if (abs($a[1] - $b[1]) > 0.8) {
                continue;
            }
            $length = abs($b[0] - $a[0]);
            $modules = (int) round($length / 18);
            if ($modules < 4 || $modules > 36 || abs($length - $modules * 18) > 0.6) {
                continue;
            }
            $lines[] = [
                'x' => min($a[0], $b[0]),
                'y' => ($a[1] + $b[1]) / 2,
                'length' => $length,
            ];
        }
        usort($lines, fn (array $p, array $q) => $p['y'] <=> $q['y']);

        $used = [];
        $rails = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($used[$j])) {
                    continue;
                }
                $gap = $lines[$j]['y'] - $lines[$i]['y'];
                if ($gap < 40) {
                    continue;
                }
                if ($gap > 55) {
                    break;
                }
                if (abs($lines[$i]['length'] - $lines[$j]['length']) > 1 || abs($lines[$i]['x'] - $lines[$j]['x']) > 2) {
                    continue;
                }
                $used[$i] = true;
                $used[$j] = true;
                $rails[] = [
                    'x' => round((($lines[$i]['x'] + $lines[$j]['x']) / 2) - $box['minX'], 2),
                    'y' => round($box['maxY'] - (($lines[$i]['y'] + $lines[$j]['y']) / 2), 2),
                    'width' => round(($lines[$i]['length'] + $lines[$j]['length']) / 2, 2),
                ];
                break;
            }
        }
        usort($rails, fn (array $p, array $q) => $p['y'] <=> $q['y']);

        return $rails;
    }

    /**
     * @return list<array{type: string, points: list<array{0: float, 1: float}>, closed?: bool, r?: float, a0?: float, a1?: float}>
     */
    private function entities(string $dxf): array
    {
        $lines = preg_split("/\r\n|\n/", $dxf) ?: [];
        $entities = [];
        $count = count($lines);
        for ($i = 0; $i < $count - 1; $i++) {
            if (trim($lines[$i]) !== '0') {
                continue;
            }
            $type = strtoupper(trim($lines[$i + 1]));
            if (! in_array($type, ['LINE', 'CIRCLE', 'ARC', 'LWPOLYLINE'], true)) {
                continue;
            }
            $data = [];
            $poly = [];
            $closed = false;
            for ($j = $i + 2; $j < $count - 1; $j += 2) {
                $code = trim($lines[$j]);
                if ($code === '0') {
                    break;
                }
                $value = trim($lines[$j + 1]);
                if ($type === 'LWPOLYLINE' && $code === '10') {
                    $poly[] = [(float) $value, 0.0];
                } elseif ($type === 'LWPOLYLINE' && $code === '20' && $poly !== []) {
                    $poly[count($poly) - 1][1] = (float) $value;
                } elseif ($type === 'LWPOLYLINE' && $code === '70') {
                    $closed = ((int) $value & 1) === 1;
                } else {
                    $data[$code] = (float) $value;
                }
            }
            if ($type === 'LINE' && isset($data['10'], $data['20'], $data['11'], $data['21'])) {
                $entities[] = ['type' => 'LINE', 'points' => [[$data['10'], $data['20']], [$data['11'], $data['21']]]];
            } elseif ($type === 'CIRCLE' && isset($data['10'], $data['20'], $data['40'])) {
                $entities[] = ['type' => 'CIRCLE', 'points' => [[$data['10'], $data['20']]], 'r' => $data['40']];
            } elseif ($type === 'ARC' && isset($data['10'], $data['20'], $data['40'], $data['50'], $data['51'])) {
                $entities[] = [
                    'type' => 'ARC',
                    'points' => [[$data['10'], $data['20']]],
                    'r' => $data['40'],
                    'a0' => $data['50'],
                    'a1' => $data['51'],
                ];
            } elseif ($type === 'LWPOLYLINE' && count($poly) >= 2) {
                $entities[] = ['type' => 'LWPOLYLINE', 'points' => $poly, 'closed' => $closed];
            }
        }

        return $entities;
    }

    /**
     * @param  list<array{type: string, points: list<array{0: float, 1: float}>}>  $entities
     * @return array{minX: float, minY: float, maxX: float, maxY: float}|null
     */
    private function bodyBox(array $entities): ?array
    {
        $long = [];
        foreach ($entities as $entity) {
            if ($entity['type'] !== 'LINE') {
                continue;
            }
            [$a, $b] = $entity['points'];
            if (hypot($b[0] - $a[0], $b[1] - $a[1]) >= 40) {
                $long[] = $entity;
            }
        }
        $source = count($long) >= 2 ? $long : $entities;
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;
        foreach ($source as $entity) {
            foreach ($entity['points'] as [$x, $y]) {
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
        if ($minX === INF) {
            return null;
        }

        return [
            'minX' => $minX - 1,
            'minY' => $minY - 1,
            'maxX' => $maxX + 1,
            'maxY' => $maxY + 1,
        ];
    }

    /**
     * @param  array{type: string, points: list<array{0: float, 1: float}>, r?: float}  $entity
     * @param  array{minX: float, minY: float, maxX: float, maxY: float}  $box
     */
    private function hits(array $entity, array $box): bool
    {
        if ($entity['type'] === 'CIRCLE' || $entity['type'] === 'ARC') {
            [$x, $y] = $entity['points'][0];
            $r = $entity['r'] ?? 0;

            return $x + $r >= $box['minX'] && $x - $r <= $box['maxX']
                && $y + $r >= $box['minY'] && $y - $r <= $box['maxY'];
        }
        foreach ($entity['points'] as [$x, $y]) {
            if ($x >= $box['minX'] && $x <= $box['maxX'] && $y >= $box['minY'] && $y <= $box['maxY']) {
                return true;
            }
        }

        return false;
    }

    /** @param  array{type: string, points: list<array{0: float, 1: float}>, closed?: bool, r?: float, a0?: float, a1?: float}  $entity */
    private function draw(array $entity): string
    {
        if ($entity['type'] === 'LINE') {
            [$a, $b] = $entity['points'];

            return '<line x1="'.$this->num($a[0]).'" y1="'.$this->num($a[1]).'" x2="'.$this->num($b[0]).'" y2="'.$this->num($b[1]).'"/>';
        }
        if ($entity['type'] === 'CIRCLE') {
            [$c] = $entity['points'];

            return '<circle cx="'.$this->num($c[0]).'" cy="'.$this->num($c[1]).'" r="'.$this->num($entity['r'] ?? 0).'"/>';
        }
        if ($entity['type'] === 'ARC') {
            return '<polyline points="'.$this->points($this->arcPoints($entity)).'"/>';
        }
        $points = $entity['points'];
        if (($entity['closed'] ?? false) && $points !== []) {
            $points[] = $points[0];
        }

        return '<polyline points="'.$this->points($points).'"/>';
    }

    /**
     * @param  array{points: list<array{0: float, 1: float}>, r?: float, a0?: float, a1?: float}  $entity
     * @return list<array{0: float, 1: float}>
     */
    private function arcPoints(array $entity): array
    {
        [$c] = $entity['points'];
        $radius = $entity['r'] ?? 0;
        $start = $entity['a0'] ?? 0;
        $end = $entity['a1'] ?? 0;
        $sweep = fmod($end - $start + 360, 360);
        if ($sweep < 0.1) {
            $sweep = 360;
        }
        $steps = max(2, (int) ceil($sweep / 12));
        $points = [];
        for ($i = 0; $i <= $steps; $i++) {
            $angle = deg2rad($start + $sweep * $i / $steps);
            $points[] = [$c[0] + $radius * cos($angle), $c[1] + $radius * sin($angle)];
        }

        return $points;
    }

    /** @param  list<array{0: float, 1: float}>  $points */
    private function points(array $points): string
    {
        return implode(' ', array_map(
            fn (array $point) => $this->num($point[0]).','.$this->num($point[1]),
            $points
        ));
    }

    private function num(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
