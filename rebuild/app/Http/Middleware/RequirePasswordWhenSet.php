<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passwordless Google customers can complete their phone onboarding without
 * being redirected to a password confirmation screen they cannot pass.
 *
 * Existing password-bearing accounts retain Laravel's normal recent-password
 * confirmation for this sensitive profile change.
 */
class RequirePasswordWhenSet
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->password) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next);
    }
}
