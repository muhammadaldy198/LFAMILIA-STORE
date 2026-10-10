<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Configure only the Midtrans automatic QRIS route.
        // Do not activate a method, touch DOKU, or change existing invoices.
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $gatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');

        if ($channelId && $gatewayId) {
            DB::table('payment_routes')
                ->where('payment_channel_id', $channelId)
                ->where('payment_gateway_id', $gatewayId)
                ->update([
                    'fee_flat_idr' => 0,
                    'fee_percent_bps' => 70, // 0.7%, grossed up on customer invoice.
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Fees may have been manually changed since migration. Never reset
        // them or modify invoices on rollback.
    }
};
