<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackCustomerActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user && (! $user->last_active_at || $user->last_active_at->lt(now()->subHour()))) {
            $user->forceFill(['last_active_at' => now()])->save();
        }

        return $next($request);
    }
}
