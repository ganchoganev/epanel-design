<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EticadPhotoController extends Controller
{
    public function show(string $code): BinaryFileResponse
    {
        if (! preg_match('/^\d{5,12}$/', $code)) {
            abort(404);
        }

        $path = storage_path('app/eticad/photos/'.$code.'.jpg');
        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }
}
