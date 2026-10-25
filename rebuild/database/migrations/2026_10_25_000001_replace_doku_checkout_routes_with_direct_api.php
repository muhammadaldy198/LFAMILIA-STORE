<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dokuId = DB::table('payment_gateways')->where('code', 'DOKU')->value('id');
        if (! $dokuId) {
            return;
        }

        foreach ((array) config('payment_routes', []) as $definition) {
            if (($definition['gateway'] ?? null) !== 'DOKU') {
                continue;
            }

            $channelId = DB::table('payment_channels')
                ->where('code', (string) $definition['channel'])
                ->value('id');
            if (! $channelId) {
                continue;
            }

            // Replace repository-owned legacy Checkout protocol without touching
            // the operator's route activation, purpose, priorities, or fees.
            DB::table('payment_routes')
                ->where('payment_gateway_id', $dokuId)
                ->where('payment_channel_id', $channelId)
                ->update([
                    'provider_channel' => $definition['provider_channel'],
                    'configuration' => json_encode($definition['configuration'], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }

        // The DOKU BRI channel requires a separate SNAP integration. It must
        // never keep its historical hosted-checkout endpoint enabled.
        $briId = DB::table('payment_channels')->where('code', 'va_bri')->value('id');
        if ($briId) {
            DB::table('payment_routes')
                ->where('payment_gateway_id', $dokuId)
                ->where('payment_channel_id', $briId)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // No rollback to a hosted checkout protocol or automatic reactivation.
    }
};
