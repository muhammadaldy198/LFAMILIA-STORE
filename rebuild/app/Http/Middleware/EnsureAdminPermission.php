<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function __construct(private readonly AdminPermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $allowed = collect($permissions)
            ->contains(fn (string $permission): bool => $this->permissions->allows(
                $request->user('admin'),
                $permission
            ));
        abort_unless($allowed, 403);

        return $next($request);
    }
}
