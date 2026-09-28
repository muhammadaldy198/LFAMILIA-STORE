<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminOrdersController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminSummaryController;
use App\Http\Controllers\AdminTeamController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DigiflazzCallbackController;
use App\Http\Controllers\DokuCallbackController;
use App\Http\Controllers\ExternalCheckoutController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\NicknameController;
use App\Http\Controllers\OrderSearchController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\TurnstileController;
use App\Http\Controllers\WalletCheckoutController;
use App\Http\Controllers\WalletTopupController;
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
Route::get('/auth/google/status', [GoogleAuthController::class, 'status']);
Route::post('/auth/google', [GoogleAuthController::class, 'login']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'request']);
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset']);

Route::get('/account', [AccountController::class, 'show']);
Route::patch('/account', [AccountController::class, 'update']);
Route::post('/account/topups', [WalletTopupController::class, 'create']);

Route::get('/products', [ProductController::class, 'index']);
Route::post('/nickname', [NicknameController::class, 'verify']);
Route::post('/payments/wallet/create', [WalletCheckoutController::class, 'create']);
Route::post('/payments/auto/create', [ExternalCheckoutController::class, 'create']);
Route::get('/wallet', [WalletTopupController::class, 'settings']);

Route::get('/orders/search', [OrderSearchController::class, 'recent']);
Route::post('/orders/search', [OrderSearchController::class, 'search']);
Route::post('/orders/status', [OrderStatusController::class, 'show']);

Route::get('/admin/session', [AdminSessionController::class, 'session']);
Route::get('/admin/summary', [AdminSummaryController::class, 'show']);
Route::get('/admin/orders', [AdminOrdersController::class, 'index']);
Route::post('/admin/orders', [AdminOrdersController::class, 'create']);
Route::patch('/admin/orders', [AdminOrdersController::class, 'update']);
Route::get('/admin/payment-routing', [AdminPaymentController::class, 'routing']);
Route::put('/admin/payment-routing', [AdminPaymentController::class, 'updateRouting']);
Route::get('/admin/payment-methods', [AdminPaymentController::class, 'methods']);
Route::post('/admin/payment-methods', [AdminPaymentController::class, 'saveMethod']);
Route::delete('/admin/payment-methods', [AdminPaymentController::class, 'deleteMethod']);
Route::get('/admin/team', [AdminTeamController::class, 'index']);
Route::post('/admin/team', [AdminTeamController::class, 'save']);
Route::delete('/admin/team', [AdminTeamController::class, 'destroy']);

Route::get('/payments/midtrans/snap/notification', [MidtransNotificationController::class, 'show']);
Route::post('/payments/midtrans/snap/notification', [MidtransNotificationController::class, 'handle']);
Route::get('/payments/doku/callback', [DokuCallbackController::class, 'show']);
Route::post('/payments/doku/callback', [DokuCallbackController::class, 'handle']);
Route::post('/fulfillment/digiflazz/callback', [DigiflazzCallbackController::class, 'handle']);

// Remaining migration work is focused on background schedulers, notifications,
// admin/customer UI parity, and the final production data cutover.
