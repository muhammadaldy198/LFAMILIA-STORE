<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderSearchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'ok' => true,
    'service' => 'lfamilia-laravel',
    'environment' => app()->environment(),
]));

Route::get('/system-status', SystemStatusController::class);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::get('/account', [AccountController::class, 'show']);
Route::patch('/account', [AccountController::class, 'update']);

Route::get('/products', [ProductController::class, 'index']);

Route::get('/orders/search', [OrderSearchController::class, 'recent']);
Route::post('/orders/search', [OrderSearchController::class, 'search']);

Route::get('/admin/session', [AdminSessionController::class, 'session']);

// Payment status, payment creation, provider callbacks, nickname validation,
// wallet mutation, and fulfillment routes stay disabled until their security
// invariants are fully ported and covered by regression tests.
