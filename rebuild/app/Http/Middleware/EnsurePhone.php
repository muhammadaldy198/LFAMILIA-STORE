<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhone
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->phone) {
            return redirect()->route('account.phone.edit');
        }

        return $next($request);
    }
}
