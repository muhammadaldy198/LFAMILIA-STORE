<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NicknameController;
use App\Http\Controllers\OrderSearchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\TurnstileController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'ok' => true,
    'service' => 'lfamilia-laravel',
    'environment' => app()->environment(),
]));

Route::get('/system-status', SystemStatusController::class);
Route::get('/security/turnstile', [TurnstileController::class, 'show']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::get('/account', [AccountController::class, 'show']);
Route::patch('/account', [AccountController::class, 'update']);

Route::get('/products', [ProductController::class, 'index']);
Route::post('/nickname', [NicknameController::class, 'verify']);

Route::get('/orders/search', [OrderSearchController::class, 'recent']);
Route::post('/orders/search', [OrderSearchController::class, 'search']);

Route::get('/admin/session', [AdminSessionController::class, 'session']);

// Payment status, payment creation, provider callbacks, wallet mutation, and
// fulfillment remain disabled until their state-transition invariants are
// fully ported and covered by regression tests.
