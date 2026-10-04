<?php

namespace App\Services\Eticad;

use App\Support\CatalogCode;
use Illuminate\Support\Facades\DB;
use Throwable;

class EticadFaceService
{
    public function __construct(private EticadFaceGeometry $geometry) {}

    /**
     * @return array{
     *     svg: string,
     *     width_mm: float,
     *     height_mm: float,
     *     rails: list<array{x: float, y: float, width: float}>
     * }|null
     */
    public function forCode(string $code): ?array
    {
        $code = CatalogCode::normalize($code);
        if (! CatalogCode::looksLike($code)) {
            return null;
        }

        try {
            $product = DB::connection('eticad')->table('products')
                ->where('order_no', $code)
                ->first(['drawing_library', 'drawing_block']);
        } catch (Throwable) {
            return null;
        }
        if ($product === null || $product->drawing_library === null || $product->drawing_block === null) {
            return null;
        }

        try {
            $asset = DB::connection('eticad')->table('assets')
                ->where('kind', 'block')
                ->where('library', $product->drawing_library)
                ->where('block', $product->drawing_block)
                ->first(['container', 'offset', 'size', 'codec', 'storage_path']);
        } catch (Throwable) {
            $asset = DB::connection('eticad')->table('assets')
                ->where('kind', 'block')
                ->where('library', $product->drawing_library)
                ->where('block', $product->drawing_block)
                ->first(['container', 'offset', 'size', 'codec']);
        }
        if ($asset === null || $asset->codec !== 'zlib-dxf') {
            return null;
        }

        $bytes = $this->blockBytes($asset);
        if ($bytes === null) {
            return null;
        }
        $dxf = gzuncompress($bytes);
        if ($dxf === false) {
            return null;
        }

        return $this->geometry->fromDxf($dxf);
    }

    private function blockBytes(object $asset): ?string
    {
        $relative = $asset->storage_path ?? null;
        if (is_string($relative) && $relative !== '') {
            $file = storage_path('app/'.str_replace('\\', '/', $relative));
            if (! is_file($file)) {
                return null;
            }
            $bytes = file_get_contents($file);

            return is_string($bytes) ? $bytes : null;
        }

        if (! is_string($asset->container ?? null) || ! is_file($asset->container)) {
            return null;
        }
        $handle = fopen($asset->container, 'rb');
        if ($handle === false) {
            return null;
        }
        fseek($handle, (int) $asset->offset);
        $bytes = fread($handle, (int) $asset->size);
        fclose($handle);

        return is_string($bytes) ? $bytes : null;
    }
}
