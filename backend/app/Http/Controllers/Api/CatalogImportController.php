<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogImportLog;
use App\Services\CatalogFile;
use App\Services\EplanImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogImportController extends Controller
{
    public function __construct(private readonly EplanImportService $service)
    {
    }

    public function importEplan(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xml,txt',
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        $log = $this->service->importFromXml($content, $file->getClientOriginalName());

        return response()->json($log, $log->status === 'failed' ? 422 : 200);
    }

    public function exportCatalog(CatalogFile $catalog): BinaryFileResponse
    {
        return response()->download($catalog->export(), 'katalog.csv');
    }

    public function importCatalog(Request $request, CatalogFile $catalog): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        try {
            $result = $catalog->import($request->file('file'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function logs()
    {
        return response()->json(CatalogImportLog::orderByDesc('id')->limit(50)->get());
    }
}
