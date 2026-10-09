<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PaymentRouteCatalogService
{
    /**
     * Ensure source-controlled payment protocol routes exist.
     *
     * Existing operational settings (priority, purposes and active state) are
     * preserved. Only protocol-owned fields are refreshed from the repository.
     *
     * @return array{created:int,updated:int,skipped:int,created_channels:int}
     */
    public function sync(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'created_channels' => $this->ensureBankChannels()];

        foreach ((array) config('payment_routes', []) as $definition) {
            $channelId = DB::table('payment_channels')
                ->where('code', (string) ($definition['channel'] ?? ''))
                ->value('id');
            $gatewayId = DB::table('payment_gateways')
                ->where('code', (string) ($definition['gateway'] ?? ''))
                ->value('id');

            if (! $channelId || ! $gatewayId) {
                $result['skipped']++;

                continue;
            }

            $protocol = [
                'provider_channel' => $definition['provider_channel'] ?? null,
                'configuration' => isset($definition['configuration'])
                    ? json_encode($definition['configuration'], JSON_THROW_ON_ERROR)
                    : null,
                'updated_at' => now(),
            ];

            $existing = DB::table('payment_routes')
                ->where('payment_channel_id', $channelId)
                ->where('payment_gateway_id', $gatewayId)
                ->first();

            if ($existing) {
                DB::table('payment_routes')->where('id', $existing->id)->update($protocol);
                $result['updated']++;

                continue;
            }

            DB::table('payment_routes')->insert([
                'payment_channel_id' => $channelId,
                'payment_gateway_id' => $gatewayId,
                ...$protocol,
                'priority' => (int) ($definition['priority'] ?? 100),
                'supports_order' => (bool) ($definition['supports_order'] ?? true),
                'supports_wallet_topup' => (bool) ($definition['supports_wallet_topup'] ?? false),
                // Bank VA routes are pre-wired automatically. Gateway credentials,
                // maintenance state and the channel toggle still gate visibility.
                'is_active' => in_array((string) ($definition['channel'] ?? ''), array_column((array) config('payment_bank_channels', []), 'code'), true),
                'created_at' => now(),
            ]);
            $result['created']++;
        }

        return $result;
    }

    /**
     * Provision bank choices without overwriting operator-edited fees, order,
     * visibility or support flags on subsequent catalog synchronizations.
     */
    private function ensureBankChannels(): int
    {
        $generic = DB::table('payment_channels')->where('code', 'virtual_account')->first();
        if (! $generic) {
            return 0;
        }

        $created = 0;
        foreach ((array) config('payment_bank_channels', []) as $bank) {
            $code = (string) ($bank['code'] ?? '');
            if ($code === '' || DB::table('payment_channels')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('payment_channels')->insert([
                'code' => $code,
                'method' => 'VIRTUAL_ACCOUNT',
                'name' => (string) $bank['name'],
                'description' => 'Transfer otomatis melalui '.(string) $bank['name'].'.',
                'fee_flat_idr' => (int) $generic->fee_flat_idr,
                'fee_percent_bps' => (int) $generic->fee_percent_bps,
                'supports_order' => (bool) $generic->supports_order,
                'supports_wallet_topup' => (bool) $generic->supports_wallet_topup,
                'sort_order' => (int) $bank['sort_order'],
                'is_active' => (bool) $generic->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }
}
