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
            ->get()
            ->filter(function (PaymentChannel $channel): bool {
                try {
                    $this->resolve((string) $channel->code, false, 'order');

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            })
            ->map(function (PaymentChannel $channel) use ($user): array {
                $route = $this->resolve((string) $channel->code, false, 'order');

                return [
                    'code' => (string) $channel->code,
                    'name' => (string) $channel->name,
                    'group' => $this->publicGroup((string) $channel->method),
                    'description' => $channel->description ?: $this->defaultDescription((string) $channel->method),
                    'logo_url' => $channel->getFirstMediaUrl('logo') ?: null,
                    'fee_flat_idr' => (int) $channel->fee_flat_idr,
                    'fee_percent_bps' => (int) $channel->fee_percent_bps,
                    'available' => $route['gateway_code'] !== 'WALLET' || $user !== null,
                ];
            })->values()->all();
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
            ->get()
            ->filter(function (PaymentChannel $channel): bool {
                try {
                    $route = $this->resolve((string) $channel->code, false, 'topup');

                    return ! in_array($route['gateway_code'], ['WALLET', 'MANUAL_QRIS'], true);
                } catch (ValidationException) {
                    return false;
                }
            })
            ->map(fn (PaymentChannel $channel): array => [
                'code' => (string) $channel->code,
                'name' => (string) $channel->name,
                'description' => $channel->description ?: $this->defaultDescription((string) $channel->method),
                'logo_url' => $channel->getFirstMediaUrl('logo') ?: null,
                'fee_flat_idr' => (int) $channel->fee_flat_idr,
                'fee_percent_bps' => (int) $channel->fee_percent_bps,
            ])->values()->all();
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
            'fee_flat_idr' => (int) $channel->fee_flat_idr,
            'fee_percent_bps' => (int) $channel->fee_percent_bps,
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
                'channels.id as channel_id',
                'channels.code as channel_code',
                'channels.name as channel_name',
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
            'fee_flat_idr' => (int) $row->fee_flat_idr,
            'fee_percent_bps' => (int) $row->fee_percent_bps,
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

        $flat = max(0, (int) $route['fee_flat_idr']);
        $bps = max(0, min(10000, (int) $route['fee_percent_bps']));
        $percent = $bps === 0 ? 0 : intdiv(($amountIdr * $bps) + 9999, 10000);

        return $flat + $percent;
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
