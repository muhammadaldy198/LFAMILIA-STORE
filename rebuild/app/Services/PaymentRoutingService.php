<?php

namespace App\Services;

use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRoutingService
{
    public function __construct(private readonly IntegrationRuntimeConfig $runtime) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicOrderChannels(?User $user): array
    {
        return PaymentChannel::query()
            ->where('is_active', true)
            ->where('supports_order', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('media')
            ->get()
            ->map(function (PaymentChannel $channel) use ($user): ?array {
                try {
                    $route = $this->resolve((string) $channel->code, false, 'order');
                } catch (ValidationException) {
                    return null;
                }

                return [
                    'code' => (string) $channel->code,
                    'name' => (string) $channel->name,
                    'group' => $this->publicGroup((string) $channel->method),
                    'description' => $channel->description ?: $this->defaultDescription((string) $channel->method),
                    'logo_url' => $channel->getFirstMediaUrl('logo') ?: null,
                    'fee_flat_idr' => $this->customerFeeIsForbidden($route) ? 0 : (int) $route['fee_flat_idr'],
                    'fee_percent_bps' => $this->customerFeeIsForbidden($route) ? 0 : (int) $route['fee_percent_bps'],
                    'available' => $route['gateway_code'] !== 'WALLET' || $user !== null,
                ];
            })->filter()->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicTopupChannels(): array
    {
        return PaymentChannel::query()
            ->where('is_active', true)
            ->where('supports_wallet_topup', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('media')
            ->get()
            ->map(function (PaymentChannel $channel): ?array {
                try {
                    $route = $this->resolve((string) $channel->code, false, 'topup');
                } catch (ValidationException) {
                    return null;
                }
                if (in_array($route['gateway_code'], ['WALLET', 'MANUAL_QRIS'], true)) {
                    return null;
                }

                return [
                    'code' => (string) $channel->code,
                    'name' => (string) $channel->name,
                    'description' => $channel->description ?: $this->defaultDescription((string) $channel->method),
                    'logo_url' => $channel->getFirstMediaUrl('logo') ?: null,
                    'fee_flat_idr' => $this->customerFeeIsForbidden($route) ? 0 : (int) $route['fee_flat_idr'],
                    'fee_percent_bps' => $this->customerFeeIsForbidden($route) ? 0 : (int) $route['fee_percent_bps'],
                ];
            })->filter()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $channelCode, bool $lock = false, string $purpose = 'order'): array
    {
        $query = DB::table('payment_channels')->where('code', $channelCode)->where('is_active', true);
        if ($purpose === 'order') {
            $query->where('supports_order', true);
        } else {
            $query->where('supports_wallet_topup', true);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $channel = $query->first();

        if (! $channel) {
            throw ValidationException::withMessages([
                'payment_channel_code' => 'Metode pembayaran tidak tersedia.',
            ]);
        }

        $unavailableGateways = $this->unavailableExternalGatewayCodes();
        $routeQuery = DB::table('payment_routes as routes')
            ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->where('routes.payment_channel_id', $channel->id)
            ->where('routes.is_active', true)
            ->where('gateways.is_active', true)
            ->where('gateways.is_maintenance', false)
            ->when($unavailableGateways !== [], fn ($query) => $query->whereNotIn('gateways.code', $unavailableGateways))
            ->when($purpose === 'order',
                fn ($query) => $query->where('routes.supports_order', true),
                fn ($query) => $query->where('routes.supports_wallet_topup', true))
            ->orderBy('routes.priority')
            ->orderBy('routes.id')
            ->select(
                'routes.id as route_id',
                'routes.provider_channel',
                'routes.configuration',
                'routes.fee_flat_idr as route_fee_flat_idr',
                'routes.fee_percent_bps as route_fee_percent_bps',
                'gateways.id as gateway_id',
                'gateways.code as gateway_code',
                'gateways.kind as gateway_kind'
            );
        if ($lock) {
            $routeQuery->lockForUpdate();
        }
        $route = $routeQuery->first();

        if (! $route) {
            throw ValidationException::withMessages([
                'payment_channel_code' => 'Metode pembayaran sedang tidak tersedia.',
            ]);
        }

        return [
            'channel_id' => (int) $channel->id,
            'channel_code' => (string) $channel->code,
            'channel_name' => (string) $channel->name,
            'channel_method' => (string) $channel->method,
            'fee_flat_idr' => $route->route_fee_flat_idr !== null
                ? (int) $route->route_fee_flat_idr : (int) $channel->fee_flat_idr,
            'fee_percent_bps' => $route->route_fee_percent_bps !== null
                ? (int) $route->route_fee_percent_bps : (int) $channel->fee_percent_bps,
            'route_id' => (int) $route->route_id,
            'gateway_id' => (int) $route->gateway_id,
            'gateway_code' => (string) $route->gateway_code,
            'gateway_kind' => (string) $route->gateway_kind,
            'provider_channel' => $route->provider_channel,
            'configuration' => is_string($route->configuration)
                ? (json_decode($route->configuration, true) ?: [])
                : ((array) $route->configuration),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function byRouteId(int $routeId, string $purpose = 'order', bool $lock = false): array
    {
        $unavailableGateways = $this->unavailableExternalGatewayCodes();
        $query = DB::table('payment_routes as routes')
            ->join('payment_channels as channels', 'channels.id', '=', 'routes.payment_channel_id')
            ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->where('routes.id', $routeId)
            ->where('routes.is_active', true)
            ->where('channels.is_active', true)
            ->where('gateways.is_active', true)
            ->where('gateways.is_maintenance', false)
            ->when($unavailableGateways !== [], fn ($query) => $query->whereNotIn('gateways.code', $unavailableGateways))
            ->when($purpose === 'order', function ($query): void {
                $query->where('channels.supports_order', true)
                    ->where('routes.supports_order', true);
            }, function ($query): void {
                $query->where('channels.supports_wallet_topup', true)
                    ->where('routes.supports_wallet_topup', true);
            })
            ->select(
                'routes.id as route_id',
                'routes.provider_channel',
                'routes.configuration',
                'routes.fee_flat_idr as route_fee_flat_idr',
                'routes.fee_percent_bps as route_fee_percent_bps',
                'channels.id as channel_id',
                'channels.code as channel_code',
                'channels.name as channel_name',
                'channels.method as channel_method',
                'channels.fee_flat_idr',
                'channels.fee_percent_bps',
                'gateways.id as gateway_id',
                'gateways.code as gateway_code',
                'gateways.kind as gateway_kind'
            );
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'payment' => 'Metode pembayaran sedang tidak tersedia.',
            ]);
        }

        return [
            'channel_id' => (int) $row->channel_id,
            'channel_code' => (string) $row->channel_code,
            'channel_name' => (string) $row->channel_name,
            'channel_method' => (string) $row->channel_method,
            'fee_flat_idr' => $row->route_fee_flat_idr !== null
                ? (int) $row->route_fee_flat_idr : (int) $row->fee_flat_idr,
            'fee_percent_bps' => $row->route_fee_percent_bps !== null
                ? (int) $row->route_fee_percent_bps : (int) $row->fee_percent_bps,
            'route_id' => (int) $row->route_id,
            'gateway_id' => (int) $row->gateway_id,
            'gateway_code' => (string) $row->gateway_code,
            'gateway_kind' => (string) $row->gateway_kind,
            'provider_channel' => $row->provider_channel,
            'configuration' => is_string($row->configuration)
                ? (json_decode($row->configuration, true) ?: [])
                : ((array) $row->configuration),
        ];
    }

    public function fee(int $amountIdr, array $route): int
    {
        if ($amountIdr <= 0) {
            throw ValidationException::withMessages(['payment_channel_code' => 'Nilai transaksi tidak valid.']);
        }

        // Paying with LFAMILIA Cash is always free. QRIS MDR cannot be
        // passed to consumers via a method-specific surcharge.
        if ($this->customerFeeIsForbidden($route)) {
            return 0;
        }

        $flat = max(0, (int) $route['fee_flat_idr']);
        $bps = max(0, min(9999, (int) $route['fee_percent_bps']));

        if ($bps === 0) {
            return $flat;
        }

        // Percentage gateway fees are deducted from the *total* payment,
        // not just the item price. Gross up so the merchant retains at
        // least the requested amount after both percentage and flat fees:
        // total = ceil((amount + flat) / (1 - rate)).
        $numeratorBase = $amountIdr + $flat;
        if ($numeratorBase <= 0 || $numeratorBase > intdiv(PHP_INT_MAX - 9999, 10000)) {
            throw ValidationException::withMessages(['payment_channel_code' => 'Nilai pembayaran melebihi batas aman.']);
        }

        $denominator = 10000 - $bps;
        $total = intdiv(($numeratorBase * 10000) + $denominator - 1, $denominator);

        return $total - $amountIdr;
    }

    private function customerFeeIsForbidden(array $route): bool
    {
        return in_array(strtoupper((string) ($route['channel_method'] ?? '')), ['WALLET', 'QRIS'], true)
            || in_array(strtolower((string) ($route['channel_code'] ?? '')), ['saldo', 'qris', 'manual_qris'], true)
            || in_array(strtoupper((string) ($route['gateway_code'] ?? '')), ['WALLET', 'MANUAL_QRIS'], true);
    }

    public function gatewayReady(string $gatewayCode): bool
    {
        return match (strtoupper($gatewayCode)) {
            'MIDTRANS' => $this->credentialHasKeys('midtrans', ['server_key']),
            'DOKU' => $this->credentialHasKeys('doku', ['client_id', 'secret_key']),
            default => true,
        };
    }

    /**
     * @return array<int, string>
     */
    private function unavailableExternalGatewayCodes(): array
    {
        return array_values(array_filter(
            ['MIDTRANS', 'DOKU'],
            fn (string $code): bool => ! $this->gatewayReady($code)
        ));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function credentialHasKeys(string $credentialCode, array $keys): bool
    {
        $resolved = $this->runtime->resolve($credentialCode);
        $config = $resolved['config'] ?? [];

        if (! is_array($config)) {
            return false;
        }

        foreach ($keys as $key) {
            if (! isset($config[$key]) || ! is_string($config[$key]) || trim($config[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    private function publicGroup(string $method): string
    {
        return match ($method) {
            'WALLET' => 'wallet',
            'QRIS' => 'qris',
            'VIRTUAL_ACCOUNT' => 'va',
            'EWALLET' => 'ewallet',
            'RETAIL' => 'retail',
            default => 'other',
        };
    }

    private function defaultDescription(string $method): string
    {
        return match ($method) {
            'WALLET' => 'Bayar langsung menggunakan saldo akun LFAMILIA.',
            'QRIS' => 'Scan QR menggunakan aplikasi pembayaran yang mendukung QRIS.',
            'VIRTUAL_ACCOUNT' => 'Transfer melalui Virtual Account bank yang tersedia.',
            'EWALLET' => 'Bayar menggunakan dompet digital yang tersedia.',
            'RETAIL' => 'Bayar melalui gerai retail yang tersedia.',
            default => 'Metode pembayaran tersedia untuk transaksi ini.',
        };
    }
}
