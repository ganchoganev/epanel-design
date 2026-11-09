<?php

namespace App\Services\Offers;

/**
 * The legend explains the symbols. The count comes from the circuit table:
 * one mark (F1, Q1, QA) and the rating written next to it.
 */
class ScheduleTableReader
{
    public function __construct(private readonly AutocadScheduleParser $parser) {}

    /**
     * @return list<array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x: float, y: float}>
     */
    public function rows(string $path): array
    {
        $items = $this->parser->textItems($path);
        if ($items === []) {
            return [];
        }

        $columns = $this->breakerColumns($items, $path);
        if ($columns !== []) {
            return $this->numbered($columns);
        }

        $legendX = null;
        foreach ($items as $item) {
            if (preg_match('/^ЛЕГЕН/u', $item['t']) === 1) {
                $legendX = $item['x'];
                break;
            }
        }

        $marks = [];
        $ratings = [];
        foreach ($items as $item) {
            if ($legendX !== null && $item['x'] > $legendX - 200) {
                continue;
            }
            if (preg_match('/^(?:F\d+|FA|QA|Q\d+)$/', $item['t']) === 1) {
                $marks[] = $item;
                continue;
            }
            $parsed = $this->rating($item['t']);
            if ($parsed !== null) {
                $ratings[] = $item + $parsed;
            }
        }

        $used = [];
        $rows = [];
        foreach ($marks as $mark) {
            $best = null;
            $bestScore = null;
            foreach ($ratings as $index => $rating) {
                if (isset($used[$index])) {
                    continue;
                }
                $dx = abs($rating['x'] - $mark['x']);
                $dy = abs($rating['y'] - $mark['y']);
                if ($dx > 900 || $dy > 220) {
                    continue;
                }
                $score = $dy * 1000 + $dx;
                if ($bestScore === null || $score < $bestScore) {
                    $best = $index;
                    $bestScore = $score;
                }
            }
            if ($best === null) {
                continue;
            }
            $used[$best] = true;
            $rows[] = $this->row($ratings[$best], $this->board($items, $mark), $path);
        }

        foreach ($ratings as $index => $rating) {
            if (isset($used[$index])) {
                continue;
            }
            $stamp = false;
            foreach ($used as $usedIndex => $_) {
                if (abs($ratings[$usedIndex]['x'] - $rating['x']) <= 420) {
                    $stamp = true;
                    break;
                }
            }
            if ($stamp || ! in_array($rating['role'], ['breaker', 'mccb'], true)) {
                continue;
            }
            $rows[] = $this->row($rating, $this->board($items, $rating), $path);
        }

        $numbered = $this->numbered($rows);
        if ($numbered !== []) {
            return $numbered;
        }

        return $this->numbered($this->symbolStamps($items, $path));
    }

    /**
     * A board schedule has a column „Номинален ток на прекъсвач“ and „Тип прекъсвач“.
     * One sheet can hold two such tables. A rating may be split across glyphs, as 50 + А + /1P/C.
     *
     * @param  list<array{x: float, y: float, px?: float, py?: float, t: string}>  $items
     * @return list<array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x: float, y: float}>
     */
    private function breakerColumns(array $items, string $path): array
    {
        $ratingHeaders = [];
        $typeHeaders = [];
        foreach ($items as $item) {
            if ($item['t'] === 'Номинален ток на прекъсвач') {
                $ratingHeaders[] = $item;
            } elseif ($item['t'] === 'Тип прекъсвач') {
                $typeHeaders[] = $item;
            }
        }
        if ($ratingHeaders === []) {
            return [];
        }

        $rows = [];
        foreach ($ratingHeaders as $header) {
            $typeHeader = null;
            foreach ($typeHeaders as $candidate) {
                $dx = $candidate['x'] - $header['x'];
                if (abs($candidate['y'] - $header['y']) < 120 && $dx > 0 && $dx < 400) {
                    $typeHeader = $candidate;
                    break;
                }
            }
            $board = $this->sectionBoard($items, $header);
            $cells = $this->columnCells($items, $header['x'], $header['y']);
            $types = $typeHeader === null ? [] : $this->columnCells($items, $typeHeader['x'], $typeHeader['y']);
            foreach ($cells as $cell) {
                $parsed = $this->columnRating($cell['text'], $this->typeNear($types, $cell['y']));
                if ($parsed === null) {
                    continue;
                }
                $rows[] = $this->row($parsed + $cell, $board, $path);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{x: float, y: float, px?: float, py?: float, t: string}>  $items
     * @param  array{x: float, y: float}  $header
     */
    private function sectionBoard(array $items, array $header): string
    {
        $best = null;
        $bestDy = null;
        foreach ($items as $item) {
            if (preg_match('/^ГЕТ\s*([АAБB])$/u', trim($item['t']), $match) !== 1) {
                continue;
            }
            if ($item['x'] < $header['x'] || $item['x'] > $header['x'] + 2000) {
                continue;
            }
            $dy = $header['y'] - $item['y'];
            if ($dy < 0 || $dy > 4000) {
                continue;
            }
            if ($bestDy === null || $dy < $bestDy) {
                $letter = strtr($match[1], ['A' => 'А', 'B' => 'Б']);
                $best = 'ГЕТ '.$letter;
                $bestDy = $dy;
            }
        }

        return $best ?? 'Табло';
    }

    /**
     * @param  list<array{x: float, y: float, px?: float, py?: float, t: string}>  $items
     * @return list<array{text: string, y: float, px: float, py: float}>
     */
    private function columnCells(array $items, float $columnX, float $headerY): array
    {
        $pieces = [];
        foreach ($items as $item) {
            if (abs($item['x'] - $columnX) > 90 || $item['y'] > $headerY - 80) {
                continue;
            }
            $pieces[] = $item;
        }
        usort($pieces, fn (array $a, array $b) => $b['y'] <=> $a['y']);

        $cells = [];
        $current = null;
        foreach ($pieces as $piece) {
            if ($current !== null && $current['y'] - $piece['y'] > 240) {
                $cells[] = $current;
                $current = null;
            }
            if ($current === null) {
                $current = [
                    'text' => $piece['t'],
                    'y' => $piece['y'],
                    'px' => (float) ($piece['px'] ?? 0),
                    'py' => (float) ($piece['py'] ?? 0),
                ];
                continue;
            }
            $current['text'] .= $piece['t'];
        }
        if ($current !== null) {
            $cells[] = $current;
        }

        return $cells;
    }

    /**
     * @param  list<array{text: string, y: float}>  $types
     */
    private function typeNear(array $types, float $y): string
    {
        $best = '';
        $bestDy = null;
        foreach ($types as $type) {
            $dy = abs($type['y'] - $y);
            if ($dy > 220) {
                continue;
            }
            if ($bestDy === null || $dy < $bestDy) {
                $best = $type['text'];
                $bestDy = $dy;
            }
        }

        return trim($best);
    }

    /**
     * @return array{text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string}|null
     */
    private function columnRating(string $text, string $type): ?array
    {
        $text = str_replace(['А', 'С', 'В', ' '], ['A', 'C', 'B', ''], trim($text));
        $type = mb_strtoupper(trim($type));
        if (preg_match('/^(\d+)A\/(\d+)P\/(\d+)mA/i', $text, $match) === 1) {
            return $this->parsed($text, null, (int) $match[2], (int) $match[1], null, 'rcd');
        }
        if (preg_match('/^(\d+)A\/(\d+)P\/([BCD])$/', $text, $match) === 1) {
            $kind = $type === 'ПЛК' ? 'ПЛК' : 'МАП';

            return $this->parsed(
                $text,
                $kind,
                (int) $match[2],
                (int) $match[1],
                $kind === 'ПЛК' ? null : $match[3],
                $kind === 'ПЛК' ? 'mccb' : 'breaker',
            );
        }
        if (preg_match('/^(\d+)A\/(\d+)P$/', $text, $match) === 1 && ($type === 'ПЛК' || $type === 'МАП')) {
            $kind = $type === 'ПЛК' ? 'ПЛК' : 'МАП';

            return $this->parsed(
                $text,
                $kind,
                (int) $match[2],
                (int) $match[1],
                null,
                $kind === 'ПЛК' ? 'mccb' : 'breaker',
            );
        }

        return null;
    }

    /**
     * @param  list<array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x: float, y: float}>  $rows
     * @return list<array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x: float, y: float}>
     */
    private function numbered(array $rows): array
    {
        $numbered = [];
        foreach (array_values($rows) as $index => $row) {
            $row['n'] = $index + 1;
            $numbered[] = $row;
        }

        return $numbered;
    }

    /**
     * @param  array{text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, px?: float, py?: float}  $rating
     * @return array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x: float, y: float}
     */
    private function row(array $rating, string $board, string $path): array
    {
        [$x, $y] = $this->parser->canvasFraction($path, (float) ($rating['px'] ?? 0), (float) ($rating['py'] ?? 0));

        return [
            'n' => 0,
            'board' => $board,
            'text' => $rating['text'],
            'kind' => $rating['kind'],
            'poles' => $rating['poles'],
            'current' => $rating['current'],
            'curve' => $rating['curve'],
            'role' => $rating['role'],
            'x' => $x,
            'y' => $y,
        ];
    }

    /**
     * @param  list<array{x: float, y: float, t: string}>  $items
     * @param  array{x: float, y: float}  $mark
     */
    /**
     * A board title owns every circuit to its right until the next title on the same header row.
     * ГЕТ under T1 is the supply, not a second board.
     *
     * @param  list<array{x: float, y: float, t: string}>  $items
     * @param  array{x: float, y: float}  $mark
     */
    private function board(array $items, array $mark): string
    {
        $titles = [];
        foreach ($items as $item) {
            $name = $this->titleName($item['t']);
            if ($name === null) {
                continue;
            }
            $titles[] = ['x' => $item['x'], 'y' => $item['y'], 'name' => $name];
        }
        $above = array_values(array_filter(
            $titles,
            fn (array $title) => $title['y'] - $mark['y'] >= 80 && $title['y'] - $mark['y'] <= 5000
        ));
        $specific = array_values(array_filter($above, fn (array $title) => $title['name'] !== 'ГЕТ'));
        $pool = $specific !== [] ? $specific : $above;
        if ($pool !== []) {
            $nearest = min(array_map(fn (array $title) => $title['y'] - $mark['y'], $pool));
            $band = array_values(array_filter(
                $pool,
                fn (array $title) => $title['y'] - $mark['y'] <= $nearest + 400
            ));
            usort($band, fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $chosen = null;
            foreach ($band as $title) {
                if ($title['x'] <= $mark['x'] + 800) {
                    $chosen = $title['name'];
                }
            }

            return $chosen ?? $band[0]['name'];
        }
        foreach ($items as $item) {
            if (preg_match('/(?:^|[\s\-])ГЕТ(?:$|[\s\-])/u', $item['t']) === 1) {
                return 'ГЕТ';
            }
        }

        return 'Табло';
    }

    private function titleName(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/T\s*2/i', $text) === 1 && preg_match('/T\s*3/i', $text) === 1) {
            return 'Т2,Т3,Т4';
        }
        if (preg_match('/^T1$|^Т1$/u', $text) === 1) {
            return 'Т1';
        }
        if (preg_match('/^T5$|^Т5$/u', $text) === 1) {
            return 'T5';
        }
        $text = str_replace([';', ';', '­'], ',', $text);
        $text = strtr($text, ['T' => 'Т', 'A' => 'А', 'O' => 'О', 'B' => 'В']);
        if ($text === 'АБ' || $text === 'ТАБ') {
            return 'ТАБ';
        }
        if ($text === 'TO' || $text === 'ТО') {
            return 'ТО';
        }
        if ($text === 'ГТГ' || preg_match('/^ТГ/u', $text) === 1) {
            return 'ТГ1,ТГ2,ТГ3,ТГ4';
        }
        if ($text === 'ГЕТ') {
            return 'ГЕТ';
        }

        return null;
    }

    /**
     * @return array{text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string}|null
     */
    private function rating(string $text): ?array
    {
        $text = str_replace(['А', 'С', 'В'], ['A', 'C', 'B'], trim($text));
        if (preg_match('/^([A-Z])\/(\d+)A\/(\d+)$/', $text, $match) === 1) {
            return $this->parsed($text, 'МАП', (int) $match[3], (int) $match[2], $match[1], 'breaker');
        }
        if (preg_match('/^(\d+)A\/(\d+)P\/(\d+)kA$/i', $text, $match) === 1) {
            return $this->parsed($text, 'ПЛК', (int) $match[2], (int) $match[1], null, 'mccb');
        }
        if (preg_match('/^(\d+)A\/(\d+)P\/(\d+)mA/i', $text, $match) === 1) {
            return $this->parsed($text, null, (int) $match[2], (int) $match[1], null, 'rcd');
        }
        if (preg_match('/^C(\d+)A\/(\d+)\+N$/i', $text, $match) === 1) {
            return $this->parsed($text, null, (int) $match[2], (int) $match[1], 'C', 'rcd');
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)-(\d+(?:[.,]\d+)?)A$/', $text, $match) === 1) {
            return $this->parsed($text, null, 0, 0, null, 'thermal');
        }

        return null;
    }

    /**
     * @return array{text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string}
     */
    private function parsed(string $text, ?string $kind, int $poles, int $current, ?string $curve, string $role): array
    {
        return [
            'text' => $text,
            'kind' => $kind,
            'poles' => $poles,
            'current' => $current,
            'curve' => $curve,
            'role' => $role,
        ];
    }

    /**
     * Single-line sheets write the rating as separate words (C, 1P, 16A) next to the symbol.
     * Identical drawings collapse into one board whose quantity is the number of copies.
     *
     * @param  list<array{x: float, y: float, px?: float, py?: float, t: string}>  $items
     * @return list<array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x: float, y: float, copies: int}>
     */
    private function symbolStamps(array $items, string $path): array
    {
        $fragments = [];
        foreach ($items as $item) {
            $role = $this->stampRole($item['t']);
            if ($role === null) {
                continue;
            }
            $fragments[] = $item + ['role' => $role];
        }
        if (count($fragments) < 2) {
            return [];
        }

        $parent = range(0, count($fragments) - 1);
        $find = function (int $index) use (&$parent, &$find): int {
            if ($parent[$index] !== $index) {
                $parent[$index] = $find($parent[$index]);
            }

            return $parent[$index];
        };
        $roles = array_map(fn (array $fragment) => [$fragment['role'] => true], $fragments);
        $count = count($fragments);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                $rootLeft = $find($left);
                $rootRight = $find($right);
                if ($rootLeft === $rootRight) {
                    continue;
                }
                $dx = abs($fragments[$left]['x'] - $fragments[$right]['x']);
                $dy = abs($fragments[$left]['y'] - $fragments[$right]['y']);
                if ($dy > 90 || $dx > 160) {
                    continue;
                }
                $shared = array_intersect_key($roles[$rootLeft], $roles[$rootRight]);
                if ($shared !== []) {
                    continue;
                }
                $parent[$rootRight] = $rootLeft;
                $roles[$rootLeft] += $roles[$rootRight];
            }
        }

        $groups = [];
        foreach ($fragments as $index => $fragment) {
            $groups[$find($index)][] = $fragment;
        }

        $devices = [];
        foreach ($groups as $group) {
            usort($group, fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $text = implode(' ', array_map(fn (array $piece) => $piece['t'], $group));
            $parsed = $this->stampDevice($text);
            if ($parsed === null) {
                continue;
            }
            $anchor = $group[0];
            $devices[] = $parsed + [
                'x' => $anchor['x'],
                'y' => $anchor['y'],
                'px' => (float) ($anchor['px'] ?? 0),
                'py' => (float) ($anchor['py'] ?? 0),
            ];
        }
        if ($devices === []) {
            return [];
        }

        $marks = [];
        foreach ($items as $item) {
            $mark = $this->boardMark($item['t']);
            if ($mark !== null) {
                $marks[] = $mark + ['x' => $item['x'], 'y' => $item['y']];
            }
        }

        $drawingOf = range(0, count($devices) - 1);
        $findDrawing = function (int $index) use (&$drawingOf, &$findDrawing): int {
            if ($drawingOf[$index] !== $index) {
                $drawingOf[$index] = $findDrawing($drawingOf[$index]);
            }

            return $drawingOf[$index];
        };
        $deviceCount = count($devices);
        for ($left = 0; $left < $deviceCount; $left++) {
            for ($right = $left + 1; $right < $deviceCount; $right++) {
                $dx = abs($devices[$left]['x'] - $devices[$right]['x']);
                $dy = abs($devices[$left]['y'] - $devices[$right]['y']);
                if ($dx < 2200 && $dy < 1000) {
                    $drawingOf[$findDrawing($right)] = $findDrawing($left);
                }
            }
        }
        $drawings = [];
        foreach ($devices as $index => $device) {
            $drawings[$findDrawing($index)][] = $device;
        }

        $usedMarks = [];
        $drawingMeta = [];
        foreach ($drawings as $id => $list) {
            $minX = min(array_column($list, 'x'));
            $maxX = max(array_column($list, 'x'));
            $minY = min(array_column($list, 'y'));
            $maxY = max(array_column($list, 'y'));
            $copies = 0;
            $prefix = null;
            $suffixes = [];
            foreach ($marks as $markIndex => $mark) {
                if ($mark['x'] < $minX - 1600 || $mark['x'] > $maxX + 1600) {
                    continue;
                }
                if ($mark['y'] < $minY - 1800 || $mark['y'] > $maxY + 400) {
                    continue;
                }
                $usedMarks[$markIndex] = true;
                $copies += $mark['copies'];
                $prefix = $mark['name'];
                if ($mark['suffix'] !== '') {
                    foreach (explode(', ', $mark['suffix']) as $suffix) {
                        $suffixes[] = $suffix;
                    }
                }
            }
            $drawingMeta[$id] = [
                'copies' => max(1, $copies),
                'prefix' => $prefix ?? $this->looseBoardName($items),
                'suffixes' => $suffixes,
                'devices' => $list,
            ];
        }

        if (count($drawings) === 1) {
            $only = array_key_first($drawingMeta);
            $extra = 0;
            $prefix = $drawingMeta[$only]['prefix'];
            foreach ($marks as $markIndex => $mark) {
                if (isset($usedMarks[$markIndex]) || $mark['name'] !== $prefix) {
                    continue;
                }
                $extra += $mark['copies'];
                if ($mark['suffix'] !== '') {
                    foreach (explode(', ', $mark['suffix']) as $suffix) {
                        $drawingMeta[$only]['suffixes'][] = $suffix;
                    }
                }
            }
            if ($extra > 0) {
                $drawingMeta[$only]['copies'] = max($drawingMeta[$only]['copies'], $extra);
            }
        }

        $signature = function (array $list): string {
            $parts = [];
            foreach ($list as $device) {
                $parts[] = $device['role'].'|'.$device['poles'].'|'.$device['current'].'|'.$device['curve'];
            }
            sort($parts);

            return implode(';', $parts);
        };
        $merged = [];
        foreach ($drawingMeta as $meta) {
            $key = $meta['prefix'].'|'.$signature($meta['devices']);
            if (! isset($merged[$key])) {
                $merged[$key] = $meta;
                continue;
            }
            $merged[$key]['copies'] += $meta['copies'];
            $merged[$key]['suffixes'] = array_merge($merged[$key]['suffixes'], $meta['suffixes']);
        }

        $named = [];
        $orphans = [];
        foreach ($merged as $key => $meta) {
            if ($meta['prefix'] === 'Табло') {
                $orphans[$key] = $meta;
                continue;
            }
            $named[$key] = $meta;
        }
        foreach ($orphans as $key => $meta) {
            $signatureKey = explode('|', $key, 2)[1] ?? '';
            $host = null;
            foreach (array_keys($named) as $namedKey) {
                if ((explode('|', $namedKey, 2)[1] ?? '') === $signatureKey) {
                    $host = $namedKey;
                    break;
                }
            }
            if ($host === null) {
                continue;
            }
            $named[$host]['copies'] += $meta['copies'];
            unset($orphans[$key]);
        }
        if (count($named) === 1 && $orphans !== []) {
            $host = array_key_first($named);
            foreach ($orphans as $meta) {
                $named[$host]['devices'] = array_merge($named[$host]['devices'], $meta['devices']);
            }
            $orphans = [];
        }
        foreach ($orphans as $key => $meta) {
            $named[$key] = $meta;
        }
        foreach ($marks as $markIndex => $mark) {
            if (isset($usedMarks[$markIndex]) || $mark['suffix'] === '') {
                continue;
            }
            $hosts = [];
            foreach ($named as $namedKey => $meta) {
                if ($meta['prefix'] === $mark['name']) {
                    $hosts[] = $namedKey;
                }
            }
            if (count($hosts) !== 1) {
                continue;
            }
            foreach (explode(', ', $mark['suffix']) as $suffix) {
                $named[$hosts[0]]['suffixes'][] = $suffix;
            }
        }

        $rows = [];
        foreach ($named as $meta) {
            $name = $this->joinedBoardName($meta['prefix'], $meta['suffixes']);
            foreach ($meta['devices'] as $device) {
                $rows[] = $this->row($device, $name, $path) + ['copies' => $meta['copies']];
            }
        }

        return $rows;
    }

    private function stampRole(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^(Easy\s*9|HAGER|C120)$/ui', $text) === 1) {
            return 'brand';
        }
        if (preg_match('/^[BCD]$/u', $text) === 1) {
            return 'curve';
        }
        if (preg_match('/^\d+P(\+N)?$/i', $text) === 1) {
            return 'poles';
        }
        if (preg_match('/^\d+\s*A$/i', $text) === 1 || preg_match('/^\d+A\s+Тип/ui', $text) === 1) {
            return 'current';
        }
        if (preg_match('/30\s*mA/i', $text) === 1) {
            return 'residual';
        }
        if (preg_match('/230\s*VAC/i', $text) === 1) {
            return 'coil';
        }
        if (preg_match('/PRF|12[,.]5/i', $text) === 1) {
            return 'spd';
        }
        if (preg_match('/^C,\s*1P$/i', $text) === 1) {
            return 'legend';
        }

        return null;
    }

    /**
     * @return array{text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string}|null
     */
    private function stampDevice(string $text): ?array
    {
        $flat = trim(preg_replace('/\s+/', ' ', str_replace(['А', 'С', 'В'], ['A', 'C', 'B'], $text)) ?? $text);
        if (preg_match('/C,\s*1P/i', $flat) === 1 && preg_match('/mA/i', $flat) !== 1) {
            return null;
        }
        if (preg_match('/PRF|12[,.]5|C120/i', $flat) === 1) {
            return null;
        }
        if (preg_match('/1P\+N/i', $flat) === 1 && preg_match('/(\d+)\s*A/i', $flat, $amp) === 1 && preg_match('/30\s*mA/i', $flat) === 1) {
            $curve = preg_match('/\b([BCD])\b/', $flat, $curveMatch) === 1 ? $curveMatch[1] : 'C';

            return $this->parsed($curve.$amp[1].'A/1+N', 'ДТЗ', 2, (int) $amp[1], $curve, 'rcd');
        }
        if (preg_match('/2P/i', $flat) === 1 && preg_match('/(\d+)\s*A/i', $flat, $amp) === 1 && preg_match('/30\s*mA/i', $flat) === 1) {
            return $this->parsed($amp[1].'A/2P/30mA AC', 'ДТЗ', 2, (int) $amp[1], null, 'rcd');
        }
        if (preg_match('/230\s*VAC/i', $flat) === 1 && preg_match('/(\d+)\s*A/i', $flat, $amp) === 1) {
            return $this->parsed($amp[1].'A/230VAC', 'КОНТ', 2, (int) $amp[1], null, 'contactor');
        }
        if (preg_match('/(\d+)\s*P/i', $flat, $poles) === 1 && preg_match('/(\d+)\s*A/i', $flat, $amp) === 1 && preg_match('/\b([BCD])\b/', $flat, $curve) === 1) {
            $poleCount = (int) $poles[1];
            if ($poleCount > 4) {
                return null;
            }

            return $this->parsed($amp[1].'A/'.$poleCount.'P/'.$curve[1], 'МАП', $poleCount, (int) $amp[1], $curve[1], 'breaker');
        }

        return null;
    }

    /**
     * @return array{name: string, suffix: string, copies: int}|null
     */
    private function boardMark(string $text): ?array
    {
        $text = trim(str_replace(['\\', ';', ';', '­', "\u{00a0}"], ['', ',', ',', '', ' '], $text));
        if (preg_match('/^Тск\.?\s*(\d+)\b/u', $text, $match) === 1) {
            return ['name' => 'Тск', 'suffix' => $match[1], 'copies' => 1];
        }
        if (preg_match('/^Тап\.?\s*([\d\s,]+)/u', $text, $match) === 1) {
            preg_match_all('/\d+/', $match[1], $numbers);
            if ($numbers[0] === []) {
                return null;
            }

            return ['name' => 'Тап', 'suffix' => implode(', ', $numbers[0]), 'copies' => count($numbers[0])];
        }
        if (preg_match('/^ТГ\s*(\d+(?:\.\d+)?)/u', $text, $match) === 1) {
            return ['name' => 'ТГ', 'suffix' => $match[1], 'copies' => 1];
        }
        if (preg_match('/^Тгар/u', $text) === 1) {
            return ['name' => 'Тгар', 'suffix' => '', 'copies' => 1];
        }

        return null;
    }

    /**
     * @param  list<array{t: string}>  $items
     */
    private function looseBoardName(array $items): string
    {
        $tokens = [];
        foreach ($items as $item) {
            $token = trim($item['t']);
            if ($token === 'Т' || $token === 'o.' || $token === 'о.' || $token === 'н' || $token === '.') {
                $tokens[] = $token;
            }
        }
        $joined = implode('', $tokens);
        if (str_contains($joined, 'о') || str_contains($joined, 'o')) {
            return 'Т о.н.';
        }

        return 'Табло';
    }

    /**
     * @param  list<string>  $suffixes
     */
    private function joinedBoardName(string $prefix, array $suffixes): string
    {
        $suffixes = array_values(array_unique(array_filter($suffixes, fn (string $suffix) => $suffix !== '')));
        if ($suffixes === []) {
            return $prefix;
        }
        usort($suffixes, fn (string $a, string $b) => strnatcmp($a, $b));
        $dotted = $prefix === 'Тск' || $prefix === 'Тап';

        return $prefix.($dotted ? '.' : '').implode(', ', $suffixes);
    }
}
