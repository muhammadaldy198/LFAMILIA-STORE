<?php

use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'ok' => true,
    'service' => 'lfamilia-laravel',
    'environment' => app()->environment(),
]));

Route::get('/system-status', SystemStatusController::class);

// Payment and fulfillment callback routes are intentionally not exposed here yet.
// They will be added only after the Laravel ports preserve signature validation,
// idempotency, status monotonicity, and server-side amount verification.
