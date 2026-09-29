<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Models\StoreAsset;
use App\Services\PaymentStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPaymentController
{
    public function index(): Response
    {
        $configured = IntegrationCredential::whereIn('code', ['midtrans', 'doku'])
            ->get(['code', 'is_active'])->keyBy('code');

        $manualAsset = StoreAsset::where('key', 'manual_qris')->first();

        return Inertia::render('Admin/Payments', [
            'canConfigure' => auth('admin')->user()->role === 'SUPER_ADMIN',
            'gateways' => DB::table('payment_gateways')->orderBy('id')->get()->map(fn (object $gateway): array => [
                'id' => $gateway->id,
                'code' => $gateway->code,
                'internal_name' => $gateway->internal_name,
                'kind' => $gateway->kind,
                'is_active' => (bool) $gateway->is_active,
                'is_maintenance' => (bool) $gateway->is_maintenance,
                'credential_configured' => match ($gateway->code) {
                    'MIDTRANS' => (bool) ($configured->get('midtrans')?->is_active),
                    'DOKU' => (bool) ($configured->get('doku')?->is_active),
                    default => true,
                },
            ]),
            'channels' => DB::table('payment_channels')->orderBy('sort_order')->get(),
            'routes' => DB::table('payment_routes as routes')
                ->join('payment_channels as channels', 'channels.id', '=', 'routes.payment_channel_id')
                ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
                ->orderBy('channels.sort_order')->orderBy('routes.priority')
                ->select('routes.*', 'channels.code as channel_code', 'gateways.code as gateway_code')->get(),
            'minimumTopupIdr' => $this->minimumTopup(),
            'manualQrisAsset' => $manualAsset ? [
                'id' => $manualAsset->id,
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
        ]);
    }

    public function gateway(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'is_maintenance' => ['required', 'boolean'],
        ]);
        $before = DB::table('payment_gateways')->where('id', $id)->first();
        abort_unless($before, 404);

        if ($data['is_active']) {
            $credentialCode = match ($before->code) {
                'MIDTRANS' => 'midtrans',
                'DOKU' => 'doku',
                default => null,
            };
            if ($credentialCode !== null
                && ! IntegrationCredential::where('code', $credentialCode)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Aktifkan credential gateway di Integrasi terlebih dahulu.',
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

        return back();
    }

    public function channel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'fee_flat_idr' => ['required', 'integer', 'min:0', 'max:10000000'],
            'fee_percent_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'supports_order' => ['required', 'boolean'],
            'supports_wallet_topup' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        $before = DB::table('payment_channels')->where('id', $id)->first();
        abort_unless($before, 404);
        DB::table('payment_channels')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->audit($request, 'payment.channel.updated', 'payment_channel', $id, (array) $before, $data);

        return back();
    }

    public function route(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payment_channel_id' => ['required', 'integer', Rule::exists('payment_channels', 'id')],
            'payment_gateway_id' => ['required', 'integer', Rule::exists('payment_gateways', 'id')],
            'provider_channel' => ['nullable', 'string', 'max:100'],
            'configuration' => ['nullable', 'string', 'max:20000'],
            'priority' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        $configuration = $this->configuration($data['configuration'] ?? null);
        if (DB::table('payment_routes')
            ->where('payment_channel_id', $data['payment_channel_id'])
            ->where('payment_gateway_id', $data['payment_gateway_id'])
            ->exists()) {
            throw ValidationException::withMessages([
                'payment_gateway_id' => 'Routing channel ke gateway tersebut sudah ada.',
            ]);
        }

        $id = DB::table('payment_routes')->insertGetId([
            'payment_channel_id' => $data['payment_channel_id'],
            'payment_gateway_id' => $data['payment_gateway_id'],
            'provider_channel' => $data['provider_channel'] ?: null,
            'configuration' => $configuration === null ? null : json_encode($configuration, JSON_THROW_ON_ERROR),
            'priority' => $data['priority'],
            'is_active' => $data['is_active'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, 'payment.route.created', 'payment_route', $id, null, [
            ...$data, 'configuration' => $configuration,
        ]);

        return back();
    }

    public function updateRoute(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'provider_channel' => ['nullable', 'string', 'max:100'],
            'configuration' => ['nullable', 'string', 'max:20000'],
            'priority' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        $before = DB::table('payment_routes')->where('id', $id)->first();
        abort_unless($before, 404);
        $configuration = $this->configuration($data['configuration'] ?? null);
        $after = [
            'provider_channel' => $data['provider_channel'] ?: null,
            'configuration' => $configuration === null ? null : json_encode($configuration, JSON_THROW_ON_ERROR),
            'priority' => $data['priority'],
            'is_active' => $data['is_active'],
            'updated_at' => now(),
        ];
        DB::table('payment_routes')->where('id', $id)->update($after);
        $this->audit($request, 'payment.route.updated', 'payment_route', $id, (array) $before, [
            ...$after, 'configuration' => $configuration,
        ]);

        return back();
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'minimum_topup_idr' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'wallet.minimum_topup_idr'],
            [
                'value' => json_encode($data['minimum_topup_idr']),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $this->audit($request, 'payment.settings.updated', 'system_setting', 0, null, $data);

        return back();
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

        return back();
    }

    private function configuration(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['configuration' => 'Configuration harus JSON valid.']);
        }
        if (! is_array($data)) {
            throw ValidationException::withMessages(['configuration' => 'Configuration harus berupa JSON object.']);
        }

        $scan = strtolower(json_encode($data, JSON_THROW_ON_ERROR));
        foreach (['secret', 'password', 'api_key', 'server_key', 'client_secret', 'access_token', 'private_key'] as $blocked) {
            if (str_contains($scan, '"'.$blocked.'"')) {
                throw ValidationException::withMessages([
                    'configuration' => 'Credential sensitif harus disimpan di menu Integrasi, bukan route pembayaran.',
                ]);
            }
        }

        return $data;
    }

    private function minimumTopup(): int
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->value('value');

        return max(1, (int) json_decode((string) $raw, true));
    }

    private function audit(
        Request $request,
        string $action,
        string $type,
        int $id,
        ?array $before,
        array $after,
    ): void {
        $admin = $request->user('admin');
        DB::table('audit_logs')->insert([
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'actor_role' => $admin->role,
            'action' => $action,
            'target_type' => $type,
            'target_id' => (string) $id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
