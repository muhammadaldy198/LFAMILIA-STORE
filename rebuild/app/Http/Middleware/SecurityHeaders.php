<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; ".
            "object-src 'none'; img-src 'self' data: https:; font-src 'self' data:; ".
            "style-src 'self' 'unsafe-inline'; script-src 'self' https://challenges.cloudflare.com; ".
            "frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com"
        );

        if ($request->is('admin/*') || $request->is('account/*') || $request->is('orders/guest/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
