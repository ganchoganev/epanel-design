<?php

namespace App\Services\Offers;

/**
 * Reads an AutoCAD plot of a board schedule. Text is stored as glyph indexes
 * with a ToUnicode map, and a circuit row is a band of Y positions around the
 * breaker-type label (МАП / ПЛК).
 */
class AutocadScheduleParser
{
    /** @return list<ScheduleCircuit> */
    public function parse(string $path): array
    {
        $raw = file_get_contents($path);
        $page = $this->pageBox(is_string($raw) ? $raw : '');
        $items = $this->extractText($path);
        $devices = array_values(array_filter(
            $items,
            fn (array $item) => $item['t'] === 'МАП' || $item['t'] === 'ПЛК'
        ));

        if ($devices === []) {
            return $this->inlineCircuits($items, $page);
        }

        $tables = $this->tables($devices);
        $circuits = [];

        foreach ($tables as $tableDevices) {
            $board = $this->boardName($items, $tableDevices);
            foreach ($tableDevices as $device) {
                $rating = $this->ratingBeside($items, $tableDevices, $device);
                if ($rating === null) {
                    continue;
                }
                [$pageX, $pageY] = $this->viewportFractions($device['px'], $device['py'], $page);
                $circuits[] = new ScheduleCircuit(
                    $board,
                    $device['t'],
                    $rating['poles'],
                    $rating['current'],
                    $rating['curve'],
                    $rating['label'],
                    $pageX,
                    $pageY,
                );
            }
        }

        return $circuits;
    }

    /**
     * A single-line diagram has no МАП/ПЛК labels. Each breaker is the rating
     * itself: C/63A/1, 32A/1P, or 160A/3P/36kA.
     *
     * @param  list<array{x: float, y: float, px: float, py: float, t: string}>  $items
     * @param  array{x0: float, y0: float, x1: float, y1: float, rotate: int}  $page
     * @return list<ScheduleCircuit>
     */
    private function inlineCircuits(array $items, array $page): array
    {
        $devices = [];
        foreach ($items as $item) {
            $rating = $this->inlineRating($item['t']);
            if ($rating === null) {
                continue;
            }
            $devices[] = $item + $rating;
        }
        if ($devices === []) {
            return [];
        }

        $board = $this->inlineBoardName($items);
        $circuits = [];
        foreach ($devices as $device) {
            [$pageX, $pageY] = $this->viewportFractions($device['px'], $device['py'], $page);
            $circuits[] = new ScheduleCircuit(
                $board,
                $device['type'],
                $device['poles'],
                $device['current'],
                $device['curve'],
                $device['label'],
                $pageX,
                $pageY,
            );
        }

        return $circuits;
    }

    /** @return array{type: string, poles: int, current: float, curve: ?string, label: string}|null */
    private function inlineRating(string $text): ?array
    {
        $text = str_replace(['А', 'С', 'В'], ['A', 'C', 'B'], trim($text));
        if (preg_match('/^([A-Z])\/(\d+(?:[.,]\d+)?)A\/(\d+)$/u', $text, $match) === 1) {
            $current = (float) str_replace(',', '.', $match[2]);
            $poles = (int) $match[3];

            return [
                'type' => 'МАП',
                'poles' => $poles,
                'current' => $current,
                'curve' => $match[1],
                'label' => $current.'A/'.$poles.'P/'.$match[1],
            ];
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)A\/(\d+)P\/(\d+(?:[.,]\d+)?)kA$/ui', $text, $match) === 1) {
            $current = (float) str_replace(',', '.', $match[1]);
            $poles = (int) $match[2];

            return [
                'type' => 'ПЛК',
                'poles' => $poles,
                'current' => $current,
                'curve' => null,
                'label' => $current.'A/'.$poles.'P',
            ];
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)A\/(\d+)P(?:\/([A-Z]))?$/u', $text, $match) === 1) {
            $current = (float) str_replace(',', '.', $match[1]);
            $poles = (int) $match[2];
            $curve = $match[3] ?? null;

            return [
                'type' => 'МАП',
                'poles' => $poles,
                'current' => $current,
                'curve' => $curve !== null && $curve !== '' ? $curve : null,
                'label' => $current.'A/'.$poles.'P'.($curve ? '/'.$curve : ''),
            ];
        }

        return null;
    }

    /** @param  list<array{t: string}>  $items */
    private function inlineBoardName(array $items): string
    {
        foreach ($items as $item) {
            if (preg_match('/(?:^|[\s\-])ГЕТ(?:$|[\s\-])/u', $item['t']) === 1) {
                return 'ГЕТ';
            }
        }

        return 'Табло';
    }

    /**
     * @return list<array{x: float, y: float, t: string}>
     */
    private function extractText(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);
        $cmaps = [];
        foreach ($streams[1] as $stream) {
            $plain = str_contains($stream, 'beginbfchar') ? $stream : $this->inflate($stream);
            if ($plain === false || ! str_contains($plain, 'beginbfchar')) {
                continue;
            }
            $map = [];
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $plain, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $pair) {
                    $chars = '';
                    $dst = $pair[2];
                    for ($i = 0; $i + 4 <= strlen($dst); $i += 4) {
                        $chars .= mb_chr(hexdec(substr($dst, $i, 4)), 'UTF-8');
                    }
                    $map[strtoupper($pair[1])] = $chars;
                }
            }
            $cmaps[] = $map;
        }

        $items = [];
        $font = 0;
        $x = 0.0;
        $y = 0.0;
        $ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $stack = [];
        foreach ($this->pageStreams($raw) as $decoded) {
            if (! preg_match_all(
                '/\/F(\d+)\s+[0-9.]+\s+Tf|([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+Tm|<([0-9A-Fa-f]+)>\s*Tj|\(((?:\\\\.|[^\\\\)])*)\)\s*Tj|([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+cm|(?:^|\s)(q|Q)(?=\s)/',
                $decoded,
                $ops,
                PREG_SET_ORDER
            )) {
                continue;
            }
            foreach ($ops as $op) {
                if (($op[16] ?? '') === 'q') {
                    $stack[] = $ctm;
                    continue;
                }
                if (($op[16] ?? '') === 'Q') {
                    $ctm = array_pop($stack) ?? $ctm;
                    continue;
                }
                if (($op[10] ?? '') !== '') {
                    $ctm = $this->concatMatrix([
                        (float) $op[10], (float) $op[11], (float) $op[12],
                        (float) $op[13], (float) $op[14], (float) $op[15],
                    ], $ctm);
                    continue;
                }
                if ($op[1] !== '') {
                    $font = (int) $op[1];
                    continue;
                }
                if ($op[2] !== '') {
                    $x = (float) $op[6];
                    $y = (float) $op[7];
                    continue;
                }
                $literal = $op[9] ?? '';
                if ($op[8] === '' && $literal === '') {
                    continue;
                }
                $text = $literal !== ''
                    ? trim($this->unescapePdf($literal))
                    : $this->decodeHex(strtoupper($op[8]), $cmaps[max(0, $font - 1)] ?? []);
                if ($text !== '') {
                    [$px, $py] = $this->applyMatrix($x, $y, $ctm);
                    $items[] = ['x' => $x, 'y' => $y, 'px' => $px, 'py' => $py, 't' => $text];
                }
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function pageStreams(string $raw): array
    {
        $streams = [];
        if (preg_match('/\/Contents\s*\[(.*?)\]/s', $raw, $list)) {
            preg_match_all('/(\d+)\s+0\s+R/', $list[1], $ids);
            foreach ($ids[1] as $id) {
                if (! preg_match('/(?<![0-9])'.$id.'\s+0\s+obj(.*?)endobj/s', $raw, $obj)) {
                    continue;
                }
                if (! preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $obj[1], $stream)) {
                    continue;
                }
                $plain = $this->inflate($stream[1]);
                $streams[] = $plain === false ? $stream[1] : $plain;
            }
        }

        return $streams;
    }

    /** @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $matrix
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $ctm
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private function concatMatrix(array $matrix, array $ctm): array
    {
        return [
            $matrix[0] * $ctm[0] + $matrix[1] * $ctm[2],
            $matrix[0] * $ctm[1] + $matrix[1] * $ctm[3],
            $matrix[2] * $ctm[0] + $matrix[3] * $ctm[2],
            $matrix[2] * $ctm[1] + $matrix[3] * $ctm[3],
            $matrix[4] * $ctm[0] + $matrix[5] * $ctm[2] + $ctm[4],
            $matrix[4] * $ctm[1] + $matrix[5] * $ctm[3] + $ctm[5],
        ];
    }

    /** @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $ctm
     * @return array{0: float, 1: float}
     */
    private function applyMatrix(float $x, float $y, array $ctm): array
    {
        return [
            $x * $ctm[0] + $y * $ctm[2] + $ctm[4],
            $x * $ctm[1] + $y * $ctm[3] + $ctm[5],
        ];
    }

    /**
     * @param  array<string, string>  $map
     */
    private function decodeHex(string $hex, array $map): string
    {
        $text = '';
        for ($i = 0; $i + 4 <= strlen($hex); $i += 4) {
            $text .= $map[substr($hex, $i, 4)] ?? '';
        }

        return trim($text);
    }

    private function unescapePdf(string $value): string
    {
        $text = preg_replace_callback('/\\\\([nrtbf()\/\\\\]|[0-7]{1,3})/', function (array $match): string {
            return match ($match[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\x08",
                'f' => "\f",
                '(', ')', '\\', '/' => $match[1] === '/' ? '/' : $match[1],
                default => chr(octdec($match[1])),
            };
        }, $value);

        return is_string($text) ? $text : $value;
    }

    /** @return array{x0: float, y0: float, x1: float, y1: float, rotate: int} */
    private function pageBox(string $raw): array
    {
        $box = ['x0' => 0.0, 'y0' => 0.0, 'x1' => 1.0, 'y1' => 1.0, 'rotate' => 0];
        if (preg_match('/MediaBox\s*\[\s*([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s*\]/', $raw, $match)) {
            $box['x0'] = (float) $match[1];
            $box['y0'] = (float) $match[2];
            $box['x1'] = (float) $match[3];
            $box['y1'] = (float) $match[4];
        }
        if (preg_match('/\/Rotate\s+(\d+)/', $raw, $match)) {
            $box['rotate'] = ((int) $match[1]) % 360;
        }

        return $box;
    }

    /**
     * Same mapping pdf.js uses for a rotated page: user space to the canvas,
     * origin at the top left.
     *
     * @param  array{x0: float, y0: float, x1: float, y1: float, rotate: int}  $page
     * @return array{0: float, 1: float}
     */
    private function viewportFractions(float $x, float $y, array $page): array
    {
        $rotation = $page['rotate'];
        if ($rotation < 0) {
            $rotation += 360;
        }
        $centerX = ($page['x1'] + $page['x0']) / 2;
        $centerY = ($page['y1'] + $page['y0']) / 2;
        [$a, $b, $c, $d] = match ($rotation) {
            90 => [0.0, 1.0, 1.0, 0.0],
            180 => [-1.0, 0.0, 0.0, 1.0],
            270 => [0.0, -1.0, -1.0, 0.0],
            default => [1.0, 0.0, 0.0, -1.0],
        };
        if ($a === 0.0) {
            $offsetX = abs($centerY - $page['y0']);
            $offsetY = abs($centerX - $page['x0']);
            $width = $page['y1'] - $page['y0'];
            $height = $page['x1'] - $page['x0'];
        } else {
            $offsetX = abs($centerX - $page['x0']);
            $offsetY = abs($centerY - $page['y0']);
            $width = $page['x1'] - $page['x0'];
            $height = $page['y1'] - $page['y0'];
        }
        $e = $offsetX - $a * $centerX - $c * $centerY;
        $f = $offsetY - $b * $centerX - $d * $centerY;

        return [
            $this->fraction($a * $x + $c * $y + $e, $width),
            $this->fraction($b * $x + $d * $y + $f, $height),
        ];
    }

    private function fraction(float $value, float $size): float
    {
        if ($size <= 0) {
            return 0;
        }

        return max(0, min(1, $value / $size));
    }

    private function inflate(string $data): string|false
    {
        $decoded = @gzuncompress($data);
        if ($decoded !== false) {
            return $decoded;
        }
        $decoded = @gzinflate($data);
        if ($decoded !== false) {
            return $decoded;
        }
        if (strlen($data) > 2) {
            $decoded = @gzinflate(substr($data, 2));
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return false;
    }

    /**
     * @param  list<array{x: float, y: float, t: string}>  $devices
     * @return list<list<array{x: float, y: float, t: string}>>
     */
    private function tables(array $devices): array
    {
        usort($devices, fn (array $a, array $b) => $a['x'] <=> $b['x']);
        $tables = [];
        $current = [];
        $anchor = null;
        foreach ($devices as $device) {
            if ($anchor !== null && $device['x'] - $anchor > 1500) {
                $tables[] = $current;
                $current = [];
            }
            $current[] = $device;
            $anchor = $device['x'];
        }
        if ($current !== []) {
            $tables[] = $current;
        }

        return $tables;
    }

    /**
     * @param  list<array{x: float, y: float, t: string}>  $items
     * @param  list<array{x: float, y: float, t: string}>  $devices
     */
    private function boardName(array $items, array $devices): string
    {
        $xs = array_column($devices, 'x');
        $ys = array_column($devices, 'y');
        $deviceX = array_sum($xs) / count($xs);
        $minY = min($ys) - 800;
        $maxY = max($ys) + 800;

        $fragments = [];
        foreach ($items as $item) {
            if ($item['x'] < $deviceX + 900 || $item['x'] > $deviceX + 1400) {
                continue;
            }
            if ($item['y'] < $minY || $item['y'] > $maxY) {
                continue;
            }
            if (! preg_match('/ГЕТ|секция|^\d+\)$/u', $item['t'])) {
                continue;
            }
            $fragments[] = $item;
        }

        usort($fragments, fn (array $a, array $b) => $b['y'] <=> $a['y']);
        $name = trim(implode(' ', array_map(fn (array $item) => $item['t'], $fragments)));
        $name = preg_replace('/(\d+)\)/u', '$1', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name !== '' ? $name : 'Табло';
    }

    /**
     * @param  list<array{x: float, y: float, t: string}>  $items
     * @param  list<array{x: float, y: float, t: string}>  $devices
     * @param  array{x: float, y: float, t: string}  $device
     * @return array{poles: int, current: float, curve: ?string, label: string}|null
     */
    private function ratingBeside(array $items, array $devices, array $device): ?array
    {
        $pieces = [];
        foreach ($items as $item) {
            if (abs($item['x'] - ($device['x'] - 114)) > 90) {
                continue;
            }
            if ($this->nearestDevice($devices, $item) !== $device) {
                continue;
            }
            $pieces[] = $item;
        }
        usort($pieces, fn (array $a, array $b) => $a['x'] <=> $b['x']);
        $joined = implode('', array_map(fn (array $item) => $item['t'], $pieces));
        $joined = str_replace(['А', 'С', 'В'], ['A', 'C', 'B'], $joined);

        if (! preg_match('/(\d+(?:[.,]\d+)?)A\/(\d+)P(?:\/([A-Z]))?/u', $joined, $match)) {
            return null;
        }

        $curve = $match[3] ?? null;

        return [
            'current' => (float) str_replace(',', '.', $match[1]),
            'poles' => (int) $match[2],
            'curve' => $curve !== null && $curve !== '' ? $curve : null,
            'label' => $match[1].'A/'.$match[2].'P'.($curve ? '/'.$curve : ''),
        ];
    }

    /**
     * @param  list<array{x: float, y: float, t: string}>  $devices
     * @param  array{x: float, y: float, t: string}  $item
     * @return array{x: float, y: float, t: string}|null
     */
    private function nearestDevice(array $devices, array $item): ?array
    {
        $best = null;
        $bestDist = INF;
        foreach ($devices as $device) {
            $dist = abs($device['y'] - $item['y']);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $device;
            }
        }

        return $best;
    }
}
