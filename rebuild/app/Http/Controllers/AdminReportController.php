<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController
{
    private const RANGE_OPTIONS = ['today', '7d', '30d', '90d', 'all', 'custom'];

    private const FAILURE_STATUSES = ['UNKNOWN', 'FAILED_CONFIRMED', 'BLOCKED', 'MANUAL_FAILED'];

    private const PENDING_FULFILLMENT_STATUSES = ['CREATED', 'SENDING', 'PENDING', 'UNKNOWN', 'BLOCKED', 'MANUAL_PENDING'];

    public function index(Request $request): Response
    {
        [$filters, $from, $to] = $this->period($request);
        $finance = $request->user('admin')->role === 'SUPER_ADMIN';

        $orders = fn (): Builder => DB::table('orders')
            ->whereBetween('created_at', [$from, $to]);

        $metrics = [
            'orders_total' => $orders()->count(),
            'success_total' => $orders()->where('status', 'SUCCESS')->count(),
            'pending_payment_total' => $orders()->where('status', 'PENDING_PAYMENT')->count(),
            'pending_fulfillment_total' => $orders()->whereIn('status', ['PAID', 'PROCESSING'])->count(),
            'failed_total' => $orders()->where('status', 'FAILED')->count(),
            'expired_total' => $orders()->where('status', 'EXPIRED')->count(),
            'cancelled_total' => $orders()->where('status', 'CANCELLED')->count(),
            'provider_errors' => DB::table('fulfillment_attempts')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('status', self::FAILURE_STATUSES)->count(),
        ];

        if ($finance) {
            $paid = $orders()->whereNotNull('paid_at');
            $metrics += [
                'paid_revenue_idr' => (int) (clone $paid)->sum('total_idr'),
                'gross_profit_idr' => (int) (clone $paid)->selectRaw(
                    'COALESCE(SUM(GREATEST(total_idr - fee_idr - cost_idr, 0)), 0) AS aggregate'
                )->value('aggregate'),
                'discount_idr' => (int) (clone $paid)->sum('discount_idr'),
                'payment_fee_idr' => (int) (clone $paid)->sum('fee_idr'),
                'wallet_liability_idr' => (int) DB::table('wallets')->sum('balance_idr'),
                'paid_topup_idr' => (int) DB::table('wallet_topups')
                    ->whereBetween('created_at', [$from, $to])
                    ->whereNotNull('paid_at')->sum('amount_idr'),
            ];
        }

        $daily = $this->dailyRows($from, $to, $finance);
        $topProducts = $this->topProducts($from, $to, $finance);
        $topCategories = $this->topCategories($from, $to, $finance);
        $providers = $this->providerRows($from, $to);

        return Inertia::render('Admin/Reports', [
            'canViewFinance' => $finance,
            'filters' => $filters,
            'metrics' => $metrics,
            'daily' => $daily,
            'topProducts' => $topProducts,
            'topCategories' => $topCategories,
            'providerReport' => $providers,
            'orderStatuses' => DB::table('orders')
                ->whereBetween('created_at', [$from, $to])
                ->select('status')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('status')->orderByDesc('total')->get()
                ->map(fn (object $row): array => [
                    'status' => (string) $row->status,
                    'total' => (int) $row->total,
                ])->values(),
            'paymentStatuses' => DB::table('payment_transactions')
                ->whereBetween('created_at', [$from, $to])
                ->select('status')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('status')->orderByDesc('total')->get()
                ->map(fn (object $row): array => [
                    'status' => (string) $row->status,
                    'total' => (int) $row->total,
                ])->values(),
            'fulfillmentStatuses' => DB::table('fulfillment_attempts')
                ->whereBetween('created_at', [$from, $to])
                ->select('status')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('status')->orderByDesc('total')->get()
                ->map(fn (object $row): array => [
                    'status' => (string) $row->status,
                    'total' => (int) $row->total,
                ])->values(),
        ]);
    }

    public function export(Request $request, AdminAuditService $audit): StreamedResponse
    {
        [$filters, $from, $to] = $this->period($request);
        $finance = $request->user('admin')->role === 'SUPER_ADMIN';
        $daily = $this->dailyRows($from, $to, $finance);

        $audit->record($request, 'report.exported', 'report', 'sales', null, [
            'range' => $filters['range'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'finance' => $finance,
        ]);

        $name = 'laporan-lfamilia-'.$filters['from'].'-'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($daily, $finance): void {
            $stream = fopen('php://output', 'w');
            if (! is_resource($stream)) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $finance
                ? ['Tanggal', 'Pesanan', 'Berhasil', 'Gagal', 'Omzet', 'Laba Kotor', 'Diskon', 'Biaya Pembayaran']
                : ['Tanggal', 'Pesanan', 'Berhasil', 'Gagal']);

            foreach ($daily as $row) {
                $values = [
                    $row['day'],
                    $row['orders_count'],
                    $row['success_count'],
                    $row['failed_count'],
                ];
                if ($finance) {
                    $values[] = $row['revenue_idr'];
                    $values[] = $row['profit_idr'];
                    $values[] = $row['discount_idr'];
                    $values[] = $row['fee_idr'];
                }
                fputcsv($stream, $values);
            }

            fclose($stream);
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array{0:array{range:string,from:string,to:string},1:Carbon,2:Carbon}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'range' => ['nullable', Rule::in(self::RANGE_OPTIONS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $range = (string) ($data['range'] ?? '');
        if ($range === '' && (isset($data['from']) || isset($data['to']))) {
            $range = 'custom';
        }
        if ($range === '') {
            $range = '30d';
        }

        $today = now()->startOfDay();
        [$from, $to] = match ($range) {
            'today' => [$today->copy(), $today->copy()->endOfDay()],
            '7d' => [$today->copy()->subDays(6), $today->copy()->endOfDay()],
            '30d' => [$today->copy()->subDays(29), $today->copy()->endOfDay()],
            '90d' => [$today->copy()->subDays(89), $today->copy()->endOfDay()],
            'all' => [
                DB::table('orders')->min('created_at')
                    ? Carbon::parse((string) DB::table('orders')->min('created_at'))->startOfDay()
                    : $today->copy(),
                $today->copy()->endOfDay(),
            ],
            default => [
                Carbon::parse((string) ($data['from'] ?? $today->toDateString()))->startOfDay(),
                Carbon::parse((string) ($data['to'] ?? $today->toDateString()))->endOfDay(),
            ],
        };

        return [[
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ], $from, $to];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function dailyRows(Carbon $from, Carbon $to, bool $finance): array
    {
        $select = [
            "DATE(created_at) as day",
            'COUNT(*) as orders_count',
            "SUM(CASE WHEN status = 'SUCCESS' THEN 1 ELSE 0 END) as success_count",
            "SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count",
        ];

        if ($finance) {
            $select[] = 'SUM(CASE WHEN paid_at IS NOT NULL THEN total_idr ELSE 0 END) as revenue_idr';
            $select[] = 'SUM(CASE WHEN paid_at IS NOT NULL THEN GREATEST(total_idr - fee_idr - cost_idr, 0) ELSE 0 END) as profit_idr';
            $select[] = 'SUM(CASE WHEN paid_at IS NOT NULL THEN discount_idr ELSE 0 END) as discount_idr';
            $select[] = 'SUM(CASE WHEN paid_at IS NOT NULL THEN fee_idr ELSE 0 END) as fee_idr';
        }

        return DB::table('orders')->whereBetween('created_at', [$from, $to])
            ->selectRaw(implode(', ', $select))
            ->groupByRaw('DATE(created_at)')->orderBy('day')->get()
            ->map(function (object $row) use ($finance): array {
                $data = [
                    'day' => (string) $row->day,
                    'orders_count' => (int) $row->orders_count,
                    'success_count' => (int) $row->success_count,
                    'failed_count' => (int) $row->failed_count,
                ];

                if ($finance) {
                    $data += [
                        'revenue_idr' => (int) $row->revenue_idr,
                        'profit_idr' => (int) $row->profit_idr,
                        'discount_idr' => (int) $row->discount_idr,
                        'fee_idr' => (int) $row->fee_idr,
                    ];
                }

                return $data;
            })->values()->all();
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function topProducts(Carbon $from, Carbon $to, bool $finance): array
    {
        $query = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('products.id', 'products.name', 'products.slug')
            ->select([
                'products.id',
                'products.name',
                'products.slug',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw("SUM(CASE WHEN orders.status = 'SUCCESS' THEN 1 ELSE 0 END) as success_orders"),
            ]);

        if ($finance) {
            $query->selectRaw('SUM(CASE WHEN orders.paid_at IS NOT NULL THEN orders.total_idr ELSE 0 END) as revenue_idr')
                ->selectRaw('SUM(CASE WHEN orders.paid_at IS NOT NULL THEN GREATEST(orders.total_idr - orders.fee_idr - orders.cost_idr, 0) ELSE 0 END) as profit_idr');
        }

        return $query->orderByDesc('success_orders')->orderByDesc('total_orders')
            ->orderBy('products.name')->limit(10)->get()
            ->map(function (object $row) use ($finance): array {
                $data = [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'total_orders' => (int) $row->total_orders,
                    'success_orders' => (int) $row->success_orders,
                ];
                if ($finance) {
                    $data += [
                        'revenue_idr' => (int) $row->revenue_idr,
                        'profit_idr' => (int) $row->profit_idr,
                    ];
                }

                return $data;
            })->values()->all();
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function topCategories(Carbon $from, Carbon $to, bool $finance): array
    {
        $query = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('categories.id', 'categories.name')
            ->select([
                'categories.id',
                'categories.name',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw("SUM(CASE WHEN orders.status = 'SUCCESS' THEN 1 ELSE 0 END) as success_orders"),
            ]);

        if ($finance) {
            $query->selectRaw('SUM(CASE WHEN orders.paid_at IS NOT NULL THEN orders.total_idr ELSE 0 END) as revenue_idr');
        }

        return $query->orderByDesc('success_orders')->orderByDesc('total_orders')
            ->orderBy('categories.name')->limit(10)->get()
            ->map(function (object $row) use ($finance): array {
                $data = [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'total_orders' => (int) $row->total_orders,
                    'success_orders' => (int) $row->success_orders,
                ];
                if ($finance) {
                    $data['revenue_idr'] = (int) $row->revenue_idr;
                }

                return $data;
            })->values()->all();
    }

    /**
     * @return array<int, array<string, int|string|float>>
     */
    private function providerRows(Carbon $from, Carbon $to): array
    {
        return DB::table('fulfillment_attempts as attempts')
            ->leftJoin('providers', 'providers.id', '=', 'attempts.provider_id')
            ->whereBetween('attempts.created_at', [$from, $to])
            ->groupBy('providers.id', 'providers.code', 'providers.display_name')
            ->select([
                'providers.id',
                'providers.code',
                'providers.display_name',
                DB::raw('COUNT(*) as attempts_count'),
                DB::raw("SUM(CASE WHEN attempts.status = 'SUCCESS' THEN 1 ELSE 0 END) as success_count"),
                DB::raw("SUM(CASE WHEN attempts.status IN ('UNKNOWN','FAILED_CONFIRMED','BLOCKED','MANUAL_FAILED') THEN 1 ELSE 0 END) as errors_count"),
                DB::raw("SUM(CASE WHEN attempts.status IN ('CREATED','SENDING','PENDING','UNKNOWN','BLOCKED','MANUAL_PENDING') THEN 1 ELSE 0 END) as pending_count"),
            ])
            ->orderByDesc('attempts_count')->get()
            ->map(function (object $row): array {
                $attempts = (int) $row->attempts_count;
                $errors = (int) $row->errors_count;

                return [
                    'id' => $row->id === null ? 0 : (int) $row->id,
                    'code' => (string) ($row->code ?: 'UNKNOWN'),
                    'name' => (string) ($row->display_name ?: $row->code ?: 'Provider tidak dikenal'),
                    'attempts_count' => $attempts,
                    'success_count' => (int) $row->success_count,
                    'errors_count' => $errors,
                    'pending_count' => (int) $row->pending_count,
                    'error_rate' => $attempts > 0 ? round(($errors / $attempts) * 100, 1) : 0.0,
                ];
            })->values()->all();
    }
}
