<?php

namespace Tests\Feature;

use App\Services\PaymentRouteCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndividualEwalletProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_ewallets_start_disabled_with_individual_midtrans_routes(): void
    {
        $wallets = config('payment_ewallet_channels');
        $codes = array_column($wallets, 'code');
        $ids = DB::table('payment_channels')->whereIn('code', $codes)->pluck('id');
        DB::table('payment_routes')->whereIn('payment_channel_id', $ids)->delete();
        DB::table('payment_channels')->whereIn('code', $codes)->delete();

        $result = app(PaymentRouteCatalogService::class)->sync();
        $this->assertSame(2, $result['created_channels']);
        $gateway = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');

        foreach ($wallets as $wallet) {
            $channel = DB::table('payment_channels')->where('code', $wallet['code'])->firstOrFail();
            $this->assertSame(0, (int) $channel->is_active);
            $this->assertSame(0, (int) $channel->fee_flat_idr);
            $this->assertSame(0, (int) $channel->fee_percent_bps);
            $route = DB::table('payment_routes')
                ->where('payment_channel_id', $channel->id)
                ->where('payment_gateway_id', $gateway)->firstOrFail();
            $this->assertSame(0, (int) $route->is_active);
            $this->assertSame([$wallet['midtrans']], json_decode($route->configuration, true)['enabled_payments']);
        }

        $gopay = DB::table('payment_channels')->where('code', 'ewallet_gopay')->firstOrFail();
        DB::table('payment_channels')->where('id', $gopay->id)->update([
            'fee_flat_idr' => 1500, 'fee_percent_bps' => 125, 'is_active' => true,
        ]);
        app(PaymentRouteCatalogService::class)->sync();
        $this->assertDatabaseHas('payment_channels', [
            'id' => $gopay->id, 'fee_flat_idr' => 1500,
            'fee_percent_bps' => 125, 'is_active' => true,
        ]);
    }
}
