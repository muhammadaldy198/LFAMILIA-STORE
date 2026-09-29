<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();

        abort_unless($admin && $admin->is_active
            && in_array($admin->role, ['SUPER_ADMIN', 'ADMIN'], true), 403);

        return $next($request);
    }
}
