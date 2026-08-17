<?php

use App\Http\Controllers\Api\CatalogImportController;
use App\Http\Controllers\Api\ComponentGroupController;
use App\Http\Controllers\Api\PriceImportController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => 'ETI Panel Designer']));

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/series', [ProductController::class, 'series']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    Route::post('/catalog/import/eplan', [CatalogImportController::class, 'importEplan']);
    Route::get('/catalog/import/logs', [CatalogImportController::class, 'logs']);

    Route::get('/prices/profiles', [PriceImportController::class, 'profiles']);
    Route::post('/prices/profiles', [PriceImportController::class, 'storeProfile']);
    Route::post('/prices/preview', [PriceImportController::class, 'preview']);
    Route::post('/prices/import', [PriceImportController::class, 'import']);

    Route::get('/groups', [ComponentGroupController::class, 'index']);
    Route::post('/groups', [ComponentGroupController::class, 'store']);
    Route::get('/groups/{group}', [ComponentGroupController::class, 'show']);
    Route::delete('/groups/{group}', [ComponentGroupController::class, 'destroy']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{project}/duplicate', [ProjectController::class, 'duplicate']);
    Route::post('/projects/{project}/versions', [ProjectController::class, 'createVersion']);
    Route::get('/projects/{project}/bom', [ProjectController::class, 'bom']);
    Route::get('/projects/{project}/export/pdf', [ProjectController::class, 'exportPdf']);
    Route::get('/projects/{project}/export/csv', [ProjectController::class, 'exportCsv']);
    Route::get('/projects/{project}/export/excel', [ProjectController::class, 'exportExcel']);
});
