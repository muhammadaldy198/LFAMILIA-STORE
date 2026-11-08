<?php

namespace App\Http\Controllers;

use App\Models\PaymentChannel;
use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use App\Services\PaymentRouteCatalogService;
use App\Services\PaymentRoutingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengelola rute pembayaran dari panel admin: memilih gateway untuk
 * channel, mengubah rute dan gateway, dan menyinkronkan daftar channel
 * dari katalog rute. Channel-nya sendiri diurus
 * AdminPaymentChannelController.
 */
class AdminPaymentRouteController
{
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

    /**
     * Operator-owned gateway assignment. This is a deliberate selection,
     * never an automatic backup or provider failover.
     */
    public function selectGateway(Request $request, int $channelId, PaymentRoutingService $routing): RedirectResponse
    {
        // Alasan belum dipecah: satu urutan pemilihan gateway di dalam
        // transaksi — pasangan field gateway dipilih dan disimpan
        // sebagai satu keputusan; memecahnya berisiko keadaan setengah
        // terpilih bocor antar fungsi.
        $data = $request->validate([
            'route_id' => ['required', 'integer', 'min:1'],
            'purpose' => ['required', 'in:order,topup'],
        ]);

        $field = $data['purpose'] === 'order' ? 'supports_order' : 'supports_wallet_topup';
        $otherField = $data['purpose'] === 'order' ? 'supports_wallet_topup' : 'supports_order';

        $selectedGateway = DB::transaction(function () use ($channelId, $data, $field, $otherField, $routing): string {
            $channel = DB::table('payment_channels')->where('id', $channelId)->lockForUpdate()->first();
            abort_unless($channel, 404);
            if (! $channel->is_active || ! $channel->{$field}) {
                throw ValidationException::withMessages(['route_id' => 'Metode atau tujuan pembayaran belum aktif.']);
            }

            $routes = DB::table('payment_routes as routes')
                ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
                ->where('routes.payment_channel_id', $channelId)
                ->whereIn('gateways.code', ['MIDTRANS', 'DOKU'])
                ->orderBy('routes.id')
                ->lockForUpdate()
                ->get([
                    'routes.id',
                    'routes.is_active',
                    'routes.supports_order',
                    'routes.supports_wallet_topup',
                    'routes.configuration',
                    'gateways.code as gateway_code',
                    'gateways.is_active as gateway_active',
                    'gateways.is_maintenance as gateway_maintenance',
                ]);

            $selected = $routes->first(fn (object $route): bool => (int) $route->id === (int) $data['route_id']);
            if (! $selected || ! $selected->gateway_active || $selected->gateway_maintenance
                || ! $routing->gatewayReady((string) $selected->gateway_code)) {
                throw ValidationException::withMessages([
                    'route_id' => 'Gateway belum siap. Lengkapi integrasi dan aktifkan gateway terlebih dahulu.',
                ]);
            }

            if ($selected->gateway_code === 'DOKU') {
                $configuration = json_decode((string) $selected->configuration, true);
                $path = is_array($configuration) ? (string) ($configuration['api_path'] ?? '') : '';
                if ($path !== '/checkout/v1/payment') {
                    throw ValidationException::withMessages([
                        'route_id' => 'Kanal DOKU harus menggunakan DOKU Checkout yang didukung.',
                    ]);
                }
            }

            foreach ($routes as $route) {
                $chosen = (int) $route->id === (int) $selected->id;
                $update = [
                    $field => $chosen,
                    'is_active' => $chosen || ((bool) $route->is_active && (bool) $route->{$otherField}),
                    'updated_at' => now(),
                ];
                // A dormant route may be configured for both purposes. When
                // activating it for one purpose, never silently activate the
                // other purpose as well.
                if ($chosen && ! $route->is_active) {
                    $update[$otherField] = false;
                }
                DB::table('payment_routes')->where('id', $route->id)->update($update);
            }

            return (string) $selected->gateway_code;
        });

        $this->audit($request, 'payment.gateway.selected', 'payment_channel', $channelId, null, [
            'purpose' => $data['purpose'],
            'route_id' => (int) $data['route_id'],
            'gateway_code' => $selectedGateway,
        ]);

        return back()->with('status', 'Gateway pembayaran untuk metode ini berhasil dipilih.');
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
        if (array_key_exists('fee_flat_idr', $data)) {
            $after['fee_flat_idr'] = $data['fee_flat_idr'];
        }
        if (array_key_exists('fee_percent_bps', $data)) {
            $after['fee_percent_bps'] = $data['fee_percent_bps'];
        }
        DB::table('payment_routes')->where('id', $id)->update($after);
        $this->audit($request, 'payment.route.updated', 'payment_route', $id, (array) $before, $after);

        return back()->with('status', 'Routing pembayaran disimpan.');
    }

    public function syncChannels(Request $request, PaymentRouteCatalogService $routeCatalog): RedirectResponse
    {
        // Alasan belum dipecah: satu urutan sinkronisasi — katalog rute
        // dibaca, rute internal dipastikan ada, lalu channel disusun
        // dan dicatat dalam konteks hasil yang sama dari awal sampai
        // tanggapan.
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
                'category_code' => $this->defaultCategory((string) $preset['method']),
                'fee_flat_idr' => 0,
                'fee_percent_bps' => 0,
                'is_active' => false,
            ]);
            $created++;
        }

        $this->ensureInternalRoutes();
        $routes = $routeCatalog->sync();
        $totalCreated = $created + $routes['created_channels'];
        $this->audit($request, 'payment.channels.synced', 'payment_channel', 0, null, [
            'created_channels' => $totalCreated,
            'route_catalog' => $routes,
        ]);

        return back()->with('status', $totalCreated > 0 || $routes['created'] > 0
            ? $totalCreated.' metode dan '.$routes['created'].' routing bawaan ditambahkan. Channel bank mengikuti status VA yang sudah ada; gateway yang belum aktif tetap tidak tersedia.'
            : 'Metode dan routing bawaan sudah sinkron dengan source code.');
    }

    private function routeData(Request $request, int $channelId): array
    {
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
            'fee_flat_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'fee_percent_bps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'supports_order' => ['required', 'boolean'],
            'supports_wallet_topup' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['payment_channel_id'] = $channelId;

        $channel = DB::table('payment_channels')->where('id', $channelId)->first();
        if ($channel && ($channel->method === 'WALLET'
            || in_array($channel->code, ['saldo', 'manual_qris'], true))) {
            if (array_key_exists('fee_flat_idr', $data)) {
                $data['fee_flat_idr'] = 0;
            }
            if (array_key_exists('fee_percent_bps', $data)) {
                $data['fee_percent_bps'] = 0;
            }
        }

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

    private function defaultCategory(string $method): string
    {
        return match ($method) {
            'WALLET' => 'wallet', 'QRIS' => 'qris', 'EWALLET' => 'ewallet',
            'VIRTUAL_ACCOUNT' => 'va', 'RETAIL' => 'retail',
            default => 'other',
        };
    }

    private function audit(
        Request $request,
        string $action,
        string $type,
        string|int $id,
        ?array $before,
        array $after,
    ): void {
        app(AdminAuditService::class)->record($request, $action, $type, $id, $before, $after);
    }
}
