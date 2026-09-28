<?php

use App\Http\Controllers\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/system-status', SystemStatusController::class);

// Existing production callback paths are intentionally reserved here.
// Their implementations will be ported with signature validation and idempotency
// before traffic is moved away from the current Cloudflare deployment.
