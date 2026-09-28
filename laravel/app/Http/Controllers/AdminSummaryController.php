<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\IntegrationConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AdminSummaryController extends Controller
{
    public function show(Request $request, AdminAuthService $auth, IntegrationConfigService $integrations): JsonResponse
    {
        try {
            $access = $auth->require($request, 'staff');
            $range = in_array($request->query('range'), ['today', '7d', '30d', '90d', 'all'], true)
                ? (string) $request->query('range')
                : '7d';
            $canViewFinance = $access['role'] === 'super_admin';
            [$from, $trendDays] = $this->period($range);

            $orders = DB::table('orders as o');
            if ($from) {
                $orders->where('o.created_at', '>=', $from);
            }

            $metrics = (clone $orders)->selectRaw(
                "COUNT(*) AS total_orders,
                 COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS paid_revenue,
                 COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
                   THEN CASE WHEN o.total - COALESCE(o.supplier_cost_snapshot, 0) > 0
                     THEN o.total - COALESCE(o.supplier_cost_snapshot, 0) ELSE 0 END
                   ELSE 0 END), 0) AS profit,
                 COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.discount_amount ELSE 0 END), 0) AS total_discount,
                 COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
                 COALESCE(SUM(CASE WHEN o.payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments,
                 COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
                   AND o.fulfillment_status NOT IN ('success','failed','cancelled') THEN 1 ELSE 0 END), 0) AS pending_fulfillments,
                 COALESCE(SUM(CASE WHEN o.payment_status = 'failed' OR o.fulfillment_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_orders"
            )->first();

            $todayStart = CarbonImmutable::now()->startOfDay();
            $today = DB::table('orders as o')
                ->where('o.created_at', '>=', $todayStart)
                ->selectRaw(
                    "COUNT(*) AS total_orders,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS paid_revenue,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
                       THEN CASE WHEN o.total - COALESCE(o.supplier_cost_snapshot, 0) > 0
                         THEN o.total - COALESCE(o.supplier_cost_snapshot, 0) ELSE 0 END
                       ELSE 0 END), 0) AS profit,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'failed' OR o.fulfillment_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_orders"
                )->first();

            $activeProducts = DB::table('products')->where('is_active', 1)->count();
            $customers = DB::table('customer_users')
                ->where('is_active', 1)
                ->where('email', 'not like', '__lfadmin__:%')
                ->count();

            $paymentStatuses = (clone $orders)
                ->selectRaw('o.payment_status AS status, COUNT(*) AS count')
                ->groupBy('o.payment_status')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => ['status' => $row->status ?: 'unknown', 'count' => (int) $row->count])
                ->all();

            $fulfillmentStatuses = (clone $orders)
                ->selectRaw('o.fulfillment_status AS status, COUNT(*) AS count')
                ->groupBy('o.fulfillment_status')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => ['status' => $row->status ?: 'unknown', 'count' => (int) $row->count])
                ->all();

            $rawChart = DB::table('orders as o')
                ->where('o.created_at', '>=', CarbonImmutable::now()->subDays($trendDays - 1)->startOfDay())
                ->selectRaw(
                    "DATE(o.created_at) AS day,
                     COUNT(*) AS orders,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
                       THEN CASE WHEN o.total - COALESCE(o.supplier_cost_snapshot, 0) > 0
                         THEN o.total - COALESCE(o.supplier_cost_snapshot, 0) ELSE 0 END
                       ELSE 0 END), 0) AS profit"
                )
                ->groupByRaw('DATE(o.created_at)')
                ->orderBy('day')
                ->get()
                ->keyBy('day');

            $chart = [];
            for ($offset = $trendDays - 1; $offset >= 0; $offset--) {
                $day = CarbonImmutable::now()->subDays($offset)->format('Y-m-d');
                $row = $rawChart->get($day);
                $chart[] = [
                    'day' => $day,
                    'orders' => (int) ($row->orders ?? 0),
                    'revenue' => $canViewFinance ? (int) ($row->revenue ?? 0) : null,
                    'profit' => $canViewFinance ? (int) ($row->profit ?? 0) : null,
                ];
            }

            $topProductsQuery = DB::table('orders as o');
            if ($from) {
                $topProductsQuery->where('o.created_at', '>=', $from);
            }
            $topProducts = $topProductsQuery
                ->selectRaw(
                    "o.product_slug AS slug, o.product_name AS name,
                     COUNT(*) AS total_orders,
                     COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue,
                     COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
                       THEN CASE WHEN o.total - COALESCE(o.supplier_cost_snapshot, 0) > 0
                         THEN o.total - COALESCE(o.supplier_cost_snapshot, 0) ELSE 0 END
                       ELSE 0 END), 0) AS profit"
                )
                ->groupBy('o.product_slug', 'o.product_name')
                ->orderByDesc('fulfilled_orders')
                ->orderByDesc('total_orders')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                    'totalOrders' => (int) $row->total_orders,
                    'fulfilledOrders' => (int) $row->fulfilled_orders,
                    'revenue' => $canViewFinance ? (int) $row->revenue : null,
                    'profit' => $canViewFinance ? (int) $row->profit : null,
                ])
                ->all();

            $recentOrders = (clone $orders)
                ->orderByDesc('o.created_at')
                ->limit(8)
                ->get([
                    'o.id', 'o.reference_id', 'o.product_name', 'o.package_label', 'o.buyer_name',
                    'o.payment_method', 'o.payment_channel', 'o.payment_status',
                    'o.fulfillment_status', 'o.total', 'o.created_at',
                ])
                ->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'referenceId' => (string) $row->reference_id,
                    'productName' => (string) $row->product_name,
                    'packageLabel' => (string) $row->package_label,
                    'buyerName' => (string) $row->buyer_name,
                    'paymentMethod' => (string) $row->payment_method,
                    'paymentChannel' => (string) $row->payment_channel,
                    'paymentStatus' => (string) $row->payment_status,
                    'fulfillmentStatus' => (string) $row->fulfillment_status,
                    'total' => $canViewFinance ? (int) $row->total : null,
                    'createdAt' => $row->created_at,
                ])
                ->all();

            $activities = $access['role'] === 'staff'
                ? []
                : DB::table('admin_activity_logs')
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(['id', 'admin_name', 'admin_role', 'action', 'target', 'created_at'])
                    ->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'adminName' => (string) $row->admin_name,
                        'adminRole' => (string) $row->admin_role,
                        'action' => (string) $row->action,
                        'target' => (string) $row->target,
                        'createdAt' => $row->created_at,
                    ])
                    ->all();

            $attention = [
                'sellerOff' => DB::table('digiflazz_seller_monitor')->where('seller_product_status', 0)->count(),
                'outOfStock' => DB::table('digiflazz_seller_monitor')->where('unlimited_stock', 0)->where('stock', '<=', 0)->count(),
                'priceChanged' => DB::table('digiflazz_seller_monitor')
                    ->whereNotNull('baseline_price')->whereNotNull('current_price')
                    ->whereColumn('current_price', '<>', 'baseline_price')->count(),
                'digiflazzPending' => DB::table('orders')
                    ->where('provider_code', 'digiflazz')->where('payment_status', 'paid')
                    ->whereIn('fulfillment_status', ['processing', 'dispatching', 'pending'])->count(),
                'paymentCallbackFailed' => DB::table('orders')
                    ->where('payment_method', '<>', 'wallet')->where('payment_status', 'failed')->count(),
                'manualPending' => DB::table('orders')
                    ->where('fulfillment_type', 'manual')->where('payment_status', 'paid')
                    ->whereIn('fulfillment_status', ['manual_pending', 'processing'])->count(),
            ];

            $digiflazzRuntime = $integrations->digiflazzRuntime();
            $digiflazzReady = $digiflazzRuntime['username'] !== '' && $digiflazzRuntime['apiKey'] !== '';
            $paymentOverview = $integrations->paymentOverview();
            $dokuReady = (bool) ($paymentOverview['dokuCheckoutConfigured'] ?? false);

            return response()->json([
                'range' => $range,
                'role' => $access['role'],
                'canViewFinance' => $canViewFinance,
                'metrics' => [
                    'totalOrders' => (int) ($metrics->total_orders ?? 0),
                    'paidRevenue' => $canViewFinance ? (int) ($metrics->paid_revenue ?? 0) : null,
                    'profit' => $canViewFinance ? (int) ($metrics->profit ?? 0) : null,
                    'totalDiscount' => $canViewFinance ? (int) ($metrics->total_discount ?? 0) : null,
                    'approvedTopups' => $canViewFinance
                        ? (int) DB::table('wallet_topups')->where('status', 'approved')->sum('amount')
                        : null,
                    'activeProducts' => $activeProducts,
                    'customers' => $customers,
                    'fulfilledOrders' => (int) ($metrics->fulfilled_orders ?? 0),
                    'pendingPayments' => (int) ($metrics->pending_payments ?? 0),
                    'pendingFulfillments' => (int) ($metrics->pending_fulfillments ?? 0),
                    'failedOrders' => (int) ($metrics->failed_orders ?? 0),
                ],
                'todayMetrics' => [
                    'paidRevenue' => $canViewFinance ? (int) ($today->paid_revenue ?? 0) : null,
                    'profit' => $canViewFinance ? (int) ($today->profit ?? 0) : null,
                    'totalOrders' => (int) ($today->total_orders ?? 0),
                    'pendingPayments' => (int) ($today->pending_payments ?? 0),
                    'failedOrders' => (int) ($today->failed_orders ?? 0),
                    'activeProducts' => $activeProducts,
                ],
                'paymentStatuses' => $paymentStatuses,
                'fulfillmentStatuses' => $fulfillmentStatuses,
                'statuses' => $fulfillmentStatuses,
                'chart' => $chart,
                'topProducts' => $topProducts,
                'topCategories' => [],
                'topCustomers' => [],
                'integrations' => [
                    'doku' => ['ready' => $access['role'] !== 'staff' && $dokuReady],
                    'digiflazz' => [
                        'ready' => $access['role'] !== 'staff' && $digiflazzReady,
                        'environment' => $canViewFinance
                            ? $digiflazzRuntime['environment']
                            : null,
                        'reason' => null,
                        'balance' => null,
                        'issues' => array_sum($attention),
                        'lastSyncAt' => DB::table('product_packages')
                            ->where('provider_code', 'digiflazz')
                            ->max('supplier_synced_at'),
                    ],
                    'webhook' => [
                        'ready' => str_starts_with((string) config('lfamilia.public_base_url'), 'https://'),
                        'baseUrl' => $canViewFinance ? config('lfamilia.public_base_url') : null,
                    ],
                ],
                'attention' => $access['role'] === 'staff'
                    ? ['pendingPayments' => 0, 'pendingFulfillments' => 0, 'failedOrders' => 0, 'lowStock' => 0, 'sellerOff' => 0, 'outOfStock' => 0, 'priceChanged' => 0]
                    : $attention,
                'recentActivities' => $activities,
                'recentOrders' => $recentOrders,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            $status = str_contains($error->getMessage(), 'Sesi panel') ? 401 : 403;
            return response()->json(['error' => $error->getMessage()], $status, ['Cache-Control' => 'no-store']);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Ringkasan gagal dimuat.',
            ], 503, ['Cache-Control' => 'no-store']);
        }
    }

    /** @return array{0:?CarbonImmutable,1:int} */
    private function period(string $range): array
    {
        return match ($range) {
            'today' => [CarbonImmutable::now()->startOfDay(), 1],
            '7d' => [CarbonImmutable::now()->subDays(6)->startOfDay(), 7],
            '30d' => [CarbonImmutable::now()->subDays(29)->startOfDay(), 30],
            '90d' => [CarbonImmutable::now()->subDays(89)->startOfDay(), 90],
            default => [null, 30],
        };
    }
}