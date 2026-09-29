<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // These legacy-compatible panel form endpoints use an explicit same-origin
        // guard and rate limiter. Excluding only these exact paths prevents the
        // current frontend from requiring a new CSRF field during the migration.
        $middleware->validateCsrfTokens(except: [
            'admin/panel/auth/login',
            'admin/panel/auth/logout',
            'staff/panel/auth/login',
            'staff/panel/auth/logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create();
