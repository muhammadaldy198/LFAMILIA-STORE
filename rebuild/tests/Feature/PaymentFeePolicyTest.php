<?php

namespace Tests\Feature;

use App\Models\IntegrationCredential;
use App\Services\PaymentRoutingService;
use App\Services\WalletTopupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentFeePolicyTest extends TestCase
{
    use DatabaseTransactions;

    private function enableGateway(string $gateway, string $credential, array $config): void
    {
        IntegrationCredential::updateOrCreate(['code' => $credential], [
            'config_ciphertext' => $config,
            'is_active' => true,
        ]);
        DB::table('payment_gateways')->where('code', $gateway)->update([
            'is_active' => true, 'is_maintenance' => false,
        ]);
    }

    public function test_per_gateway_fees_apply_to_orders_and_lfamilia_cash_topups(): void
    {
        $this->enableGateway('MIDTRANS', 'midtrans', ['server_key' => 'sandbox-key', 'is_production' => false]);
        $this->enableGateway('DOKU', 'doku', [
            'client_id' => 'sandbox-client', 'secret_key' => 'sandbox-secret',
            'base_url' => 'https://api-sandbox.doku.com',
        ]);

        $channel = DB::table('payment_channels')->where('code', 'virtual_account')->firstOrFail();
        DB::table('payment_channels')->where('id', $channel->id)->update([
            'is_active' => true,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'fee_flat_idr' => 1000,
            'fee_percent_bps' => 0,
        ]);
        foreach ([['MIDTRANS', 10, 4400], ['DOKU', 20, 3500]] as [$code, $priority, $fee]) {
            $gateway = DB::table('payment_gateways')->where('code', $code)->firstOrFail();
            DB::table('payment_routes')->updateOrInsert(
                ['payment_channel_id' => $channel->id, 'payment_gateway_id' => $gateway->id],
                [
                    'priority' => $priority, 'fee_flat_idr' => $fee, 'fee_percent_bps' => 0,
                    'supports_order' => true, 'supports_wallet_topup' => true,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }

        $routing = app(PaymentRoutingService::class);
        $primary = $routing->resolve('virtual_account', false, 'order');
        $this->assertSame('MIDTRANS', $primary['gateway_code']);
        $this->assertSame(4400, $routing->fee(20000, $primary));
        $this->assertSame(4400, collect($routing->publicOrderChannels(null))
            ->firstWhere('code', 'virtual_account')['fee_flat_idr']);

        DB::table('payment_gateways')->where('code', 'MIDTRANS')
            ->update(['is_maintenance' => true]);

        $fallback = $routing->resolve('virtual_account', false, 'order');
        $this->assertSame('DOKU', $fallback['gateway_code']);
        $this->assertSame(3500, $routing->fee(20000, $fallback));
        $this->assertSame(3500, collect($routing->publicOrderChannels(null))
            ->firstWhere('code', 'virtual_account')['fee_flat_idr']);

        $quote = app(WalletTopupService::class)->quote(20000, 'virtual_account');
        $this->assertSame(20000, $quote['amount_idr']);
        $this->assertSame(3500, $quote['fee_idr']);
        $this->assertSame(23500, $quote['total_idr']);
        $this->assertSame(3500, collect($routing->publicTopupChannels())
            ->firstWhere('code', 'virtual_account')['fee_flat_idr']);
    }

    public function test_wallet_is_free_but_automatic_midtrans_qris_uses_configured_fee(): void
    {
        $this->enableGateway('MIDTRANS', 'midtrans', ['server_key' => 'sandbox-key', 'is_production' => false]);
        DB::table('payment_gateways')->where('code', 'WALLET')->update(['is_active' => true]);
        foreach ([['saldo', 'WALLET'], ['qris', 'MIDTRANS']] as [$code, $gateway]) {
            $channel = DB::table('payment_channels')->where('code', $code)->firstOrFail();
            DB::table('payment_channels')->where('id', $channel->id)->update([
                'is_active' => true, 'fee_flat_idr' => 500, 'fee_percent_bps' => 70,
                'supports_wallet_topup' => $code === 'qris',
            ]);
            $gatewayId = DB::table('payment_gateways')->where('code', $gateway)->value('id');
            DB::table('payment_routes')->updateOrInsert(
                ['payment_channel_id' => $channel->id, 'payment_gateway_id' => $gatewayId],
                [
                    'priority' => 10, 'fee_flat_idr' => 1000, 'fee_percent_bps' => 100,
                    'supports_order' => true, 'supports_wallet_topup' => $code === 'qris',
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }

        $routing = app(PaymentRoutingService::class);
        $wallet = $routing->resolve('saldo');
        $this->assertSame(0, $routing->fee(20000, $wallet));
        $walletVisible = collect($routing->publicOrderChannels(null))->firstWhere('code', 'saldo');
        $this->assertSame(0, $walletVisible['fee_flat_idr']);
        $this->assertSame(0, $walletVisible['fee_percent_bps']);

        $qris = $routing->resolve('qris');
        $this->assertSame('MIDTRANS', $qris['gateway_code']);
        // Explicit override above: Rp1,000 + 1% gross-up on Rp20,000.
        $this->assertSame(1213, $routing->fee(20000, $qris));
        $qrisVisible = collect($routing->publicOrderChannels(null))->firstWhere('code', 'qris');
        $this->assertSame(1000, $qrisVisible['fee_flat_idr']);
        $this->assertSame(100, $qrisVisible['fee_percent_bps']);

        $quote = app(WalletTopupService::class)->quote(20000, 'qris');
        $this->assertSame(1213, $quote['fee_idr']);
        $this->assertSame(21213, $quote['total_idr']);
    }

    public function test_default_midtrans_qris_route_is_seventy_basis_points(): void
    {
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $gatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        $route = DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $gatewayId)
            ->firstOrFail();

        // Migration sets only automatic Midtrans QRIS, not the manual route.
        $this->assertSame(0, (int) $route->fee_flat_idr);
        $this->assertSame(70, (int) $route->fee_percent_bps);

        $this->enableGateway('MIDTRANS', 'midtrans', ['server_key' => 'sandbox-key', 'is_production' => false]);
        DB::table('payment_channels')->where('id', $channelId)->update([
            'is_active' => true, 'supports_order' => true, 'supports_wallet_topup' => true,
        ]);
        $resolved = app(PaymentRoutingService::class)->resolve('qris');
        // ceil(10,000 / 0.993) = 10,071; fee Rp71.
        $this->assertSame(71, app(PaymentRoutingService::class)->fee(10000, $resolved));
        $quote = app(WalletTopupService::class)->quote(10000, 'qris');
        $this->assertSame(10071, $quote['total_idr']);
        $this->assertSame(71, $quote['fee_idr']);
    }

    public function test_percentage_gateway_fee_is_grossed_up_for_customer_orders_and_wallet_topups(): void
    {
        $this->enableGateway('MIDTRANS', 'midtrans', ['server_key' => 'sandbox-key', 'is_production' => false]);
        $channel = DB::table('payment_channels')->where('code', 'virtual_account')->firstOrFail();
        DB::table('payment_channels')->where('id', $channel->id)->update([
            'is_active' => true,
            'supports_order' => true,
            'supports_wallet_topup' => true,
            'fee_flat_idr' => 0,
            'fee_percent_bps' => 0,
        ]);
        $gatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        DB::table('payment_routes')->updateOrInsert(
            ['payment_channel_id' => $channel->id, 'payment_gateway_id' => $gatewayId],
            [
                'priority' => 0, 'fee_flat_idr' => 0, 'fee_percent_bps' => 150,
                'supports_order' => true, 'supports_wallet_topup' => true,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]
        );

        $routing = app(PaymentRoutingService::class);
        $orderRoute = $routing->resolve('virtual_account', false, 'order');
        $this->assertSame(153, $routing->fee(10000, $orderRoute));

        $quote = app(WalletTopupService::class)->quote(10000, 'virtual_account');
        $this->assertSame(10000, $quote['amount_idr']);
        $this->assertSame(153, $quote['fee_idr']);
        $this->assertSame(10153, $quote['total_idr']);

        // Rounded-up percentage deducted from gross leaves requested balance.
        $gatewayDeduction = intdiv(($quote['total_idr'] * 150) + 9999, 10000);
        $this->assertGreaterThanOrEqual(10000, $quote['total_idr'] - $gatewayDeduction);
    }

    public function test_empty_route_override_inherits_existing_channel_fee(): void
    {
        $this->enableGateway('MIDTRANS', 'midtrans', ['server_key' => 'sandbox-key', 'is_production' => false]);
        $channel = DB::table('payment_channels')->where('code', 'virtual_account')->firstOrFail();
        DB::table('payment_channels')->where('id', $channel->id)->update([
            'is_active' => true, 'fee_flat_idr' => 500, 'fee_percent_bps' => 50,
        ]);
        $gatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        DB::table('payment_routes')->updateOrInsert(
            ['payment_channel_id' => $channel->id, 'payment_gateway_id' => $gatewayId],
            [
                'priority' => 0, 'fee_flat_idr' => null, 'fee_percent_bps' => null,
                'supports_order' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
        $route = app(PaymentRoutingService::class)->resolve('virtual_account');
        // ceil((20,000 + 500) / 0.995) = 20,604.
        // Percent is calculated on the actual payment amount.
        $this->assertSame(604, app(PaymentRoutingService::class)->fee(20000, $route));
    }
}
