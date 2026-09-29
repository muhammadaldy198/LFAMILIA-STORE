<?php

namespace App\Http\Controllers;

use App\Services\TurnstileService;
use Illuminate\Http\JsonResponse;
use Throwable;

class TurnstileController extends Controller
{
    public function show(TurnstileService $turnstile): JsonResponse
    {
        try {
            return response()->json($turnstile->publicConfig(), 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return response()->json([
                'enabled' => false,
                'siteKey' => null,
                'error' => 'Konfigurasi keamanan belum siap.',
            ], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
