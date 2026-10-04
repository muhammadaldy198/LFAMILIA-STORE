<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Services\AdminPermissionService;
use App\Services\IntegrationRegistry;
use App\Services\IntegrationRuntimeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    public function __invoke(Request $request, AdminPermissionService $permissions, IntegrationRegistry $registry): Response
    {
        $data = $request->validate(['range' => ['nullable', 'in:today,7d,30d,90d']]);
        $range = $data['range'] ?? '7d';
        $days = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$range];
        $now = Carbon::now('Asia/Jakarta');
        $first = $now->copy()->startOfDay()->subDays($days - 1);
        $dbZone = config('app.timezone', 'UTC');
        $from = $first->copy()->timezone($dbZone);
        $until = $now->copy()->addDay()->startOfDay()->timezone($dbZone);
        $today = $now->copy()->startOfDay()->timezone($dbZone);
        $admin = $request->user('admin');
        $finance = $admin->role === 'SUPER_ADMIN';
        $canOrders = $permissions->allows($admin, 'orders.view');
        $canCatalog = $permissions->allows($admin, 'catalog.manage');
        $canProviders = $permissions->allows($admin, 'providers.manage');
        $canSupport = $permissions->allows($admin, 'support.manage');
        $canNotifications = $permissions->allows($admin, 'notifications.view');
        $period = fn () => DB::table('orders')->where('orders.created_at', '>=', $from)->where('orders.created_at', '<', $until);
        $metrics = [];
        if ($canOrders) {
            $metrics['orders_today'] = DB::table('orders')->where('created_at', '>=', $today)->where('created_at', '<', $until)->count();
            $metrics['success_period'] = $period()->where('status', 'SUCCESS')->count();
        }
        if ($canCatalog) {
            $metrics['active_products'] = DB::table('products')->where('is_active', true)->count();
        }
        if ($canSupport) {
            $metrics['open_tickets'] = DB::table('support_tickets')->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count();
        }
        if ($permissions->allows($admin, 'fulfillment.manage')) {
            $metrics['pending_fulfillment'] = DB::table('fulfillment_attempts')->whereIn('status', ['CREATED', 'SENDING', 'PENDING', 'UNKNOWN', 'BLOCKED', 'MANUAL_PENDING'])->count();
        }
        $balance = $finance ? $this->balance() : null;
        if ($finance) {
            $metrics['revenue_today'] = (int) DB::table('orders')->where('created_at', '>=', $today)->where('created_at', '<', $until)->whereNotNull('paid_at')->sum('total_idr');
            $metrics['wallet_balance'] = (int) DB::table('wallets')->sum('balance_idr');
            $metrics['digiflazz_balance'] = $balance['amount_idr'];
        }

        $chart = [];
        if ($finance) {
            $offset = Carbon::now($dbZone)->format('P');
            $dateSql = "DATE(CONVERT_TZ(orders.created_at, ?, '+07:00'))";
            $daily = $period()->selectRaw($dateSql.' AS day, COUNT(*) AS orders, SUM(CASE WHEN paid_at IS NOT NULL THEN total_idr ELSE 0 END) AS revenue_idr', [$offset])
                ->groupBy('day')->get()->keyBy('day');
            for ($i = 0; $i < $days; $i++) {
                $day = $first->copy()->addDays($i)->toDateString();
                $chart[] = ['day' => $day, 'orders' => (int) ($daily->get($day)?->orders ?? 0), 'revenue_idr' => (int) ($daily->get($day)?->revenue_idr ?? 0)];
            }
        }

        $recent = [];
        if ($canOrders) {
            $recent = $period()->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()
                ->map(function (object $order) use ($finance): array {
                    $snapshot = json_decode($order->snapshot, true) ?: [];
                    $payment = DB::table('payment_transactions')->where('order_id', $order->id)->orderByDesc('id')->first(['channel_code', 'status']);
                    $buyer = $order->user_id ? DB::table('users')->where('id', $order->user_id)->value('name') : null;

                    return [
                        'id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status,
                        'buyer_name' => $buyer ?: ($snapshot['buyer_name'] ?? 'Guest'),
                        'product_name' => data_get($snapshot, 'product.name') ?: ($snapshot['product_name'] ?? DB::table('products')->where('id', $order->product_id)->value('name')),
                        'package_name' => data_get($snapshot, 'package.name') ?: ($snapshot['package_name'] ?? DB::table('product_packages')->where('id', $order->product_package_id)->value('name')),
                        'payment_channel' => $payment?->channel_code ?: ($snapshot['payment_channel_code'] ?? '—'),
                        'payment_status' => $payment?->status ?: ($order->paid_at ? 'PAID' : 'PENDING'),
                        'created_at' => Carbon::parse($order->created_at, config('app.timezone'))->toIso8601String(),
                        ...($finance ? ['total_idr' => (int) $order->total_idr] : []),
                    ];
                })->all();
        }

        $top = [];
        if ($canCatalog && $canOrders) {
            $top = $period()->join('products', 'products.id', '=', 'orders.product_id')
                ->select('products.id', 'products.name', 'products.slug')->selectRaw("SUM(CASE WHEN orders.status = 'SUCCESS' THEN 1 ELSE 0 END) AS fulfilled_orders, COUNT(*) AS total_orders")
                ->groupBy('products.id', 'products.name', 'products.slug')->orderByDesc('fulfilled_orders')->orderByDesc('total_orders')->orderBy('products.name')->limit(5)->get();
        }

        $activities = $finance ? DB::table('audit_logs')->orderByDesc('id')->limit(5)
            ->get(['id', 'action', 'target_type', 'target_id', 'created_at'])->map(fn (object $row): array => [
                'id' => $row->id, 'action' => $row->action, 'target' => $row->target_type.' #'.$row->target_id,
                'created_at' => Carbon::parse($row->created_at, $dbZone)->toIso8601String(),
            ]) : [];
        $integrations = [];
        if ($finance || $canProviders || $permissions->allows($admin, 'payments.manage')) {
            $records = IntegrationCredential::get(['code', 'is_active'])->keyBy('code');
            $health = DB::table('system_settings')->where('key', 'like', 'integration.health.%')->pluck('value', 'key');
            foreach ($registry->all() as $code => $definition) {
                if (! $finance && ! (($canProviders && in_array($code, ['digiflazz', 'kokinpay'], true)) || ($permissions->allows($admin, 'payments.manage') && in_array($code, ['midtrans', 'doku'], true)))) {
                    continue;
                }
                $active = (bool) ($records->get($code)?->is_active);
                $test = json_decode($health->get('integration.health.'.$code, '{}'), true) ?: [];
                $status = $active ? ($test['status'] ?? 'UNTESTED') : 'NOT_CONFIGURED';
                if ($active && $status === 'HEALTHY' && (empty($test['tested_at']) || Carbon::parse($test['tested_at'])->lt(now()->subMinutes(15)))) {
                    $status = 'STALE';
                }
                $integrations[] = ['code' => $code, 'name' => $definition['name'], 'active' => $active, 'status' => $status, 'tested_at' => $test['tested_at'] ?? null];
            }
        }

        return Inertia::render('Admin/Dashboard', [
            'range' => $range, 'generatedAt' => $now->toIso8601String(), 'canViewFinance' => $finance,
            'canOrders' => $canOrders, 'canCatalog' => $canCatalog, 'canNotifications' => $canNotifications,
            'metrics' => $metrics, 'chart' => $chart, 'recentOrders' => $recent, 'topProducts' => $top,
            'activities' => $activities, 'integrations' => $integrations, 'digiflazzBalance' => $balance,
            'webhookReady' => str_starts_with((string) config('app.url'), 'https://'),
            'lastSyncedAt' => ($finance || $canProviders) ? $this->timestamp(DB::table('digiflazz_catalog_items')->max('synced_at')) : null,
            'notifications' => $canNotifications ? DB::table('admin_notifications as notifications')
                ->leftJoin('admin_notification_reads as reads', function ($join) use ($admin): void {
                    $join->on('reads.admin_notification_id', '=', 'notifications.id')->where('reads.admin_user_id', '=', $admin->id);
                })->orderByDesc('notifications.id')->limit(8)
                ->get(['notifications.id', 'notifications.severity', 'notifications.title', 'notifications.message', 'reads.read_at', 'notifications.created_at'])->map(fn (object $row): array => [
                    ...((array) $row), 'created_at' => $this->timestamp($row->created_at), 'read_at' => $this->timestamp($row->read_at),
                ]) : [],
        ]);
    }

    private function timestamp(?string $value): ?string
    {
        return $value ? Carbon::parse($value, config('app.timezone', 'UTC'))->toIso8601String() : null;
    }

    private function balance(): array
    {
        $resolved = $this->runtime->resolve('digiflazz');
        $config = $resolved['config'] ?? [];
        if (! is_array($config)
            || trim((string) ($config['username'] ?? '')) === ''
            || trim((string) ($config['api_key'] ?? '')) === '') {
            return ['amount_idr' => null, 'checked_at' => null, 'status' => 'NOT_CONFIGURED'];
        }

        $key = 'admin.dashboard.digiflazz_balance.'.hash('sha256', json_encode([
            'environment' => $resolved['environment'] ?? null,
            'username' => $config['username'],
        ]));

        return Cache::remember($key, 60, function () use ($config): array {
            try {
                $base = $this->runtime->digiflazzApiBase($config);
                $response = Http::acceptJson()->timeout(2)->connectTimeout(1)->post($base.'/v1/cek-saldo', [
                    'cmd' => 'deposit',
                    'username' => $config['username'],
                    'sign' => md5($config['username'].$config['api_key'].'depo'),
                ]);
                $amount = $response->json('data.deposit');
                if ($response->successful() && is_numeric($amount) && (float) $amount >= 0) {
                    return ['amount_idr' => (int) $amount, 'checked_at' => now()->toIso8601String(), 'status' => 'HEALTHY'];
                }
            } catch (\Throwable) {
                // Dashboard remains available when the read-only provider probe fails.
            }

            return ['amount_idr' => null, 'checked_at' => now()->toIso8601String(), 'status' => 'DOWN'];
        });
    }
}
