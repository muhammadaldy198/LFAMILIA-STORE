<?php

namespace App\Http\Controllers;

use App\Services\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProductController extends Controller
{
    public function index(ProductCatalogService $catalog): JsonResponse
    {
        try {
            return response()->json($catalog->publicCatalog(), 200, [
                'Cache-Control' => 'public, max-age=10, s-maxage=10, stale-while-revalidate=20',
            ]);
        } catch (Throwable) {
            return response()->json([
                'products' => [],
                'databaseReady' => false,
            ], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
