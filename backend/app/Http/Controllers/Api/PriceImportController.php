<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceImportProfile;
use App\Services\PriceImportService;
use Illuminate\Http\Request;

class PriceImportController extends Controller
{
    public function __construct(private readonly PriceImportService $service)
    {
    }

    public function profiles()
    {
        return response()->json(PriceImportProfile::orderBy('name')->get());
    }

    public function storeProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'column_mapping' => 'required|array',
            'column_mapping.catalog_number' => 'required|integer',
            'column_mapping.price' => 'required|integer',
            'header_row' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->service->storeProfile($data['name'], $data['column_mapping'], $data['header_row'] ?? 1),
            201
        );
    }

    public function preview(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt',
            'header_row' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->service->preview($request->file('file'), $request->integer('header_row', 1))
        );
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt',
            'column_mapping' => 'required|array',
            'column_mapping.catalog_number' => 'required|integer',
            'column_mapping.price' => 'required|integer',
            'header_row' => 'nullable|integer|min:1',
        ]);

        $result = $this->service->import(
            $request->file('file'),
            $data['column_mapping'],
            $data['header_row'] ?? 1
        );

        return response()->json($result);
    }
}
