<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceTrustedHost;
use App\Http\Middleware\EnsureCustomerSessionFresh;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsurePhone;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PublicAbuseProtection;
use App\Http\Middleware\RequireAdminPasswordConfirm;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackCustomerActivity;
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
        $middleware->append([AssignCorrelationId::class, EnforceTrustedHost::class, SecurityHeaders::class]);
        $middleware->web(append: [PublicAbuseProtection::class, EnsureCustomerSessionFresh::class, HandleInertiaRequests::class]);
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin/*')
            ? route('admin.login') : route('login'));

        $middleware->alias([
            'phone.required' => EnsurePhone::class,
            'admin.role' => EnsureAdminRole::class,
            'admin.permission' => EnsureAdminPermission::class,
            'admin.super' => EnsureSuperAdmin::class,
            'admin.password.confirm' => RequireAdminPasswordConfirm::class,
            'customer.activity' => TrackCustomerActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
