<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'LFAMILIA STORE',
        'runtime' => 'laravel',
        'status' => 'migration-in-progress',
    ], 503);
});
