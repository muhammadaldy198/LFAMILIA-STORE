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
     * @return array{created:int,updated:int,skipped:int}
     */
    public function sync(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

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
                'is_active' => false,
                'created_at' => now(),
            ]);
            $result['created']++;
        }

        return $result;
    }
}
