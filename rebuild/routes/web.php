<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Foundation'));

Route::get('/health/ready', function () {
    try {
        DB::select('SELECT 1');
        Redis::connection()->ping();

        return response()->json(['status' => 'healthy']);
    } catch (Throwable $exception) {
        report($exception);

        return response()->json(['status' => 'unavailable'], 503);
    }
});
