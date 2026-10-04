<?php

use App\Http\Controllers\FulfillmentWebhookController;
use App\Http\Controllers\PaymentWebhookController;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api-account'])->get('/account', function (Request $request) {
    $user = $request->user();
    $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'membership_tier' => $user->membership_tier_code,
        'balance_idr' => (int) $wallet->balance_idr,
    ]);
});

Route::post('/payments/midtrans/notification', [PaymentWebhookController::class, 'midtrans'])
    ->middleware('throttle:payment-webhook')
    ->name('api.payments.midtrans.notification');
Route::post('/payments/doku/notification', [PaymentWebhookController::class, 'doku'])
    ->middleware('throttle:payment-webhook')
    ->name('api.payments.doku.notification');

Route::post('/fulfillment/digiflazz/webhook', [FulfillmentWebhookController::class, 'digiflazz'])
    ->middleware('throttle:fulfillment-webhook')
    ->name('api.fulfillment.digiflazz.webhook');
