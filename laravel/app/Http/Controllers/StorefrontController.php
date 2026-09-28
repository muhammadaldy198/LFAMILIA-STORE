<?php

namespace App\Http\Controllers;

use App\Services\StoreContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StorefrontController extends Controller
{
    public function storefront(StoreContentService $content): JsonResponse
    {
        return response()->json([
            'settings' => $content->storefront(true),
            'categories' => $content->categories(false),
            'faqs' => $content->faqs(false, true),
        ], 200, [
            'Cache-Control' => 'public, max-age=30, s-maxage=60, stale-while-revalidate=120',
        ]);
    }

    public function home(StoreContentService $content): JsonResponse
    {
        try {
            $settings = $content->storefront(false);
            return response()->json([
                'banners' => $settings['bannerEnabled'] ? $content->banners(false) : [],
                'popups' => $content->popups(false),
            ], 200, [
                'Cache-Control' => 'public, max-age=30, s-maxage=60, stale-while-revalidate=120',
            ]);
        } catch (Throwable) {
            return response()->json(['banners' => [], 'popups' => []], 200, [
                'Cache-Control' => 'public, max-age=15',
            ]);
        }
    }

    public function news(Request $request, StoreContentService $content): JsonResponse
    {
        try {
            $slug = trim((string) $request->query('slug', ''));
            $articles = $content->news(false);

            if ($slug !== '') {
                foreach ($articles as $article) {
                    if ($article['slug'] === $slug) {
                        return response()->json(['article' => $article]);
                    }
                }

                return response()->json(['error' => 'Berita tidak ditemukan.'], 404);
            }

            return response()->json(['articles' => $articles], 200, [
                'Cache-Control' => 'public, max-age=60, s-maxage=120, stale-while-revalidate=180',
            ]);
        } catch (Throwable) {
            return response()->json(['articles' => []]);
        }
    }
}
