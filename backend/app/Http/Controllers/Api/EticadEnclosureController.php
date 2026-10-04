<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Eticad\EticadEnclosureCatalog;
use Illuminate\Http\JsonResponse;

class EticadEnclosureController extends Controller
{
    public function index(EticadEnclosureCatalog $catalog): JsonResponse
    {
        return response()->json(['enclosures' => $catalog->all()]);
    }
}
