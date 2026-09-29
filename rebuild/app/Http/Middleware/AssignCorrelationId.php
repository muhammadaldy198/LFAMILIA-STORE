<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) ($request->header('X-Request-ID')
            ?: $request->header('X-Correlation-ID', '')));
        $correlationId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $correlationId);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
