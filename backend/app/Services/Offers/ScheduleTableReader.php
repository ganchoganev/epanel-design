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

        return $this->numbered($rows);
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
}
