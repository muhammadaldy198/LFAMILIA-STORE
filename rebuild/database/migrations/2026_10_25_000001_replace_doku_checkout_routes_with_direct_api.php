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

        // The existing DOKU gateway was historically unconfigured/disabled.
        // Do not let legacy staged routes shadow working Midtrans channels.
        $dokuActive = DB::table('payment_gateways')->where('id', $dokuId)->value('is_active');
        if (! $dokuActive) {
            DB::table('payment_routes')->where('payment_gateway_id', $dokuId)
                ->update(['is_active' => false, 'updated_at' => now()]);
        }

        // Routes without a supported Non-SNAP adapter must never retain
        // historical hosted-checkout payment configuration.
        $unsupportedIds = DB::table('payment_channels')
            ->whereIn('code', ['va_bri', 'va_bca', 'va_mandiri', 'va_cimb'])
            ->pluck('id');
        if ($unsupportedIds->isNotEmpty()) {
            DB::table('payment_routes')
                ->where('payment_gateway_id', $dokuId)
                ->whereIn('payment_channel_id', $unsupportedIds)
                ->update([
                    'is_active' => false,
                    'provider_channel' => null,
                    'configuration' => json_encode(['disabled_reason' => 'REQUIRES_SNAP_DIRECT_API'], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // No rollback to a hosted checkout protocol or automatic reactivation.
    }
};
