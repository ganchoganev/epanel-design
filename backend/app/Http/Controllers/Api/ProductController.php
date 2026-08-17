<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EtiProduct;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = EtiProduct::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('catalog_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($series = $request->query('series')) {
            $query->where('series', $series);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($poles = $request->query('poles')) {
            $query->where('poles', $poles);
        }

        if ($minCurrent = $request->query('min_current')) {
            $query->where('rated_current_a', '>=', $minCurrent);
        }

        if ($maxCurrent = $request->query('max_current')) {
            $query->where('rated_current_a', '<=', $maxCurrent);
        }

        return response()->json(
            $query->orderBy('series')->orderBy('rated_current_a')->paginate($request->integer('per_page', 50))
        );
    }

    public function series()
    {
        return response()->json([
            'series' => EtiProduct::query()->whereNotNull('series')->distinct()->orderBy('series')->pluck('series'),
            'categories' => EtiProduct::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function show(EtiProduct $product)
    {
        return response()->json($product);
    }
}
