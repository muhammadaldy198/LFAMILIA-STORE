<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep operator-edited gateway names intact.
        DB::table('payment_gateways')
            ->where('code', 'DOKU')
            ->where('internal_name', 'DOKU Direct API')
            ->update(['internal_name' => 'DOKU Checkout', 'updated_at' => now()]);

        $gatewayId = DB::table('payment_gateways')->where('code', 'DOKU')->value('id');
        if (! $gatewayId) {
            return;
        }

        // Update only known code-owned DOKU bank routes. Keep route IDs,
        // activations, financial settings and already-issued invoices intact.
        $definitions = collect(config('payment_routes', []))->filter(
            fn (array $route): bool => ($route['gateway'] ?? null) === 'DOKU'
                && ($route['configuration']['api_path'] ?? null) === '/checkout/v1/payment'
        );
        foreach ($definitions as $definition) {
            $channelId = DB::table('payment_channels')
                ->where('code', (string) ($definition['channel'] ?? ''))->value('id');
            if (! $channelId) {
                continue;
            }

            $route = DB::table('payment_routes')
                ->where('payment_channel_id', $channelId)
                ->where('payment_gateway_id', $gatewayId)->first();
            if (! $route) {
                continue;
            }

            $existing = json_decode((string) $route->configuration, true);
            if (! is_array($existing) || ($existing['api_path'] ?? null) === '/checkout/v1/payment') {
                continue;
            }
            if (! in_array((string) ($existing['api_path'] ?? ''), [
                '/doku-virtual-account/v2/payment-code',
            ], true)) {
                // A non-standard merchant protocol must never be silently remapped.
                continue;
            }

            $pending = DB::table('payment_transactions')
                ->where('payment_route_id', $route->id)
                ->whereIn('status', ['CREATING', 'SENDING', 'UNKNOWN', 'PENDING'])
                ->exists();
            if ($pending) {
                // Preserve the pinned route of unresolved invoices for review.
                // DOKU Checkout's adapter itself will reject Direct API creates.
                continue;
            }

            DB::table('payment_routes')->where('id', $route->id)->update([
                'provider_channel' => $definition['provider_channel'],
                'configuration' => json_encode($definition['configuration'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Never reactivate or restore deprecated direct-payment protocols.
    }
};
