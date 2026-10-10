<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
use App\Services\StorefrontContentService;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $admin = $request->user('admin');
        $permissions = app(AdminPermissionService::class);
        $turnstile = app(TurnstileService::class);
        $storefront = app(StorefrontContentService::class);

        return [
            ...parent::share($request),
            'status' => fn () => $request->session()->get('status'),
            'auth' => function (): array {
                $user = auth('web')->user();
                if (! $user) {
                    return ['user' => null];
                }

                $wallet = $user->wallet()->firstOrCreate([]);

                return [
                    'user' => [
                        ...$user->only('id', 'name', 'email', 'phone', 'membership_tier_code', 'leaderboard_opt_in'),
                        'email_verified' => $user->hasVerifiedEmail(),
                        'balance_idr' => (int) $wallet->balance_idr,
                    ],
                ];
            },
            'storefront' => fn () => $storefront->shared(),
            'security' => function () use ($request, $turnstile): array {
                $config = $turnstile->publicConfig();
                $path = trim($request->path(), '/');
                $required = false;
                $action = null;

                if ($path === 'register') {
                    $required = true;
                    $action = 'register';
                } elseif ($path === 'forgot-password') {
                    $required = true;
                    $action = 'forgot_password';
                } elseif ($path === 'login') {
                    $required = true;
                    $action = 'customer_login';
                } elseif ($path === 'admin/login') {
                    $required = true;
                    $action = 'admin_login';
                } elseif ($path === 'admin/forgot-password') {
                    $required = true;
                    $action = 'admin_forgot_password';
                }

                return [
                    'turnstile_enabled' => $config['enabled'],
                    'turnstile_site_key' => $config['site_key'],
                    'turnstile_required' => $config['enabled'] && $required,
                    'turnstile_action' => $config['enabled'] && $required ? $action : null,
                ];
            },
            'adminPanel' => function () use ($admin, $permissions): ?array {
                if (! $admin) {
                    return null;
                }

                return [
                    'admin' => $admin->only('id', 'name', 'email', 'role'),
                    'base_path' => '/admin',
                    'menu' => $permissions->menu($admin),
                    'can_notifications' => $permissions->allows($admin, 'notifications.view'),
                    'unread_notifications' => Schema::hasTable('admin_notifications')
                        && $permissions->allows($admin, 'notifications.view')
                        ? DB::table('admin_notifications as notifications')
                            ->whereNotExists(function ($query) use ($admin): void {
                                $query->selectRaw('1')
                                    ->from('admin_notification_reads as reads')
                                    ->whereColumn('reads.admin_notification_id', 'notifications.id')
                                    ->where('reads.admin_user_id', $admin->id);
                            })->count()
                        : 0,
                ];
            },
        ];
    }
}
