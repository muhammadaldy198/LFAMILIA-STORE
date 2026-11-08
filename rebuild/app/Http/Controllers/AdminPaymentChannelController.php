<?php

namespace App\Http\Controllers;

use App\Models\PaymentChannel;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mengelola channel pembayaran dari panel admin: membuat, mengubah,
 * menghapus, dan memasang logo channel, termasuk konfigurasi metode
 * per gateway-nya. Kategori channel diurus
 * AdminPaymentCategoryController; rute antar gateway diurus
 * AdminPaymentRouteController.
 */
class AdminPaymentChannelController
{
    public function storeChannel(Request $request): RedirectResponse
    {
        $data = $this->channelData($request);
        $data['code'] = strtolower(trim($data['code']));
        $this->applyCategory($request, $data);
        $connection = $request->validate([
            'gateway_code' => ['required', Rule::in(['MIDTRANS', 'DOKU'])],
            'provider_method' => ['required', 'string', 'min:2', 'max:80'],
        ]);
        $configuration = $this->providerMethodConfiguration(
            (string) $data['method'], (string) $connection['gateway_code'], (string) $connection['provider_method']
        );

        DB::transaction(function () use ($request, $data, $connection, $configuration): void {
            $gateway = DB::table('payment_gateways')
                ->where('code', $connection['gateway_code'])->firstOrFail();

            $channel = PaymentChannel::create($data);
            DB::table('payment_routes')->insert([
                'payment_channel_id' => $channel->id,
                'payment_gateway_id' => $gateway->id,
                'provider_channel' => $connection['gateway_code'] === 'MIDTRANS'
                    ? $connection['provider_method'] : 'DOKU_CHECKOUT',
                'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                'priority' => 10,
                'supports_order' => (bool) $data['supports_order'],
                'supports_wallet_topup' => (bool) $data['supports_wallet_topup'],
                'is_active' => (bool) $data['is_active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'payment.channel.created', 'payment_channel', $channel->id, null, [
                'channel' => $channel->toArray(),
                'gateway' => $connection['gateway_code'],
                'provider_method' => $connection['provider_method'],
            ]);
        });

        return back()->with('status', 'Metode dan koneksi gateway dibuat. Periksa kesiapan penyedia sebelum digunakan.');
    }

    public function channel(Request $request, int $id): RedirectResponse
    {
        // Alasan belum dipecah: satu urutan simpan yang transaksional —
        // validasi, penerapan konfigurasi, penyimpanan di dalam
        // transaksi, dan audit berbagi konteks channel yang sama dan
        // harus berhenti utuh bila satu langkah gagal.
        $channel = PaymentChannel::findOrFail($id);
        $data = $this->channelData($request, $channel->id);
        $data['code'] = strtolower(trim($data['code']));
        $this->applyCategory($request, $data, $channel);

        if ($channel->method !== $data['method']
            && DB::table('payment_routes')->where('payment_channel_id', $channel->id)->exists()) {
            throw ValidationException::withMessages([
                'method' => 'Jenis metode dengan route yang sudah terhubung tidak boleh diubah. Buat pilihan baru dengan kode penyedia yang sesuai.',
            ]);
        }

        $hasRoute = DB::table('payment_routes')->where('payment_channel_id', $channel->id)->exists();
        $connection = null;
        $configuration = null;
        if (! $hasRoute && ($request->filled('gateway_code') || $data['is_active'])) {
            $connection = $request->validate([
                'gateway_code' => ['required', Rule::in(['MIDTRANS', 'DOKU'])],
                'provider_method' => ['required', 'string', 'min:2', 'max:80'],
            ]);
            $configuration = $this->providerMethodConfiguration(
                (string) $data['method'], (string) $connection['gateway_code'],
                (string) $connection['provider_method']
            );
        }

        $before = $channel->toArray();
        DB::transaction(function () use ($channel, $data, $connection, $configuration): void {
            $channel->update($data);
            if ($connection !== null) {
                $gatewayId = DB::table('payment_gateways')
                    ->where('code', $connection['gateway_code'])->value('id');
                if (! $gatewayId) {
                    throw ValidationException::withMessages(['gateway_code' => 'Gateway tidak ditemukan.']);
                }
                DB::table('payment_routes')->insert([
                    'payment_channel_id' => $channel->id,
                    'payment_gateway_id' => $gatewayId,
                    'provider_channel' => $connection['gateway_code'] === 'MIDTRANS'
                        ? $connection['provider_method'] : 'DOKU_CHECKOUT',
                    'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                    'priority' => 10,
                    'supports_order' => (bool) $data['supports_order'],
                    'supports_wallet_topup' => (bool) $data['supports_wallet_topup'],
                    'is_active' => (bool) $data['is_active'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
        $this->audit($request, 'payment.channel.updated', 'payment_channel', $channel->id, $before, [
            'channel' => $channel->fresh()->toArray(),
            'gateway_attached' => $connection['gateway_code'] ?? null,
        ]);

        return back()->with('status', $connection === null
            ? 'Metode pembayaran disimpan.' : 'Metode terhubung dengan route gateway. Periksa status kesiapan.');
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

    private function channelData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
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

        // Internal wallet and manual QRIS are fee-free; automatic QRIS is
        // configurable like other externally processed payment methods.
        if ($data['method'] === 'WALLET'
            || in_array($data['code'], ['saldo', 'manual_qris'], true)) {
            $data['fee_flat_idr'] = 0;
            $data['fee_percent_bps'] = 0;
        }

        return $data;
    }

    private function applyCategory(Request $request, array &$data, ?PaymentChannel $channel = null): void
    {
        $requested = $request->validate([
            'category_code' => ['sometimes', 'required', 'string', 'max:40', Rule::exists('payment_categories', 'code')],
        ]);
        $category = $requested['category_code'] ?? ($channel?->category_code ?: $this->defaultCategory($data['method']));
        if (($data['method'] === 'WALLET' && $category !== 'wallet')
            || ($data['method'] !== 'WALLET' && $category === 'wallet')) {
            throw ValidationException::withMessages([
                'category_code' => 'Kategori saldo hanya untuk metode saldo LFAMILIA.',
            ]);
        }
        $data['category_code'] = $category;
    }

    /**
     * Protocol fields come from a validated gateway method identifier, never
     * a free-form API path or payment request template from the Admin form.
     * The external merchant account must still have the method enabled.
     *
     * @return array<string, mixed>
     */
    private function providerMethodConfiguration(string $method, string $gatewayCode, string $providerMethod): array
    {
        if ($gatewayCode === 'MIDTRANS') {
            if (! preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $providerMethod)
                || ! in_array($method, ['QRIS', 'VIRTUAL_ACCOUNT', 'EWALLET', 'RETAIL'], true)) {
                throw ValidationException::withMessages([
                    'provider_method' => 'Masukkan kode pembayaran Midtrans Snap yang valid untuk jenis metode ini.',
                ]);
            }

            return ['enabled_payments' => [$providerMethod]];
        }

        // DOKU Checkout uses an exact provider-owned method code, including
        // mixed-case IDs such as VIRTUAL_ACCOUNT_Sinarmas.
        $supported = collect($this->providerMethodChoices())->contains(
            fn (array $choice): bool => $choice['gateway'] === 'DOKU'
                && $choice['method'] === $method && $choice['code'] === $providerMethod
        );
        if ($gatewayCode !== 'DOKU' || ! $supported) {
            throw ValidationException::withMessages([
                'provider_method' => 'Kode pembayaran ini belum terdaftar untuk DOKU Checkout dan kategorinya.',
            ]);
        }

        return [
            'api_path' => '/checkout/v1/payment',
            'payment_method_types' => [$providerMethod],
            'public_paths' => ['payment_url' => 'response.payment.url'],
        ];
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
