<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogImportLog;
use App\Services\EplanImportService;
use Illuminate\Http\Request;

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

    public function logs()
    {
        return response()->json(CatalogImportLog::orderByDesc('id')->limit(50)->get());
    }
}
