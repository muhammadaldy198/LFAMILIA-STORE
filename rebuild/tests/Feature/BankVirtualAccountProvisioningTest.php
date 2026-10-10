<?php

namespace Tests\Feature;

use App\Services\IntegrationRuntimeConfig;
use App\Services\Payment\DokuDirectGateway;
use App\Services\Payment\DokuSignature;
use App\Services\Payment\MidtransGateway;
use App\Services\PaymentRouteCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class BankVirtualAccountProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verified_midtrans_bank_routes_exclude_unsupported_doku_bsi(): void
    {
        $banks = config('payment_bank_channels');
        $this->assertCount(7, $banks);
        $routes = collect(config('payment_routes'));

        foreach ($banks as $bank) {
            $midtrans = $routes->first(fn (array $route): bool => $route['channel'] === $bank['code']
                && $route['gateway'] === 'MIDTRANS');
            $doku = $routes->first(fn (array $route): bool => $route['channel'] === $bank['code']
                && $route['gateway'] === 'DOKU');

            $this->assertSame([$bank['midtrans']], $midtrans['configuration']['enabled_payments']);
            if ($bank['doku'] === null) {
                $this->assertNull($doku, 'DOKU BSI route must not be guessed.');

                continue;
            }
            $this->assertSame([$bank['doku']], $doku['configuration']['payment_method_types']);
            $this->assertSame('/checkout/v1/payment', $doku['configuration']['api_path']);
        }
    }

    public function test_bank_channels_are_auto_provisioned_and_admin_overrides_survive_resync(): void
    {
        $banks = config('payment_bank_channels');
        $codes = array_column($banks, 'code');
        $ids = DB::table('payment_channels')->whereIn('code', $codes)->pluck('id');
        DB::table('payment_routes')->whereIn('payment_channel_id', $ids)->delete();
        DB::table('payment_channels')->whereIn('code', $codes)->delete();
        DB::table('payment_channels')->where('code', 'virtual_account')->update([
            'is_active' => true,
            'fee_flat_idr' => 2500,
            'fee_percent_bps' => 25,
        ]);

        $catalog = app(PaymentRouteCatalogService::class);
        $first = $catalog->sync();

        $this->assertSame(7, $first['created_channels']);
        $this->assertSame(13, $first['created']);
        $this->assertSame(7, DB::table('payment_channels')->whereIn('code', $codes)->count());
        $this->assertSame(13, DB::table('payment_routes')->whereIn(
            'payment_channel_id', DB::table('payment_channels')->whereIn('code', $codes)->pluck('id')
        )->count());

        $bsi = DB::table('payment_channels')->where('code', 'va_bsi')->firstOrFail();
        $this->assertSame(0, (int) $bsi->is_active, 'BSI must be enabled only after live route check.');
        $this->assertSame(1, DB::table('payment_routes')
            ->join('payment_gateways', 'payment_gateways.id', '=', 'payment_routes.payment_gateway_id')
            ->where('payment_routes.payment_channel_id', $bsi->id)
            ->where('payment_gateways.code', 'MIDTRANS')->count());

        $bca = DB::table('payment_channels')->where('code', 'va_bca')->firstOrFail();
        $this->assertSame(2500, (int) $bca->fee_flat_idr);
        $this->assertSame(25, (int) $bca->fee_percent_bps);
        $this->assertSame(1, (int) $bca->is_active);

        DB::table('payment_channels')->where('id', $bca->id)->update([
            'is_active' => false, 'fee_flat_idr' => 999, 'sort_order' => 78,
        ]);
        $midtransGatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        DB::table('payment_routes')->where('payment_channel_id', $bca->id)
            ->where('payment_gateway_id', $midtransGatewayId)->update([
                'is_active' => false, 'priority' => 73,
            ]);

        $second = $catalog->sync();
        $this->assertSame(0, $second['created_channels']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, (int) DB::table('payment_channels')->where('id', $bca->id)->value('is_active'));
        $this->assertSame(999, (int) DB::table('payment_channels')->where('id', $bca->id)->value('fee_flat_idr'));
        $this->assertSame(78, (int) DB::table('payment_channels')->where('id', $bca->id)->value('sort_order'));

        $route = DB::table('payment_routes')->where('payment_channel_id', $bca->id)
            ->where('payment_gateway_id', $midtransGatewayId)->firstOrFail();
        $this->assertSame(0, (int) $route->is_active);
        $this->assertSame(73, (int) $route->priority);
        $this->assertSame(['enabled_payments' => ['bca_va']], json_decode($route->configuration, true));
    }

    public function test_midtrans_generic_routes_only_expose_merchant_active_methods(): void
    {
        $routes = collect(config('payment_routes'));
        $qris = $routes->first(fn (array $route): bool => $route['channel'] === 'qris'
            && $route['gateway'] === 'MIDTRANS');
        $gopay = $routes->first(fn (array $route): bool => $route['channel'] === 'ewallet'
            && $route['gateway'] === 'MIDTRANS');
        $va = $routes->first(fn (array $route): bool => $route['channel'] === 'virtual_account'
            && $route['gateway'] === 'MIDTRANS');

        $this->assertSame(['other_qris'], $qris['configuration']['enabled_payments']);
        $this->assertSame(['gopay'], $gopay['configuration']['enabled_payments']);
        $this->assertSame(
            ['bni_va', 'bri_va', 'echannel', 'cimb_va', 'permata_va', 'bsi_va'],
            $va['configuration']['enabled_payments']
        );
        $this->assertNotContains('bca_va', $va['configuration']['enabled_payments']);
    }

    public function test_midtrans_snap_receives_only_selected_bank(): void
    {
        $runtime = Mockery::mock(IntegrationRuntimeConfig::class);
        $runtime->shouldReceive('resolve')->once()->with('midtrans')->andReturn([
            'environment' => 'sandbox',
            'config' => ['server_key' => 'sandbox-test-key'],
        ]);
        $runtime->shouldReceive('midtransSnapBase')->once()->with('sandbox')
            ->andReturn('https://app.sandbox.midtrans.com');

        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'token-test',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/token-test',
        ], 201)]);

        $gateway = new MidtransGateway($runtime);
        $result = $gateway->create([
            'merchant_reference' => 'PAY-123',
            'amount_idr' => 10000,
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '08123456789',
            'route_configuration' => ['enabled_payments' => ['bca_va']],
            'provider_channel' => 'bca_va',
            'finish_url' => null,
        ]);

        $this->assertSame('PENDING', $result['status']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            && $request['enabled_payments'] === ['bca_va']);
    }

    public function test_doku_checkout_receives_only_selected_bank(): void
    {
        $gateway = new DokuDirectGateway(
            Mockery::mock(DokuSignature::class),
            Mockery::mock(IntegrationRuntimeConfig::class),
        );
        $method = new ReflectionMethod(DokuDirectGateway::class, 'payload');

        $payload = $method->invoke($gateway, [
            'route_configuration' => [
                'api_path' => '/checkout/v1/payment',
                'payment_method_types' => ['VIRTUAL_ACCOUNT_BCA'],
            ],
            'merchant_reference' => 'PAY-123',
            'amount_idr' => 10000,
            'expires_minutes' => 60,
            'return_url' => 'https://lfamiliastore.my.id/payment',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '08123456789',
        ]);

        $this->assertSame(['VIRTUAL_ACCOUNT_BCA'], $payload['payment']['payment_method_types']);
        $this->assertSame(10000, $payload['order']['amount']);
    }
}
