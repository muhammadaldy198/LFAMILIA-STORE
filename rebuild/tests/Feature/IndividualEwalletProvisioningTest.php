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
        $supported = array_values(array_filter($wallets, fn (array $wallet): bool => empty($wallet['requires_integration'])));
        $this->assertGreaterThanOrEqual(count($supported), $result['created_channels']);

        foreach ($wallets as $wallet) {
            $channel = DB::table('payment_channels')->where('code', $wallet['code'])->first();
            if (! empty($wallet['requires_integration'])) {
                $this->assertNull($channel, 'Do not provision DOKU Wallet before its special callback works.');

                continue;
            }

            $this->assertNotNull($channel);
            $this->assertSame(0, (int) $channel->is_active);
            $this->assertSame(0, (int) $channel->fee_flat_idr);
            $this->assertSame(0, (int) $channel->fee_percent_bps);
            foreach (['MIDTRANS' => 'midtrans', 'DOKU' => 'doku'] as $gatewayCode => $key) {
                $route = DB::table('payment_routes')
                    ->join('payment_gateways', 'payment_gateways.id', '=', 'payment_routes.payment_gateway_id')
                    ->where('payment_routes.payment_channel_id', $channel->id)
                    ->where('payment_gateways.code', $gatewayCode)->first();
                if (empty($wallet[$key])) {
                    $this->assertNull($route, $gatewayCode.' must not be invented for '.$wallet['code']);

                    continue;
                }

                $this->assertNotNull($route, $gatewayCode.' route missing for '.$wallet['code']);
                $this->assertSame(0, (int) $route->is_active);
                $configuration = json_decode($route->configuration, true);
                $this->assertSame([$wallet[$key]], $gatewayCode === 'MIDTRANS'
                    ? $configuration['enabled_payments'] : $configuration['payment_method_types']);
            }
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
