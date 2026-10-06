<?php

namespace App\Services\Offers;

use App\Services\Claude\ClaudeClient;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Claude reads the drawings. It never sees the ETI catalog and never returns catalog numbers.
 */
class ScheduleClaudeReader
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly ScheduleTableReader $tables,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<UploadedFile>  $tiles
     * @return array{circuits: list<ScheduleCircuit>, unread: list<array{board: string, text: string, quantity: int, reason: string}>, table_count: int}
     */
    public function read(array $files, array $tiles = []): array
    {
        if ($files === []) {
            throw new RuntimeException('Качете поне една схема в PDF.');
        }

        $table = [];
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }
            foreach ($this->tables->rows($path) as $row) {
                $row['file'] = $file->getClientOriginalName();
                $table[] = $row;
            }
        }
        foreach ($table as $index => $row) {
            $table[$index]['n'] = $index + 1;
        }

        if ($table !== []) {
            return $this->fromTable($table, '');
        }
        if ($tiles === []) {
            throw new RuntimeException('NEEDS_TILES');
        }

        return $this->fromPictures($tiles);
    }

    /**
     * Stroke text on an AutoCAD plot is readable only on a close crop.
     *
     * @param  list<UploadedFile>  $tiles
     * @return array{circuits: list<ScheduleCircuit>, unread: list<array{board: string, text: string, quantity: int, reason: string}>, table_count: int}
     */
    private function fromPictures(array $tiles): array
    {
        $content = [[
            'type' => 'text',
            'text' => $this->pictureInstruction(),
        ]];
        foreach ($tiles as $tile) {
            $bytes = file_get_contents($tile->getRealPath() ?: '');
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/jpeg',
                    'data' => base64_encode($bytes),
                ],
            ];
        }
        $images = array_slice($content, 1);
        if ($images === []) {
            throw new RuntimeException('Схемата не можа да се разреже за четене.');
        }

        $circuits = [];
        $unread = [];
        foreach (array_chunk($images, 4) as $group) {
            try {
                $part = $this->parse($this->claude->textFromResponse($this->claude->messages([
                    $content[0],
                    ...$group,
                ], 8000)));
            } catch (RuntimeException) {
                continue;
            }
            foreach ($part['circuits'] as $circuit) {
                if (mb_strtoupper($circuit->board) === 'ОБЩО') {
                    continue;
                }
                $circuits[] = $circuit;
            }
            $unread = array_merge($unread, $part['unread']);
        }
        if ($circuits === [] && $unread === []) {
            throw new RuntimeException('Схемата не върна нито един апарат. Проверете дали PDF е електрическата схема.');
        }

        return [
            'circuits' => $circuits,
            'unread' => $unread,
            'table_count' => 0,
        ];
    }

    private function pictureInstruction(): string
    {
        return <<<'TEXT'
Това са съседни изрезки от един лист с еднолинейни схеми. Надписите са част от чертежа. Прочети всяка изрезка.

Всеки цял прекъсвач е отделен апарат. Ако символът е срязан от ръба на снимката, не го брой: той е цял в съседната изрезка.
- C10A и 1P е МАП, 1 полюс, 10A, крива C. C16A и 1P е МАП 16A крива C. B25A и 3P е МАП 25A крива B, 3 полюса.
- ID=40A, 30mA и 2P е дефектнотокова защита: kind ДТЗ, poles 2, current 40, residual_ma 30.
- Vigi с отделен символ е ДТЗ. Не удвоявай съседния автоматичен прекъсвач.
- Редът „Прекъсвач“ в таблицата под схемата само потвърждава тока. Не добавяй от него втори апарат.
- board е заглавието над схемата, на кирилица, както е написано: Т1, Т2, ТАБ, ТО, ТГ, ГЕТ. Не го превеждай.
- Кабел, лампи, контакти, мощност и фаза не са апарати. Колоната ОБЩО не е табло.

Върни само JSON:
{"devices":[{"board":"Т1","kind":"МАП","poles":1,"current":16,"curve":"C","quantity":1,"text":"C16A 1P"},{"board":"Т1","kind":"ДТЗ","poles":2,"current":40,"residual_ma":30,"quantity":1,"text":"ID=40A 30mA 2P"}],"unread":[]}
TEXT;
    }

    /**
     * @return array{circuits: list<ScheduleCircuit>, unread: list<array{board: string, text: string, quantity: int, reason: string}>}
     */
    public function parse(string $text): array
    {
        $json = $this->jsonObject($text);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Claude не върна четима схема. Отговорът не е JSON.');
        }

        $circuits = [];
        foreach ($decoded['devices'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kind = $this->kind((string) ($row['kind'] ?? ''));
            $poles = (int) ($row['poles'] ?? 0);
            $current = (int) ($row['current'] ?? 0);
            $curve = $this->curve($row['curve'] ?? null);
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $board = trim((string) ($row['board'] ?? ''));
            if ($board === '') {
                $board = 'Табло';
            }
            if ($kind === null || $poles < 1 || $current < 1) {
                continue;
            }
            if ($kind === 'ДТЗ') {
                $residual = (int) ($row['residual_ma'] ?? 0);
                if ($residual < 1 && preg_match('/(\d+)\s*mA/i', (string) ($row['text'] ?? ''), $milliamp) === 1) {
                    $residual = (int) $milliamp[1];
                }
                if ($residual < 1) {
                    continue;
                }
                $rating = $current.'A/'.$poles.'P/'.$residual.'mA';
            } else {
                $rating = $kind === 'ПЛК'
                    ? $current.'A/'.$poles.'P'
                    : $current.'A/'.$poles.'P'.($curve !== null ? '/'.$curve : '');
            }
            for ($copy = 0; $copy < $quantity; $copy++) {
                $circuits[] = new ScheduleCircuit(
                    $board,
                    $kind,
                    $poles,
                    $current,
                    $curve,
                    $rating,
                );
            }
        }

        $unread = [];
        foreach ($decoded['unread'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['text'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($label === '' && $reason === '') {
                continue;
            }
            $unread[] = [
                'board' => trim((string) ($row['board'] ?? '')) ?: 'Табло',
                'text' => $label !== '' ? $label : 'неясен надпис',
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'reason' => $reason !== '' ? $reason : 'Надписът не се разчете като апарат.',
            ];
        }

        foreach ($decoded['devices'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kind = $this->kind((string) ($row['kind'] ?? ''));
            $poles = (int) ($row['poles'] ?? 0);
            $current = (int) ($row['current'] ?? 0);
            if ($kind !== null && $poles >= 1 && $current >= 1) {
                continue;
            }
            $unread[] = [
                'board' => trim((string) ($row['board'] ?? '')) ?: 'Табло',
                'text' => trim((string) ($row['text'] ?? $row['kind'] ?? 'апарат')),
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'reason' => 'Редът от схемата няма тип, полюси и ток, затова не се мапва към изделие на ETI.',
            ];
        }

        if ($circuits === [] && $unread === []) {
            throw new RuntimeException('Схемата не върна нито един апарат. Проверете дали PDF е електрическата схема.');
        }

        return ['circuits' => $circuits, 'unread' => $unread];
    }

    /**
     * @param  list<array{n: int, board: string, text: string, kind: ?string, poles: int, current: int, curve: ?string, role: string, x?: float, y?: float, file?: string}>  $table
     * @return array{circuits: list<ScheduleCircuit>, unread: list<array{board: string, text: string, quantity: int, reason: string, x: float, y: float, file: string}>, table_count: int}
     */
    private function fromTable(array $table, string $text): array
    {
        $boards = [];
        try {
            $decoded = json_decode($this->jsonObject($text), true);
            foreach (is_array($decoded) ? ($decoded['rows'] ?? []) : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $number = (int) ($row['n'] ?? 0);
                $board = trim((string) ($row['board'] ?? ''));
                if ($number > 0 && $board !== '') {
                    $boards[$number] = $board;
                }
            }
        } catch (RuntimeException) {
            $boards = [];
        }

        $circuits = [];
        $unread = [];
        foreach ($table as $row) {
            $board = $boards[$row['n']] ?? $row['board'];
            if ($row['kind'] === 'МАП' || $row['kind'] === 'ПЛК' || $row['role'] === 'rcd' || $row['role'] === 'thermal') {
                $kind = $row['kind'] ?? ($row['role'] === 'thermal' ? 'ТЗ' : 'ДТЗ');
                $circuits[] = new ScheduleCircuit(
                    $board,
                    $kind,
                    $row['poles'],
                    (float) $row['current'],
                    $row['curve'],
                    $row['text'],
                    (float) ($row['x'] ?? 0),
                    (float) ($row['y'] ?? 0),
                    (string) ($row['file'] ?? ''),
                );
                continue;
            }
            $unread[] = [
                'board' => $board,
                'text' => $row['text'],
                'quantity' => 1,
                'reason' => $this->tableReason($row['role'], $row['text']),
                'x' => (float) ($row['x'] ?? 0),
                'y' => (float) ($row['y'] ?? 0),
                'file' => (string) ($row['file'] ?? ''),
            ];
        }

        return [
            'circuits' => $circuits,
            'unread' => $unread,
            'table_count' => count($table),
        ];
    }

    private function tableReason(string $role, string $text): string
    {
        return match ($role) {
            'rcd' => 'Ред '.$text.' от таблицата е дефектнотокова защита. Не се мапва към автоматичен прекъсвач на ETI.',
            'thermal' => 'Ред '.$text.' от таблицата е термореле. Не се мапва към автоматичен прекъсвач на ETI.',
            default => 'Ред '.$text.' от таблицата не е автоматичен прекъсвач и няма код на ETI.',
        };
    }

    private function jsonObject(string $text): string
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $fenced) === 1) {
            return $fenced[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Claude не върна четима схема.');
        }

        return substr($text, $start, $end - $start + 1);
    }

    private function kind(string $value): ?string
    {
        $value = mb_strtoupper(trim($value));

        return match ($value) {
            'МАП', 'MCB', 'АВТОМАТИЧЕН ПРЕКЪСВАЧ' => 'МАП',
            'ПЛК', 'MCCB' => 'ПЛК',
            'ДТЗ', 'RCD', 'RCCB', 'ДЕФЕКТНОТОКОВА ЗАЩИТА' => 'ДТЗ',
            default => null,
        };
    }

    private function curve(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $curve = strtoupper(trim((string) $value));
        if ($curve === '' || $curve === 'NULL') {
            return null;
        }

        return in_array($curve, ['B', 'C', 'D'], true) ? $curve : null;
    }
}
