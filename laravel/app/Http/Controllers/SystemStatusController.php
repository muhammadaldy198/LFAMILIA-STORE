<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemStatusController
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return response()->json([
            'ok' => $database === 'ok',
            'service' => 'LFAMILIA STORE',
            'runtime' => 'laravel',
            'database' => $database,
            'environment' => app()->environment(),
        ], $database === 'ok' ? 200 : 503);
    }
}
