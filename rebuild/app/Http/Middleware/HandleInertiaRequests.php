<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
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

        return [
            ...parent::share($request),
            'status' => fn () => $request->session()->get('status'),
            'security' => [
                'turnstile' => app(TurnstileService::class)->publicConfig(),
            ],
            'adminPanel' => function () use ($admin, $permissions): ?array {
                if (!$admin) {
                    return null;
                }

                return [
                    'admin' => $admin->only('id', 'name', 'email', 'role'),
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
