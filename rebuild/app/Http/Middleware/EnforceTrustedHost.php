<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTrustedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = (array) config('lfamilia.trusted_hosts', []);
        if ($hosts !== []) {
            $host = strtolower($request->getHost());
            abort_unless(in_array($host, $hosts, true), 400, 'Invalid host.');
        }

        return $next($request);
    }
}
