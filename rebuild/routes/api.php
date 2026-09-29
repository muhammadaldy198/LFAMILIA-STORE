<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/account', fn (Request $request) => response()->json([
    'id' => $request->user()->id,
    'name' => $request->user()->name,
    'membership_tier' => $request->user()->membership_tier_code,
]));
