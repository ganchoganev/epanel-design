<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CatalogCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CodeReplacementController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::connection('eticad')
            ->table('code_replacements')
            ->orderBy('from_code')
            ->get(['from_code', 'to_code']);

        return response()->json([
            'count' => $rows->count(),
            'rows' => $rows,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt',
        ]);

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $skipped = 0;
        $pairs = [];

        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $from = CatalogCode::normalize((string) ($row[0] ?? ''));
            $to = CatalogCode::normalize((string) ($row[1] ?? ''));
            if (! CatalogCode::looksLike($from) || ! CatalogCode::looksLike($to) || $from === $to) {
                $skipped++;

                continue;
            }
            $pairs[$from] = $to;
        }

        if ($pairs === []) {
            return response()->json([
                'message' => 'Не видях двойки кодове. Първата колона е старият код, втората е новият.',
            ], 422);
        }

        $connection = DB::connection('eticad');
        $connection->transaction(function () use ($connection, $pairs): void {
            $connection->table('code_replacements')->delete();
            foreach ($pairs as $from => $to) {
                $connection->table('code_replacements')->insert([
                    'from_code' => $from,
                    'to_code' => $to,
                ]);
            }
        });

        return response()->json([
            'imported' => count($pairs),
            'skipped' => $skipped,
            'count' => count($pairs),
        ]);
    }
}
