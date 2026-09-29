<?php

use App\Http\Controllers\AdminSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/panel/auth/login', [AdminSessionController::class, 'loginBackoffice']);
Route::post('/admin/panel/auth/logout', [AdminSessionController::class, 'logoutBackoffice']);
Route::post('/staff/panel/auth/login', [AdminSessionController::class, 'loginStaff']);
Route::post('/staff/panel/auth/logout', [AdminSessionController::class, 'logoutStaff']);

Route::get('/', function () {
    return response()->json([
        'service' => 'LFAMILIA STORE',
        'runtime' => 'laravel',
        'status' => 'migration-in-progress',
    ], 503);
});
