<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ((array) config('payment_routes', []) as $definition) {
            $channelId = DB::table('payment_channels')
                ->where('code', (string) ($definition['channel'] ?? ''))
                ->value('id');
            $gatewayId = DB::table('payment_gateways')
                ->where('code', (string) ($definition['gateway'] ?? ''))
                ->value('id');

            if (! $channelId || ! $gatewayId) {
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
        }
    }

    public function down(): void
    {
        // Protocol routes may already have transaction references or operator state.
        // Rollback intentionally keeps them; disabling/removing transaction routes
        // automatically would be unsafe.
    }
};
