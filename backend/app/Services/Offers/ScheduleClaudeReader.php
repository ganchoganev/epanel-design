<?php

namespace App\Services\Offers;

use App\Models\ScheduleMap;
use App\Services\Claude\ClaudeClient;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

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
        $notes = [];
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }
            $note = $this->tables->sheetNote($path);
            if ($note !== '') {
                $notes[$note] = true;
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
            $read = $this->fromTable($table, '');
            $read['sheet_note'] = implode(' ', array_keys($notes));

            return $read;
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
Това са съседни изрезки от един лист. Надписите са част от чертежа. Прочети всяка изрезка. Не измисляй апарат, който не е написан. Ако символът е срязан от ръба, не го брой: той е цял в съседната изрезка.

Чертежите са три вида. Ползвай вида, който виждаш.

1) Таблица с маркировка F1, FA, QA, Q и номинал до нея, например C/63A/1.
- Една маркировка и най-близкият пълен номинал са един апарат.
- Легендата вдясно не се брои.
- Втори печат в същата колона под вече броен номинал не е втори прекъсвач.
- C/63A/1 е МАП 1 полюс 63A крива C. 160A/3P/36kA е ПЛК. 40A/2P/30mA AC и C16A/1+N са ДТЗ. 2,5-4A е термореле, не прекъсвач.
- 63A/1P и 32A/1P под дефектнотоковата не са автоматичен прекъсвач. Сложи ги в unread.
- Заглавието на таблото е над маркировката: Т1, Т2,Т3,Т4, T5, ТАБ, ТО, ТГ1,ТГ2,ТГ3,ТГ4, ГЕТ. Ако има конкретно име, не ползвай ГЕТ.

2) Таблица с колони „Номинален ток на прекъсвач“ и „Тип прекъсвач“. На един лист може да има две такива таблици.
- Номиналът може да е на части: 50, А, /1P/C. Събери ги в един ред.
- 16A/1P/C е МАП 1p C16. 250A/3P и тип ПЛК е ПЛК.
- Таблото е най-близкото „ГЕТ А“ или „ГЕТ Б“.
- Колоната „Тип табло“ с „тип 1“ до „тип 4“ е означение на извода, не ново табло. „РТ“ в описанието е бележка към реда, не тип прекъсвач.
- „8бр.“, „11бр.“ и подобни до кабелните редове са брой кабели. Сложи ги в unread и не ги брой като прекъсвач.
- Ако двете таблици на листа имат едни и същи редове, това е едно табло, начертано два пъти. Ако редовете се различават, това са две секции на същото табло.
- „4 жилищни сгради“ и етапите в антетката описват обекта. Не умножавай броя на таблата по тях.

3) Еднолинейна схема без таблица. Номиналът е от отделни думи до символа: Easy 9, C, 1P, 16A, 30mA AC, 1P+N, 2P, 25A Тип AC, 230VAC.
- C + 1P + 16A е МАП 1p C16. B + 3P + 25A е МАП 3p B25. C10A и 1P е същото.
- 1P+N + C + 10A + 30mA AC е ДТЗ, poles 2, current 10, curve C, residual_ma 30.
- 2P + 40A + 30mA или „25A Тип AC“ с 2P и 30mA е ДТЗ без крива.
- ID=40A, 30mA и 2P е ДТЗ 40A. Vigi е ДТЗ само ако има собствен символ.
- 230VAC до 16A е контактор, не прекъсвач. PRF и 12,5 е арестор, не прекъсвач. HAGER-40A е превключвател, не прекъсвач. Тези три отиват в unread с точния надпис.
- Надпис с запетая „C, 1P“ е легенда, не апарат.
- Ред „Прекъсвач“ в таблица под схемата само потвърждава тока. Не добавяй втори апарат.
- Еднакви схеми една до друга са едно табло. quantity е броят еднакви схеми. Името е списъкът: Тск.1, 2, 3, 4, 5, 6 или Тап.1, 4, 7. Ако името е изписано веднъж, а номерата са в заглавието, quantity е броят номера.
- Пиши тока както е на чертежа. 20A не става 25A.
- Не сливай съседни табла в едно име. T5 не влиза при Т2, Т3, Т4. Ако заглавието е списък, quantity е броят имена, а бройките на апаратите са за едно табло, не умножени по копията.
- 63A/1P и 32A/1P не са автоматичен прекъсвач. Сложи ги в unread.
- Латинско T в TАБ и TГ1 е същото табло като ТАБ и ТГ1.

Общо за всички видове: кабел, лампа, контакт, мощност и фаза не са апарати. Колоната ОБЩО не е табло. board е кирилското име, както е написано.

Върни само JSON:
{"devices":[{"board":"Т1","kind":"МАП","poles":1,"current":16,"curve":"C","quantity":1,"text":"C16A 1P"},{"board":"Т1","kind":"ДТЗ","poles":2,"current":40,"residual_ma":30,"quantity":1,"text":"ID=40A 30mA 2P"}],"unread":[{"board":"Тгар","text":"16A/230VAC","quantity":1,"reason":"контактор"}]}
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
            try {
                $remembered = ScheduleMap::query()
                    ->where('signature', ScheduleMap::signature($row['text']))
                    ->exists();
            } catch (Throwable) {
                $remembered = false;
            }
            if ($remembered || $row['kind'] === 'МАП' || $row['kind'] === 'ПЛК' || $row['role'] === 'rcd' || $row['role'] === 'thermal') {
                $kind = $row['kind'] ?? ($row['role'] === 'thermal' ? 'ТЗ' : ($row['role'] === 'rcd' ? 'ДТЗ' : 'АПАРАТ'));
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
                    $this->copies($board, (int) ($row['copies'] ?? 1)),
                    (string) ($row['note'] ?? ''),
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

    private function copies(string $board, int $copies): int
    {
        if ($copies > 1) {
            return $copies;
        }
        preg_match_all('/\d+/', $board, $numbers);
        $count = count($numbers[0] ?? []);

        return $count > 1 ? $count : 1;
    }

    private function tableReason(string $role, string $text): string
    {
        return match ($role) {
            'rcd' => 'Ред '.$text.' от таблицата е дефектнотокова защита. Не се мапва към автоматичен прекъсвач на ETI.',
            'thermal' => 'Ред '.$text.' от таблицата е термореле. Не се мапва към автоматичен прекъсвач на ETI.',
            'cable' => 'Надпис '.$text.' е брой кабели до извода, не апарат.',
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
