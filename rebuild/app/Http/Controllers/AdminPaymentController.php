<?php

namespace App\Http\Controllers;

use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\StoreAsset;
use App\Services\PaymentPageSettingsService;
use App\Services\PaymentRoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman pembayaran admin: daftar channel, gateway, dan rute pembayaran
 * beserta status kesiapan dan kesehatannya, dan ringkasan transaksi
 * terakhir. Mengubah kategori diurus AdminPaymentCategoryController,
 * channel oleh AdminPaymentChannelController, rute oleh
 * AdminPaymentRouteController, tampilan halaman bayar oleh
 * AdminPaymentPageController, dan QRIS manual oleh
 * AdminPaymentManualController.
 */
class AdminPaymentController
{
    public function index(Request $request, PaymentPageSettingsService $pageSettings, PaymentRoutingService $routing): Response
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu lintasan baca-tampil — daftar channel dibaca sekali, lalu
        // kesiapan, rute, dan ringkasan transaksinya disusun dalam konteks
        // yang sama untuk satu halaman; menyebar langkahnya akan memecah
        // definisi satu halaman menjadi berkeping-keping. Penilaiannya
        // sendiri sudah dipecah ke fungsi-fungsi di bawah.
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                'CREATING', 'SENDING', 'PENDING', 'UNKNOWN', 'PAID', 'FAILED', 'EXPIRED',
                'CANCELLED', 'REJECTED', 'REFUNDED',
            ])],
            'source' => ['nullable', Rule::in(['order', 'topup'])],
            'channel' => ['nullable', 'integer', Rule::exists('payment_channels', 'id')],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $filters = [
            'q' => mb_substr(trim((string) ($filters['q'] ?? '')), 0, 100),
            'status' => (string) ($filters['status'] ?? ''),
            'source' => (string) ($filters['source'] ?? ''),
            'channel' => (int) ($filters['channel'] ?? 0),
            'per_page' => (int) ($filters['per_page'] ?? 25),
        ];

        // Load route details once for all gateways/channels, rather than one
        // additional database query per gateway and per channel.
        $routeDetails = DB::table('payment_routes as routes')
            ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->orderBy('routes.priority')->orderBy('routes.id')
            ->get([
                'routes.id',
                'routes.payment_channel_id',
                'routes.payment_gateway_id',
                'routes.provider_channel',
                'routes.configuration',
                'routes.priority',
                'routes.fee_flat_idr',
                'routes.fee_percent_bps',
                'routes.supports_order',
                'routes.supports_wallet_topup',
                'routes.is_active',
                'gateways.id as gateway_id',
                'gateways.code as gateway_code',
                'gateways.internal_name as gateway_name',
                'gateways.is_active as gateway_active',
                'gateways.is_maintenance as gateway_maintenance',
            ]);
        $routesByChannel = $routeDetails->groupBy('payment_channel_id');
        $activeRoutesByGateway = $routeDetails->where('is_active', true)
            ->countBy('payment_gateway_id');

        $gateways = DB::table('payment_gateways')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (object $gateway): array => [
                'id' => (int) $gateway->id,
                'code' => (string) $gateway->code,
                'internal_name' => (string) $gateway->internal_name,
                'kind' => (string) $gateway->kind,
                'sort_order' => (int) $gateway->sort_order,
                'is_active' => (bool) $gateway->is_active,
                'is_maintenance' => (bool) $gateway->is_maintenance,
                'credential_configured' => $routing->gatewayReady((string) $gateway->code),
                'health' => $this->gatewayHealth((string) $gateway->code),
                'active_route_count' => (int) ($activeRoutesByGateway[$gateway->id] ?? 0),
            ]);

        $channels = PaymentChannel::query()->with('category')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (PaymentChannel $channel) use ($routing, $routesByChannel): array {
                $routes = $routesByChannel->get($channel->id, collect());
                $orderReadiness = $this->paymentReadiness($channel, $routes, 'supports_order', $routing);
                $topupReadiness = $this->paymentReadiness($channel, $routes, 'supports_wallet_topup', $routing);

                return [
                    'id' => (int) $channel->id,
                    'code' => (string) $channel->code,
                    'method' => (string) $channel->method,
                    'category_code' => (string) $channel->category_code,
                    'category_name' => $channel->category?->name ?: 'Lainnya',
                    'category_active' => (bool) $channel->category?->is_active,
                    'name' => (string) $channel->name,
                    'description' => $channel->description,
                    'logo_url' => $channel->getFirstMediaUrl('logo'),
                    'fee_flat_idr' => (int) $channel->fee_flat_idr,
                    'fee_percent_bps' => (int) $channel->fee_percent_bps,
                    'supports_order' => (bool) $channel->supports_order,
                    'supports_wallet_topup' => (bool) $channel->supports_wallet_topup,
                    'sort_order' => (int) $channel->sort_order,
                    'is_active' => (bool) $channel->is_active,
                    'route_count' => $routes->count(),
                    'active_route_count' => $routes->where('is_active', true)->count(),
                    'available_order' => $orderReadiness['ready'],
                    'available_topup' => $topupReadiness['ready'],
                    'order_readiness_reason' => $orderReadiness['reason'],
                    'topup_readiness_reason' => $topupReadiness['reason'],
                ];
            })->values();

        $routes = DB::table('payment_routes as routes')
            ->join('payment_channels as channels', 'channels.id', '=', 'routes.payment_channel_id')
            ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->orderBy('channels.sort_order')->orderBy('routes.priority')->orderBy('routes.id')
            ->select([
                'routes.id',
                'routes.payment_channel_id',
                'routes.payment_gateway_id',
                'routes.priority',
                'routes.fee_flat_idr',
                'routes.fee_percent_bps',
                'routes.supports_order',
                'routes.supports_wallet_topup',
                'routes.is_active',
                'channels.code as channel_code',
                'channels.name as channel_name',
                'channels.method as channel_method',
                'channels.fee_flat_idr as channel_fee_flat_idr',
                'channels.fee_percent_bps as channel_fee_percent_bps',
                'gateways.code as gateway_code',
                'gateways.internal_name as gateway_name',
            ])->get()->map(fn (object $route): array => [
                ...((array) $route),
                'priority' => (int) $route->priority,
                'fee_flat_idr' => $route->fee_flat_idr !== null ? (int) $route->fee_flat_idr : null,
                'fee_percent_bps' => $route->fee_percent_bps !== null ? (int) $route->fee_percent_bps : null,
                'channel_fee_flat_idr' => (int) $route->channel_fee_flat_idr,
                'channel_fee_percent_bps' => (int) $route->channel_fee_percent_bps,
                'supports_order' => (bool) $route->supports_order,
                'supports_wallet_topup' => (bool) $route->supports_wallet_topup,
                'is_active' => (bool) $route->is_active,
                // Admin selects only supported external payment providers.
                'selectable' => in_array($route->gateway_code, ['MIDTRANS', 'DOKU'], true),
            ]);

        $transactions = $this->transactionQuery($filters)
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (object $payment): array => [
                'id' => (int) $payment->id,
                'order_id' => $payment->order_id ? (int) $payment->order_id : null,
                'wallet_topup_id' => $payment->wallet_topup_id ? (int) $payment->wallet_topup_id : null,
                'order_number' => $payment->order_number,
                'merchant_reference' => $payment->merchant_reference,
                'external_reference' => $payment->external_reference,
                'amount_idr' => (int) $payment->amount_idr,
                'status' => (string) $payment->status,
                'channel_code' => (string) $payment->channel_code,
                'channel_name' => $payment->channel_name ?: $payment->channel_code,
                'gateway_code' => (string) $payment->gateway_code,
                'gateway_name' => $payment->gateway_name ?: $payment->gateway_code,
                'customer_name' => $payment->customer_name ?: 'Guest',
                'customer_email' => $payment->customer_email,
                'customer_phone' => $payment->customer_phone,
                'verified_at' => $payment->verified_at,
                'expires_at' => $payment->expires_at,
                'created_at' => $payment->created_at,
                'callback_count' => (int) $payment->callback_count,
                'source' => $payment->order_id ? 'ORDER' : 'TOPUP',
            ]);

        $summaryBase = DB::table('payment_transactions');
        $total = (clone $summaryBase)->count();
        $paid = (clone $summaryBase)->where('status', 'PAID');
        $manualAsset = StoreAsset::where('key', 'manual_qris')->first();

        return Inertia::render('Admin/Payments', [
            'isSuperAdmin' => $request->user('admin')?->role === 'SUPER_ADMIN',
            'gateways' => $gateways,
            'channels' => $channels,
            'categories' => PaymentCategory::query()->withCount('channels')
                ->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (PaymentCategory $category): array => [
                    'code' => $category->code,
                    'name' => $category->name,
                    'description' => $category->description,
                    'sort_order' => $category->sort_order,
                    'is_active' => $category->is_active,
                    'channels_count' => $category->channels_count,
                ]),
            'routes' => $routes,
            'transactions' => $transactions,
            'filters' => $filters,
            'summary' => [
                'total' => $total,
                'paid' => (clone $paid)->count(),
                'paid_amount_idr' => (int) ((clone $paid)->sum('amount_idr') ?? 0),
                'pending' => (clone $summaryBase)->whereIn('status', ['CREATING', 'SENDING', 'PENDING', 'UNKNOWN'])->count(),
                'failed' => (clone $summaryBase)->whereIn('status', ['FAILED', 'EXPIRED', 'CANCELLED', 'REJECTED'])->count(),
                'refunded' => (clone $summaryBase)->where('status', 'REFUNDED')->count(),
            ],
            'walletSettings' => [
                'minimum_topup_idr' => $this->minimumTopup(),
                'topup_enabled' => $this->topupEnabled(),
            ],
            'pageSettings' => $pageSettings->read(),
            'manualQrisAsset' => $manualAsset ? [
                'id' => (int) $manualAsset->id,
                'is_active' => (bool) $manualAsset->is_active,
                'image_url' => $manualAsset->getFirstMediaUrl('image'),
            ] : null,
            'manualPayments' => DB::table('payment_transactions as payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->where('payments.gateway_code', 'MANUAL_QRIS')
                ->whereIn('payments.status', ['CREATING', 'PENDING'])
                ->orderByDesc('payments.id')
                ->limit(50)
                ->get([
                    'payments.id', 'payments.status', 'payments.amount_idr',
                    'payments.created_at', 'orders.order_number',
                ]),
            'refundReviews' => DB::table('wallet_topups as topups')
                ->join('users', 'users.id', '=', 'topups.user_id')
                ->where('topups.status', 'REFUND_REVIEW')
                ->orderBy('topups.id')
                ->limit(50)
                ->get([
                    'topups.id', 'topups.amount_idr', 'topups.created_at',
                    'users.name as customer_name', 'users.email as customer_email',
                ]),
            'providerMethods' => $this->providerMethodChoices(),
            'callbackUrls' => [
                'midtrans' => rtrim((string) config('app.url'), '/').'/api/payments/midtrans/notification',
                'doku' => rtrim((string) config('app.url'), '/').'/api/payments/doku/notification',
            ],
        ]);
    }

    /**
     * Present provider API codes from the single source-controlled protocol
     * catalog. Labels may be edited by the merchant; codes are provider owned.
     *
     * @return array<int, array{gateway:string,method:string,code:string,label:string}>
     */
    private function providerMethodChoices(): array
    {
        $choices = [
            ['gateway' => 'MIDTRANS', 'method' => 'QRIS', 'code' => 'other_qris', 'label' => 'QRIS Otomatis'],
            ['gateway' => 'DOKU', 'method' => 'QRIS', 'code' => 'QRIS', 'label' => 'QRIS Otomatis'],
        ];
        foreach ([
            'payment_bank_channels' => 'VIRTUAL_ACCOUNT',
            'payment_ewallet_channels' => 'EWALLET',
            'payment_retail_channels' => 'RETAIL',
        ] as $catalog => $method) {
            foreach ((array) config($catalog, []) as $entry) {
                foreach (['midtrans' => 'MIDTRANS', 'doku' => 'DOKU'] as $key => $gateway) {
                    if (empty($entry[$key]) || ($gateway === 'DOKU' && ! empty($entry['requires_integration']))) {
                        continue;
                    }
                    $choices[] = [
                        'gateway' => $gateway, 'method' => $method,
                        'code' => (string) $entry[$key], 'label' => (string) $entry['name'],
                    ];
                }
            }
        }

        return $choices;
    }

    /**
     * Match the public router: each channel and purpose must have exactly one
     * active route, and that route's gateway must be usable.
     *
     * @return array{ready:bool,reason:?string}
     */
    private function paymentReadiness(PaymentChannel $channel, $routes, string $purpose, PaymentRoutingService $routing): array
    {
        // Alasan belum dipecah: satu penilaian kesiapan — syarat channel,
        // rute, dan tujuannya diperiksa lalu disimpulkan menjadi satu
        // status; membacanya setengah-setengah menghilangkan makna
        // kesimpulannya.
        if (! $channel->is_active) {
            return ['ready' => false, 'reason' => 'Metode pembayaran nonaktif.'];
        }
        if (! $channel->category?->is_active) {
            return ['ready' => false, 'reason' => 'Kategori pembayaran nonaktif.'];
        }
        if (! $channel->{$purpose}) {
            return ['ready' => false, 'reason' => 'Metode tidak diaktifkan untuk tujuan transaksi ini.'];
        }

        $selected = $routes->filter(
            fn (object $route): bool => (bool) $route->is_active && (bool) $route->{$purpose}
        )->values();
        if ($selected->isEmpty()) {
            return ['ready' => false, 'reason' => $routes->isEmpty()
                ? 'Belum ada koneksi gateway untuk metode ini. Gunakan metode bawaan yang telah terintegrasi.'
                : 'Belum ada gateway dipilih. Pilih gateway untuk tujuan ini di kolom Gateway terpilih.'];
        }
        if ($selected->count() > 1) {
            return ['ready' => false, 'reason' => 'Lebih dari satu gateway aktif. Pilih tepat satu gateway untuk tujuan ini.'];
        }

        $route = $selected->first();
        if (! $route->gateway_active) {
            return ['ready' => false, 'reason' => 'Gateway belum diaktifkan pada pengaturan Gateway.'];
        }
        if ($route->gateway_maintenance) {
            return ['ready' => false, 'reason' => 'Gateway sedang dalam mode pemeliharaan.'];
        }
        if (! $routing->gatewayReady((string) $route->gateway_code)) {
            return ['ready' => false, 'reason' => 'Kredensial gateway belum siap. Periksa menu Integrasi.'];
        }
        if ((string) $route->gateway_code === 'MANUAL_QRIS') {
            $asset = StoreAsset::where('key', 'manual_qris')->where('is_active', true)->first();
            if (! $asset || ! $asset->getFirstMedia('image')) {
                return ['ready' => false, 'reason' => 'Gambar QRIS Manual belum diunggah atau belum aktif.'];
            }
        }
        if ((string) $route->gateway_code === 'DOKU') {
            $settings = json_decode((string) $route->configuration, true);
            if (! is_array($settings) || ($settings['api_path'] ?? '') !== '/checkout/v1/payment') {
                return ['ready' => false, 'reason' => 'Route DOKU lama tidak didukung. Pulihkan ke DOKU Checkout dahulu.'];
            }
        }

        return ['ready' => true, 'reason' => null];
    }

    private function transactionQuery(array $filters)
    {
        // Alasan belum dipecah: satu definisi query transaksi — gabungan
        // tabel dan syarat saringannya yang bersarang membentuk satu
        // makna daftar transaksi; memisahkannya membuat definisinya
        // terbaca setengah-setengah.
        return DB::table('payment_transactions as payments')
            ->leftJoin('orders', 'orders.id', '=', 'payments.order_id')
            ->leftJoin('wallet_topups as topups', 'topups.id', '=', 'payments.wallet_topup_id')
            ->leftJoin('users as order_users', 'order_users.id', '=', 'orders.user_id')
            ->leftJoin('users as topup_users', 'topup_users.id', '=', 'topups.user_id')
            ->leftJoin('payment_routes as routes', 'routes.id', '=', 'payments.payment_route_id')
            ->leftJoin('payment_channels as channels', 'channels.id', '=', 'routes.payment_channel_id')
            ->leftJoin('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('orders.order_number', 'like', $like)
                        ->orWhere('payments.merchant_reference', 'like', $like)
                        ->orWhere('payments.external_reference', 'like', $like)
                        ->orWhere('order_users.name', 'like', $like)
                        ->orWhere('order_users.email', 'like', $like)
                        ->orWhere('orders.guest_email', 'like', $like)
                        ->orWhere('orders.guest_phone', 'like', $like)
                        ->orWhere('topup_users.name', 'like', $like)
                        ->orWhere('topup_users.email', 'like', $like);
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('payments.status', $filters['status']))
            ->when($filters['source'] === 'order', fn ($query) => $query->whereNotNull('payments.order_id'))
            ->when($filters['source'] === 'topup', fn ($query) => $query->whereNotNull('payments.wallet_topup_id'))
            ->when($filters['channel'] > 0, fn ($query) => $query->where('routes.payment_channel_id', $filters['channel']))
            ->orderByDesc('payments.id')
            ->select([
                'payments.id',
                'payments.order_id',
                'payments.wallet_topup_id',
                'payments.merchant_reference',
                'payments.external_reference',
                'payments.amount_idr',
                'payments.status',
                'payments.channel_code',
                'payments.gateway_code',
                'payments.verified_at',
                'payments.expires_at',
                'payments.created_at',
                'orders.order_number',
                'channels.name as channel_name',
                'gateways.internal_name as gateway_name',
                DB::raw("COALESCE(order_users.name, topup_users.name, 'Guest') as customer_name"),
                DB::raw('COALESCE(order_users.email, orders.guest_email, topup_users.email) as customer_email'),
                DB::raw('COALESCE(order_users.phone, orders.guest_phone, topup_users.phone) as customer_phone'),
                DB::raw('(SELECT COUNT(*) FROM payment_callbacks callbacks WHERE callbacks.payment_transaction_id = payments.id) as callback_count'),
            ]);
    }

    private function gatewayHealth(string $code): array
    {
        if (! in_array($code, ['MIDTRANS', 'DOKU'], true)) {
            return ['status' => 'INTERNAL', 'message' => 'Diproses oleh sistem internal LFAMILIA.'];
        }

        $routing = app(PaymentRoutingService::class);
        if (! $routing->gatewayReady($code)) {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Kredensial belum lengkap/aktif di menu Integrasi.'];
        }

        $key = $code === 'MIDTRANS' ? 'midtrans' : 'doku';
        $stored = DB::table('system_settings')->where('key', 'integration.health.'.$key)->value('value');
        $health = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
        $status = strtoupper((string) ($health['status'] ?? 'UNTESTED'));

        return [
            'status' => $status,
            'message' => match ($status) {
                'HEALTHY' => 'Tes koneksi terakhir berhasil.',
                'DOWN' => 'Tes koneksi terakhir bermasalah.',
                'STALE' => 'Status koneksi perlu diuji ulang.',
                default => 'Koneksi belum pernah diuji.',
            },
            'tested_at' => $health['tested_at'] ?? null,
        ];
    }

    private function minimumTopup(): int
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->value('value');

        return max(1, (int) json_decode((string) $raw, true));
    }

    private function topupEnabled(): bool
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.topup_enabled')->value('value');

        return $raw === null ? true : (bool) json_decode((string) $raw, true);
    }
}
