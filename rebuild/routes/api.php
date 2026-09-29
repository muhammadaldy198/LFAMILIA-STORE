<?php

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/account', function (Request $request) {
    $user = $request->user();
    $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'membership_tier' => $user->membership_tier_code,
        'balance_idr' => (int) $wallet->balance_idr,
    ]);
});
