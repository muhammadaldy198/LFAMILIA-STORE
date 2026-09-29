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
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        if (!app()->environment('local')) {
            $response->headers->set(
                'Content-Security-Policy',
                'default-src \'self\'; '.
                'script-src \'self\' https://challenges.cloudflare.com; '.
                'style-src \'self\' \'unsafe-inline\'; '.
                'img-src \'self\' data: https:; '.
                'font-src \'self\' data:; '.
                'connect-src \'self\' https://challenges.cloudflare.com; '.
                'frame-src https://challenges.cloudflare.com; '.
                'object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'; form-action \'self\''
            );
        }
        $response->headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is('admin/*')
            || $request->is('account/*')
            || $request->is('login')
            || $request->is('register')
            || $request->is('forgot-password')
            || $request->is('reset-password/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
