<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        abort_unless($admin && $admin->is_active && $admin->role === 'STAFF', 403);

        return $next($request);
    }
}
