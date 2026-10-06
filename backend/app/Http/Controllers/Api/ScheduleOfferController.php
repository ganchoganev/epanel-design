<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Offers\OfferBoard;
use App\Services\Offers\OfferDraft;
use App\Services\Offers\OfferLine;
use App\Services\Offers\OfferWorkbookWriter;
use App\Services\Offers\ScheduleClaudeReader;
use App\Services\Offers\ScheduleOfferMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScheduleOfferController extends Controller
{
    public function __construct(
        private readonly ScheduleClaudeReader $reader,
        private readonly ScheduleOfferMapper $mapper,
        private readonly OfferWorkbookWriter $writer,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        try {
            $draft = $this->draftFromRequest($request);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'NEEDS_TILES') {
                return response()->json([
                    'message' => 'Надписите са част от чертежа. Разреждам схемата.',
                    'needs_tiles' => true,
                ], 422);
            }
            abort(422, $exception->getMessage());
        }

        return response()->json($draft->toArray());
    }

    public function download(Request $request): BinaryFileResponse
    {
        try {
            $draft = $this->draftFromRequest($request);
        } catch (RuntimeException $exception) {
            abort(422, 'Първо прочетете схемата.');
        }

        return $this->send($draft);
    }

    public function downloadDraft(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'boards' => ['required', 'array'],
            'boards.*.name' => ['required', 'string'],
            'boards.*.quantity' => ['required', 'integer'],
            'boards.*.lines' => ['required', 'array'],
            'boards.*.lines.*.catalog_number' => ['required', 'string'],
            'boards.*.lines.*.name' => ['required', 'string'],
            'boards.*.lines.*.quantity' => ['required', 'integer', 'min:1'],
            'boards.*.lines.*.unit_price' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string'],
        ]);

        $boards = [];
        foreach ($data['boards'] as $board) {
            $lines = [];
            foreach ($board['lines'] as $line) {
                $lines[] = new OfferLine(
                    $line['catalog_number'],
                    $line['name'],
                    (int) $line['quantity'],
                    isset($line['unit_price']) ? (float) $line['unit_price'] : null,
                );
            }
            $boards[] = new OfferBoard($board['name'], (int) $board['quantity'], $lines);
        }

        return $this->send(new OfferDraft($boards, [], $data['note'] ?? ''));
    }

    private function send(OfferDraft $draft): BinaryFileResponse
    {
        $target = storage_path('app/offers/'.Str::uuid().'.xlsx');
        $this->writer->write($draft, $target);

        return response()->download($target, 'oferta.xlsx')->deleteFileAfterSend(true);
    }

    private function draftFromRequest(Request $request): OfferDraft
    {
        $request->validate([
            'files' => ['required_without:file', 'array', 'min:1', 'max:8'],
            'files.*' => ['file', 'max:15360'],
            'file' => ['required_without:files', 'file', 'max:15360'],
            'tiles' => ['nullable', 'array', 'max:40'],
            'tiles.*' => ['file', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $uploaded = $request->file('files', []);
        if ($uploaded instanceof UploadedFile) {
            $uploaded = [$uploaded];
        }
        if ($uploaded === [] && $request->file('file') instanceof UploadedFile) {
            $uploaded = [$request->file('file')];
        }
        foreach ($uploaded as $file) {
            if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
                abort(422, 'Качват се само PDF файлове.');
            }
        }

        $tiles = $request->file('tiles', []);
        if ($tiles instanceof UploadedFile) {
            $tiles = [$tiles];
        }

        try {
            $read = $this->reader->read(array_values($uploaded), array_values($tiles));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'NEEDS_TILES') {
                throw $exception;
            }
            abort(422, $exception->getMessage());
        }

        $draft = $this->mapper->map($read['circuits']);
        $note = $draft->note;
        $tableCount = (int) ($read['table_count'] ?? 0);
        if ($tableCount > 0) {
            $recognized = count($read['circuits']);
            foreach ($read['unread'] as $row) {
                $recognized += (int) $row['quantity'];
            }
            $note = 'Таблицата описва '.$tableCount.' елемента. Разпознати са '.$recognized.'. '.$note;
        }
        if ($read['unread'] !== []) {
            $note .= ' Има надписи от схемата, които не се разчетоха като апарат.';
        }
        if ($draft->unmatched !== []) {
            $note .= ' Има прочетени апарати без единствен код на ETI.';
        }
        $note .= ' Сравнението е с каталога в базата по полюси, ток, крива, дефектен ток и тип. Запомнен надпис от схемата се попълва със същия код.';

        return new OfferDraft($draft->boards, $draft->unmatched, trim($note), $draft->placements, $read['unread']);
    }
}
