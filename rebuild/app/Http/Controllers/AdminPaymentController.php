<?php

namespace App\Http\Controllers;

use App\Models\PaymentChannel;
use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use App\Services\PaymentPageSettingsService;
use App\Services\PaymentRouteCatalogService;
use App\Services\PaymentRoutingService;
use App\Services\PaymentStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPaymentController
{
    public function index(Request $request, PaymentPageSettingsService $pageSettings, PaymentRoutingService $routing): Response
    {
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
                'active_route_count' => DB::table('payment_routes')
                    ->where('payment_gateway_id', $gateway->id)
                    ->where('is_active', true)->count(),
            ]);

        $channels = PaymentChannel::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (PaymentChannel $channel) use ($routing): array {
                $routes = DB::table('payment_routes as routes')
                    ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
                    ->where('routes.payment_channel_id', $channel->id)
                    ->orderBy('routes.priority')->orderBy('routes.id')
                    ->get([
                        'routes.id',
                        'routes.provider_channel',
                        'routes.priority',
                        'routes.supports_order',
                        'routes.supports_wallet_topup',
                        'routes.is_active',
                        'gateways.id as gateway_id',
                        'gateways.code as gateway_code',
                        'gateways.internal_name as gateway_name',
                        'gateways.is_active as gateway_active',
                        'gateways.is_maintenance as gateway_maintenance',
                    ]);

                return [
                    'id' => (int) $channel->id,
                    'code' => (string) $channel->code,
                    'method' => (string) $channel->method,
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
                    'available_order' => (bool) $channel->is_active && $routes->contains(
                        fn (object $route): bool => (bool) $route->is_active
                            && (bool) $route->supports_order
                            && (bool) $route->gateway_active
                            && ! (bool) $route->gateway_maintenance
                            && $routing->gatewayReady((string) $route->gateway_code)
                            && $routing->gatewayReady((string) $route->gateway_code)
                    ),
                    'available_topup' => (bool) $channel->is_active && $routes->contains(
                        fn (object $route): bool => (bool) $route->is_active
                            && (bool) $route->supports_wallet_topup
                            && (bool) $route->gateway_active
                            && ! (bool) $route->gateway_maintenance
                    ),
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
                'routes.supports_order',
                'routes.supports_wallet_topup',
                'routes.is_active',
                'channels.code as channel_code',
                'channels.name as channel_name',
                'gateways.code as gateway_code',
                'gateways.internal_name as gateway_name',
            ])->get()->map(fn (object $route): array => [
                ...((array) $route),
                'priority' => (int) $route->priority,
                'supports_order' => (bool) $route->supports_order,
                'supports_wallet_topup' => (bool) $route->supports_wallet_topup,
                'is_active' => (bool) $route->is_active,
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
            'refundReviews' => DB::table('wallet_topups as topups')
                ->join('wallets', 'wallets.id', '=', 'topups.wallet_id')
                ->join('users', 'users.id', '=', 'topups.user_id')
                ->where('topups.status', 'REFUND_REVIEW')
                ->orderBy('topups.created_at')
                ->limit(100)
                ->get([
                    'topups.id',
                    'topups.amount_idr',
                    'topups.total_idr',
                    'topups.created_at',
                    'users.id as user_id',
                    'users.name as customer_name',
                    'users.email as customer_email',
                    'wallets.balance_idr',
                ]),
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
            'callbackUrls' => [
                'midtrans' => rtrim((string) config('app.url'), '/').'/api/payments/midtrans/notification',
                'doku' => rtrim((string) config('app.url'), '/').'/api/payments/doku/notification',
            ],
        ]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $data = $this->channelData($request);
        $data['code'] = strtolower(trim($data['code']));

        $channel = PaymentChannel::create($data);
        $this->audit($request, 'payment.channel.created', 'payment_channel', $channel->id, null, $channel->toArray());

        return back()->with('status', 'Metode pembayaran ditambahkan.');
    }

    public function channel(Request $request, int $id): RedirectResponse
    {
        $channel = PaymentChannel::findOrFail($id);
        $data = $this->channelData($request, $channel->id);
        $data['code'] = strtolower(trim($data['code']));

        $before = $channel->toArray();
        $channel->update($data);
        $this->audit($request, 'payment.channel.updated', 'payment_channel', $channel->id, $before, $channel->fresh()->toArray());

        return back()->with('status', 'Metode pembayaran disimpan.');
    }

    public function destroyChannel(Request $request, int $id): RedirectResponse
    {
        $channel = PaymentChannel::findOrFail($id);

        $used = DB::table('orders')->where('payment_channel_id', $channel->id)->exists()
            || DB::table('wallet_topups')->where('payment_channel_id', $channel->id)->exists()
            || DB::table('payment_transactions')->where('channel_code', $channel->code)->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'channel' => 'Metode ini sudah memiliki riwayat transaksi. Nonaktifkan saja agar data lama tetap utuh.',
            ]);
        }

        $before = $channel->toArray();
        $channel->clearMediaCollection('logo');
        $channel->delete();
        $this->audit($request, 'payment.channel.deleted', 'payment_channel', $id, $before, []);

        return back()->with('status', 'Metode pembayaran dihapus.');
    }

    public function uploadChannelLogo(Request $request, int $id): RedirectResponse
    {
        $channel = PaymentChannel::findOrFail($id);
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $before = ['logo_url' => $channel->getFirstMediaUrl('logo')];
        $channel->clearMediaCollection('logo');
        $channel->addMediaFromRequest('image')
            ->toMediaCollection('logo', config('media-library.disk_name', 'public'));
        $after = ['logo_url' => $channel->getFirstMediaUrl('logo')];
        $this->audit($request, 'payment.channel.logo.updated', 'payment_channel', $channel->id, $before, $after);

        return back()->with('status', 'Logo metode pembayaran diperbarui.');
    }

    public function syncChannels(Request $request, PaymentRouteCatalogService $routeCatalog): RedirectResponse
    {
        $presets = [
            [
                'code' => 'qris',
                'method' => 'QRIS',
                'name' => 'QRIS',
                'description' => 'Scan QR menggunakan aplikasi pembayaran yang mendukung QRIS.',
                'supports_order' => true,
                'supports_wallet_topup' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'virtual_account',
                'method' => 'VIRTUAL_ACCOUNT',
                'name' => 'Virtual Account',
                'description' => 'Transfer melalui Virtual Account bank yang tersedia.',
                'supports_order' => true,
                'supports_wallet_topup' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'ewallet',
                'method' => 'EWALLET',
                'name' => 'E-Wallet',
                'description' => 'Bayar menggunakan dompet digital yang tersedia.',
                'supports_order' => true,
                'supports_wallet_topup' => true,
                'sort_order' => 30,
            ],
            [
                'code' => 'manual_qris',
                'method' => 'QRIS',
                'name' => 'QRIS Manual',
                'description' => 'Scan QRIS lalu tunggu konfirmasi pembayaran dari Admin.',
                'supports_order' => true,
                'supports_wallet_topup' => false,
                'sort_order' => 40,
            ],
            [
                'code' => 'saldo',
                'method' => 'WALLET',
                'name' => 'Saldo LFAMILIA',
                'description' => 'Bayar langsung menggunakan saldo akun LFAMILIA.',
                'supports_order' => true,
                'supports_wallet_topup' => false,
                'sort_order' => 50,
            ],
        ];

        $created = 0;
        foreach ($presets as $preset) {
            $existing = PaymentChannel::where('code', $preset['code'])->first();
            if ($existing) {
                continue;
            }
            PaymentChannel::create([
                ...$preset,
                'fee_flat_idr' => 0,
                'fee_percent_bps' => 0,
                'is_active' => false,
            ]);
            $created++;
        }

        $this->ensureInternalRoutes();
        $routes = $routeCatalog->sync();
        $this->audit($request, 'payment.channels.synced', 'payment_channel', 0, null, [
            'created_channels' => $created,
            'route_catalog' => $routes,
        ]);

        return back()->with('status', $created > 0 || $routes['created'] > 0
            ? $created.' metode dan '.$routes['created'].' routing bawaan ditambahkan dalam keadaan nonaktif.'
            : 'Metode dan routing bawaan sudah sinkron dengan source code.');
    }

    public function gateway(Request $request, int $id, PaymentRoutingService $routing): RedirectResponse
    {
        $data = $request->validate([
            'internal_name' => ['required', 'string', 'min:2', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'is_maintenance' => ['required', 'boolean'],
        ]);
        $before = DB::table('payment_gateways')->where('id', $id)->first();
        abort_unless($before, 404);

        if ($data['is_active']) {
            if (! $routing->gatewayReady((string) $before->code)) {
                throw ValidationException::withMessages([
                    'is_active' => 'Lengkapi dan aktifkan kredensial gateway di menu Integrasi terlebih dahulu.',
                ]);
            }
            if ($before->code === 'MANUAL_QRIS') {
                $asset = StoreAsset::where('key', 'manual_qris')->where('is_active', true)->first();
                if (! $asset || ! $asset->getFirstMedia('image')) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Unggah dan aktifkan gambar QRIS manual terlebih dahulu.',
                    ]);
                }
            }
        }

        DB::table('payment_gateways')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->audit($request, 'payment.gateway.updated', 'payment_gateway', $id, (array) $before, $data);

        return back()->with('status', 'Pengaturan gateway disimpan.');
    }

    public function updateRoute(Request $request, int $id): RedirectResponse
    {
        $before = DB::table('payment_routes')->where('id', $id)->first();
        abort_unless($before, 404);

        $data = $this->routeData($request, (int) $before->payment_channel_id);
        $this->validateRoutePurpose($data);
        $after = [
            'priority' => $data['priority'],
            'supports_order' => $data['supports_order'],
            'supports_wallet_topup' => $data['supports_wallet_topup'],
            'is_active' => $data['is_active'],
            'updated_at' => now(),
        ];
        DB::table('payment_routes')->where('id', $id)->update($after);
        $this->audit($request, 'payment.route.updated', 'payment_route', $id, (array) $before, $after);

        return back()->with('status', 'Routing pembayaran disimpan.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'minimum_topup_idr' => ['required', 'integer', 'min:1', 'max:100000000'],
            'topup_enabled' => ['required', 'boolean'],
        ]);

        $before = [
            'minimum_topup_idr' => $this->minimumTopup(),
            'topup_enabled' => $this->topupEnabled(),
        ];

        DB::transaction(function () use ($request, $data): void {
            foreach ([
                'wallet.minimum_topup_idr' => $data['minimum_topup_idr'],
                'wallet.topup_enabled' => $data['topup_enabled'],
            ] as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    [
                        'value' => json_encode($value),
                        'updated_by_admin_id' => $request->user('admin')->id,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });

        $this->audit($request, 'payment.settings.updated', 'system_setting', 0, $before, $data);

        return back()->with('status', 'Pengaturan top up saldo disimpan.');
    }

    public function pageSettings(
        Request $request,
        PaymentPageSettingsService $settings,
    ): RedirectResponse {
        $data = $request->validate([
            'accentColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'headerImageUrl' => ['nullable', 'string', 'max:500'],
            'eyebrow' => ['required', 'string', 'min:1', 'max:60'],
            'pendingTitle' => ['required', 'string', 'min:2', 'max:100'],
            'paidTitle' => ['required', 'string', 'min:2', 'max:100'],
            'failedTitle' => ['required', 'string', 'min:2', 'max:100'],
            'subtitle' => ['nullable', 'string', 'max:240'],
            'invoiceNoticeTitle' => ['required', 'string', 'min:2', 'max:120'],
            'invoiceNoticeText' => ['nullable', 'string', 'max:500'],
            'pendingStatusText' => ['nullable', 'string', 'max:300'],
            'paidStatusText' => ['nullable', 'string', 'max:300'],
            'failedStatusText' => ['nullable', 'string', 'max:300'],
            'payButtonText' => ['required', 'string', 'min:1', 'max:80'],
            'checkStatusButtonText' => ['required', 'string', 'min:1', 'max:80'],
            'checkInvoiceButtonText' => ['required', 'string', 'min:1', 'max:80'],
            'supportText' => ['nullable', 'string', 'max:120'],
            'supportUrl' => ['nullable', 'string', 'max:500', 'regex:/^(\/(?!\/)|https?:\/\/)/i'],
            'showStoreBrand' => ['required', 'boolean'],
            'showInvoiceNotice' => ['required', 'boolean'],
            'showOrderSummary' => ['required', 'boolean'],
            'showStatusBox' => ['required', 'boolean'],
            'showSupport' => ['required', 'boolean'],
        ]);
        $before = $settings->read();
        $settings->write($data, $request->user('admin')->id);
        $this->audit($request, 'payment.page.updated', 'system_setting', 0, $before, $data);

        return back()->with('status', 'Tampilan halaman pembayaran disimpan.');
    }

    public function uploadPageHeader(
        Request $request,
        PaymentPageSettingsService $settings,
    ): RedirectResponse {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $asset = StoreAsset::where('key', 'payment_header')->first();
        if (! $asset) {
            $id = DB::table('store_assets')->insertGetId([
                'key' => 'payment_header',
                'target_url' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $asset = StoreAsset::findOrFail($id);
        }

        $before = $settings->read();
        $asset->clearMediaCollection('image');
        $asset->addMediaFromRequest('image')
            ->toMediaCollection('image', config('media-library.disk_name', 'public'));
        $asset->forceFill(['is_active' => true])->save();

        $after = [
            ...$before,
            'headerImageUrl' => $asset->getFirstMediaUrl('image'),
        ];
        $settings->write($after, $request->user('admin')->id);
        $this->audit($request, 'payment.page.header.updated', 'store_asset', $asset->id, $before, $after);

        return back()->with('status', 'Banner pembayaran diperbarui.');
    }

    public function uploadManualQris(Request $request): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $asset = StoreAsset::where('key', 'manual_qris')->first();
        if (! $asset) {
            $id = DB::table('store_assets')->insertGetId([
                'key' => 'manual_qris',
                'target_url' => null,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $asset = StoreAsset::findOrFail($id);
        }

        $before = ['image_url' => $asset->getFirstMediaUrl('image')];
        $asset->clearMediaCollection('image');
        $asset->addMediaFromRequest('image')
            ->toMediaCollection('image', config('media-library.disk_name', 'public'));
        $after = ['image_url' => $asset->getFirstMediaUrl('image')];
        $this->audit($request, 'payment.manual_qris.image.updated', 'store_asset', $asset->id, $before, $after);

        return back()->with('status', 'Gambar QRIS manual diperbarui.');
    }

    public function toggleManualQris(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $asset = StoreAsset::where('key', 'manual_qris')->firstOrFail();
        if ($data['is_active'] && ! $asset->getFirstMedia('image')) {
            throw ValidationException::withMessages([
                'manual_qris' => 'Unggah gambar QRIS terlebih dahulu.',
            ]);
        }

        $before = ['is_active' => (bool) $asset->is_active];
        $asset->forceFill(['is_active' => (bool) $data['is_active']])->save();
        if (! $data['is_active']) {
            DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }
        $this->audit($request, 'payment.manual_qris.updated', 'store_asset', $asset->id, $before, $data);

        return back()->with('status', $data['is_active'] ? 'QRIS manual diaktifkan.' : 'QRIS manual dinonaktifkan.');
    }

    public function resolveTopupRefund(
        Request $request,
        int $topupId,
        PaymentStateService $states,
    ): RedirectResponse {
        $before = DB::table('wallet_topups')->where('id', $topupId)->first();
        abort_unless($before, 404);

        $result = $states->resolveTopupRefundReview(
            $topupId,
            (int) $request->user('admin')->id
        );
        $after = DB::table('wallet_topups')->where('id', $topupId)->first();

        $this->audit(
            $request,
            'wallet.topup_refund.resolved',
            'wallet_topup',
            $topupId,
            (array) $before,
            $after ? (array) $after : $result
        );

        return back()->with('status', 'Refund top up telah direkonsiliasi.');
    }

    public function confirmManual(Request $request, int $paymentId, PaymentStateService $states): RedirectResponse
    {
        $payment = DB::table('payment_transactions')->where('id', $paymentId)->firstOrFail();
        if ($payment->gateway_code !== 'MANUAL_QRIS') {
            throw ValidationException::withMessages(['payment' => 'Transaksi bukan pembayaran QRIS manual.']);
        }

        $before = (array) $payment;
        $result = $states->apply($paymentId, 'PAID', [
            'source' => 'manual_confirmation',
            'admin_id' => $request->user('admin')->id,
        ]);
        $this->audit($request, 'payment.manual.confirmed', 'payment_transaction', $paymentId, $before, $result);

        return back()->with('status', 'Pembayaran manual dikonfirmasi.');
    }

    private function channelData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('payment_channels', 'code')->ignore($ignoreId),
            ],
            'method' => ['required', Rule::in(['QRIS', 'VIRTUAL_ACCOUNT', 'EWALLET', 'RETAIL', 'WALLET', 'OTHER'])],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:160'],
            'fee_flat_idr' => ['required', 'integer', 'min:0', 'max:100000000'],
            'fee_percent_bps' => ['required', 'integer', 'min:0', 'max:9999'],
            'supports_order' => ['required', 'boolean'],
            'supports_wallet_topup' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function routeData(Request $request, int $channelId): array
    {
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
            'supports_order' => ['required', 'boolean'],
            'supports_wallet_topup' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['payment_channel_id'] = $channelId;

        return $data;
    }

    private function validateRoutePurpose(array $data): void
    {
        if ($data['is_active'] && ! $data['supports_order'] && ! $data['supports_wallet_topup']) {
            throw ValidationException::withMessages([
                'route' => 'Routing aktif harus dipakai untuk pesanan, top up saldo, atau keduanya.',
            ]);
        }

        $channel = DB::table('payment_channels')->where('id', $data['payment_channel_id'])->first();
        abort_unless($channel, 404);

        if ($data['supports_order'] && ! $channel->supports_order) {
            throw ValidationException::withMessages([
                'supports_order' => 'Metode ini tidak diaktifkan untuk pembayaran pesanan.',
            ]);
        }
        if ($data['supports_wallet_topup'] && ! $channel->supports_wallet_topup) {
            throw ValidationException::withMessages([
                'supports_wallet_topup' => 'Metode ini tidak diaktifkan untuk top up saldo.',
            ]);
        }
    }

    private function transactionQuery(array $filters)
    {
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

    private function ensureInternalRoutes(): void
    {
        foreach ([
            ['channel' => 'manual_qris', 'gateway' => 'MANUAL_QRIS'],
            ['channel' => 'saldo', 'gateway' => 'WALLET'],
        ] as $pair) {
            $channel = DB::table('payment_channels')->where('code', $pair['channel'])->first();
            $gateway = DB::table('payment_gateways')->where('code', $pair['gateway'])->first();
            if (! $channel || ! $gateway) {
                continue;
            }

            DB::table('payment_routes')->updateOrInsert(
                [
                    'payment_channel_id' => $channel->id,
                    'payment_gateway_id' => $gateway->id,
                ],
                [
                    'priority' => 0,
                    'supports_order' => true,
                    'supports_wallet_topup' => false,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
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

    private function audit(
        Request $request,
        string $action,
        string $type,
        int $id,
        ?array $before,
        array $after,
    ): void {
        app(AdminAuditService::class)->record($request, $action, $type, $id, $before, $after);
    }
}
