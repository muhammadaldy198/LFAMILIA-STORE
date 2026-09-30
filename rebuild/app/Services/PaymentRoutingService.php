<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRoutingService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicOrderChannels(?User $user): array
    {
        return DB::table('payment_channels as channels')
            ->where('channels.is_active', true)
            ->where('channels.supports_order', true)
            ->orderBy('channels.sort_order')
            ->get()
            ->filter(function (object $channel): bool {
                try {
                    $this->resolve((string) $channel->code, false, 'order');

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            })
            ->map(function (object $channel) use ($user): array {
                $route = $this->resolve((string) $channel->code, false, 'order');

                return [
                    'code' => (string) $channel->code,
                    'name' => (string) $channel->name,
                    'group' => $this->publicGroup((string) $channel->code, (string) $channel->name),
                    'description' => $this->publicDescription((string) $channel->code, (string) $channel->name),
                    'available' => $route['gateway_code'] !== 'WALLET' || $user !== null,
                ];
            })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicTopupChannels(): array
    {
        return DB::table('payment_channels as channels')
            ->where('channels.is_active', true)
            ->where('channels.supports_wallet_topup', true)
            ->orderBy('channels.sort_order')
            ->get()
            ->filter(function (object $channel): bool {
                try {
                    $route = $this->resolve((string) $channel->code, false, 'topup');

                    return ! in_array($route['gateway_code'], ['WALLET'], true);
                } catch (ValidationException) {
                    return false;
                }
            })
            ->map(fn (object $channel): array => [
                'code' => (string) $channel->code,
                'name' => (string) $channel->name,
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

        $routeQuery = DB::table('payment_routes as routes')
            ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->where('routes.payment_channel_id', $channel->id)
            ->where('routes.is_active', true)
            ->where('gateways.is_active', true)
            ->where('gateways.is_maintenance', false)
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
    public function byRouteId(int $routeId): array
    {
        $row = DB::table('payment_routes as routes')
            ->join('payment_channels as channels', 'channels.id', '=', 'routes.payment_channel_id')
            ->join('payment_gateways as gateways', 'gateways.id', '=', 'routes.payment_gateway_id')
            ->where('routes.id', $routeId)
            ->where('routes.is_active', true)
            ->where('channels.is_active', true)
            ->where('gateways.is_active', true)
            ->where('gateways.is_maintenance', false)
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
            )->first();

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

    private function publicGroup(string $code, string $name): string
    {
        $value = strtolower($code.' '.$name);
        if (str_contains($value, 'wallet') || str_contains($value, 'saldo') || str_contains($value, 'cash')) {
            return 'wallet';
        }
        if (str_contains($value, 'qris') || str_contains($value, 'qr')) {
            return 'qris';
        }
        if (str_contains($value, 'va') || str_contains($value, 'virtual') || str_contains($value, 'bank')) {
            return 'va';
        }
        if (str_contains($value, 'dana') || str_contains($value, 'ovo') || str_contains($value, 'gopay')
            || str_contains($value, 'shopee') || str_contains($value, 'wallet')) {
            return 'ewallet';
        }
        if (str_contains($value, 'alfamart') || str_contains($value, 'indomaret') || str_contains($value, 'retail')) {
            return 'retail';
        }

        return 'other';
    }

    private function publicDescription(string $code, string $name): string
    {
        return match ($this->publicGroup($code, $name)) {
            'wallet' => 'Bayar langsung menggunakan saldo akun LFAMILIA.',
            'qris' => 'Scan QR menggunakan aplikasi pembayaran yang mendukung QRIS.',
            'va' => 'Transfer melalui Virtual Account bank yang tersedia.',
            'ewallet' => 'Bayar menggunakan dompet digital yang tersedia.',
            'retail' => 'Bayar melalui gerai retail yang tersedia.',
            default => 'Metode pembayaran tersedia untuk transaksi ini.',
        };
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
}
