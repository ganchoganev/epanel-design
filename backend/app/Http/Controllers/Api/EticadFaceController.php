<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Eticad\EticadFaceService;
use Illuminate\Http\JsonResponse;

class EticadFaceController extends Controller
{
    public function show(string $code, EticadFaceService $faces): JsonResponse
    {
        $face = $faces->forCode($code);
        if ($face === null) {
            abort(404);
        }

        return response()->json($face);
    }
}
