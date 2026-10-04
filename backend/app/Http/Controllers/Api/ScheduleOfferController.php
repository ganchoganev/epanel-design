<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Offers\AutocadScheduleParser;
use App\Services\Offers\OfferDraft;
use App\Services\Offers\OfferBoard;
use App\Services\Offers\OfferLine;
use App\Services\Offers\OfferWorkbookWriter;
use App\Services\Offers\ScheduleOfferMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScheduleOfferController extends Controller
{
    public function __construct(
        private readonly AutocadScheduleParser $parser,
        private readonly ScheduleOfferMapper $mapper,
        private readonly OfferWorkbookWriter $writer,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $draft = $this->draftFromRequest($request);

        return response()->json($draft->toArray());
    }

    public function download(Request $request): BinaryFileResponse
    {
        $draft = $this->draftFromRequest($request);

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
            'file' => ['required', 'file', 'max:15360'],
        ]);

        $path = $request->file('file')->getRealPath();
        $circuits = $this->parser->parse($path);
        if ($circuits === []) {
            abort(422, 'Не разчетох апарати. PDF трябва да е плот от AutoCAD с колона „Тип прекъсвач“.');
        }

        return $this->mapper->map($circuits);
    }
}
