<?php

namespace App\Http\Controllers;

use App\Services\AdminPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController
{
    public function __invoke(Request $request): Response
    {
        $today = now()->startOfDay();
        $adminId = $request->user('admin')->id;

        $metrics = [
            'orders_today' => DB::table('orders')->where('created_at', '>=', $today)->count(),
            'revenue_today' => (int) DB::table('orders')
                ->where('created_at', '>=', $today)
                ->whereIn('status', ['PAID', 'PROCESSING', 'SUCCESS'])
                ->sum('total_idr'),
            'success_today' => DB::table('orders')->where('created_at', '>=', $today)
                ->where('status', 'SUCCESS')->count(),
            'open_tickets' => DB::table('support_tickets')->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count(),
            'pending_fulfillment' => DB::table('fulfillment_attempts')
                ->whereIn('status', ['CREATED', 'SENDING', 'PENDING', 'UNKNOWN', 'BLOCKED', 'MANUAL_PENDING'])
                ->count(),
            'wallet_balance' => (int) DB::table('wallets')->sum('balance_idr'),
        ];

        return Inertia::render('Admin/Dashboard', [
            'metrics' => $metrics,
            'recentOrders' => app(AdminPermissionService::class)->allows($request->user('admin'), 'orders.view')
                ? DB::table('orders')->orderByDesc('id')->limit(8)->get(['id', 'order_number', 'status', 'total_idr', 'created_at']) : [],
            'notifications' => DB::table('admin_notifications as notifications')
                ->leftJoin('admin_notification_reads as reads', function ($join) use ($adminId): void {
                    $join->on('reads.admin_notification_id', '=', 'notifications.id')
                        ->where('reads.admin_user_id', '=', $adminId);
                })
                ->orderByDesc('notifications.id')->limit(8)
                ->get(['notifications.id', 'notifications.severity', 'notifications.title',
                    'notifications.message', 'reads.read_at', 'notifications.created_at']),
        ]);
    }
}
