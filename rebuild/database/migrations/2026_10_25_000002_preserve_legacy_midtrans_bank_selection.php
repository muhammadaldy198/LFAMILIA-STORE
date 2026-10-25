<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Before manual provider assignment existed, bank VA provisioning
        // auto-enabled BOTH routes while a disabled DOKU gateway was ignored.
        // Freeze the previously effective Midtrans route for untouched defaults.
        // Never switch a selected provider on failure or modify custom routes.
        $dokuId = DB::table('payment_gateways')->where('code', 'DOKU')
            ->where('is_active', false)->value('id');
        $midtransId = DB::table('payment_gateways')->where('code', 'MIDTRANS')
            ->where('is_active', true)->value('id');

        if (! $dokuId || ! $midtransId) {
            return;
        }

        $definitions = collect(config('payment_routes', []));
        foreach ((array) config('payment_bank_channels', []) as $bank) {
            if (empty($bank['doku'])) {
                continue;
            }

            $code = (string) ($bank['code'] ?? '');
            $dokuDefault = $definitions->first(
                fn (array $r): bool => ($r['channel'] ?? '') === $code && ($r['gateway'] ?? '') === 'DOKU'
            );
            $midtransDefault = $definitions->first(
                fn (array $r): bool => ($r['channel'] ?? '') === $code && ($r['gateway'] ?? '') === 'MIDTRANS'
            );
            if (! $dokuDefault || ! $midtransDefault) {
                continue;
            }

            $channelId = DB::table('payment_channels')->where('code', $code)->value('id');
            if (! $channelId) {
                continue;
            }

            $midtrans = DB::table('payment_routes')->where('payment_channel_id', $channelId)
                ->where('payment_gateway_id', $midtransId)->first();
            $doku = DB::table('payment_routes')->where('payment_channel_id', $channelId)
                ->where('payment_gateway_id', $dokuId)->first();

            if (! $midtrans || ! $doku || ! $midtrans->is_active || ! $doku->is_active) {
                continue;
            }

            // Do not rewrite operator-controlled gateway assignments.
            if ((int) $midtrans->priority !== (int) ($midtransDefault['priority'] ?? 100)
                || (int) $doku->priority !== (int) ($dokuDefault['priority'] ?? 100)
                || (string) $doku->provider_channel !== (string) ($dokuDefault['provider_channel'] ?? '')
                || json_decode((string) $doku->configuration, true) !== $dokuDefault['configuration']
                || (bool) $doku->supports_order !== (bool) ($dokuDefault['supports_order'] ?? true)
                || (bool) $doku->supports_wallet_topup !== (bool) ($dokuDefault['supports_wallet_topup'] ?? false)) {
                continue;
            }

            DB::table('payment_routes')->where('id', $doku->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Never reactivate another gateway behind the operator's back.
    }
};
