<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tes kedaluwarsa pembayaran: pembayaran berakhir menutup
 * pesanan dan melepas voucher, kunci pembayaran kedua memakai
 * pembayaran yang sama, dan penutupan terjadwal hanya berjalan
 * sekali. Tes topup berakhir ada di PaymentTopupTest.
 */
class PaymentExpiryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake([SendTransactionalEmailJob::class]);
    }

    /**
     * @return array{product:Product,package_id:int}
     */
    private function catalog(): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Payment Game '.bin2hex(random_bytes(3)),
            'slug' => 'payment-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $package = $product->packages()->create([
            'code' => 'PAY'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            // Explicit nominal markup: fixtures must not inherit a game price.
            'pricing_mode' => 'PERCENT', 'margin_percent' => 10,
            'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'PAY-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedCheckoutDigiflazzCatalog($package->id);

        return ['product' => $product, 'package_id' => $package->id];
    }

    private function route(
        string $channel,
        string $gateway,
        int $flat = 0,
        int $bps = 0,
        ?string $providerChannel = null,
        ?array $configuration = null,
    ): int {
        DB::table('payment_gateways')->where('code', $gateway)->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        DB::table('payment_channels')->where('code', $channel)->update([
            'is_active' => true,
            'fee_flat_idr' => $flat,
            'fee_percent_bps' => $bps,
            'updated_at' => now(),
        ]);

        $channelId = DB::table('payment_channels')->where('code', $channel)->value('id');
        $gatewayId = DB::table('payment_gateways')->where('code', $gateway)->value('id');

        DB::table('payment_routes')->updateOrInsert(
            ['payment_channel_id' => $channelId, 'payment_gateway_id' => $gatewayId],
            [
                'provider_channel' => $providerChannel,
                'configuration' => $configuration === null ? null : json_encode($configuration, JSON_THROW_ON_ERROR),
                'fee_flat_idr' => $flat,
                'fee_percent_bps' => $bps,
                'priority' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return (int) DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $gatewayId)
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function guestCheckout(int $packageId, string $channel, string $key): array
    {
        return [
            'package_id' => $packageId,
            'payment_channel_code' => $channel,
            'customer_input' => ['user_id' => '123456'],
            'voucher_code' => null,
            'guest_email' => 'pay@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => $key,
        ];
    }

    public function test_expired_payment_closes_order_releases_voucher_and_late_paid_does_not_reopen(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('manual_qris', 'MANUAL_QRIS');
        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'M7EXPIRE',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'total_quota' => 10,
            'per_customer_limit' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'm7-expire-order-0001'
        );
        $payload['voucher_code'] = 'M7EXPIRE';
        $checkout = $this->postJson('/checkout/orders', $payload)->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->first();

        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $order->id,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MANUAL_QRIS',
            'channel_code' => 'manual_qris',
            'merchant_reference' => 'EXPIRE-'.$order->id,
            'amount_idr' => $order->total_idr,
            'status' => 'PENDING',
            'idempotency_key' => 'm7-expire-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $states = app(PaymentStateService::class);
        $states->apply($paymentId, 'EXPIRED');

        $this->assertSame('EXPIRED', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame('EXPIRED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame('RELEASED', DB::table('voucher_redemptions')
            ->where('voucher_id', $voucherId)->value('status'));

        $states->apply($paymentId, 'PAID');

        $this->assertSame('EXPIRED', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $order->id)
            ->where('event_type', 'PAYMENT_LATE_VERIFIED')->count());
    }

    public function test_second_payment_key_for_same_order_reuses_existing_payment(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'one-token-only',
                'redirect_url' => 'https://sandbox.midtrans.test/pay-one',
            ]),
        ]);

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'm7-one-payment-order-0001'
        ))->assertCreated();

        $first = $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-one-payment-key-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();

        $second = $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-one-payment-key-0002',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();

        $this->assertSame($first->json('payment_id'), $second->json('payment_id'));
        $this->assertSame('one-token-only', $second->json('instructions.token'));
        $orderId = DB::table('orders')->where('order_number', $checkout->json('order_number'))->value('id');
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $orderId)->count());
        Http::assertSentCount(1);
    }

    public function test_scheduled_expiry_closes_untouched_order_and_releases_voucher_only_once(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'SWEEPER-TEST-'.bin2hex(random_bytes(4)),
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'total_quota' => 10,
            'per_customer_limit' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $voucherCode = DB::table('vouchers')->where('id', $voucherId)->value('code');
        $payload = $this->guestCheckout($catalog['package_id'], 'qris', 'sweeper-no-touch-0001');
        $payload['voucher_code'] = $voucherCode;
        $checkout = $this->postJson('/checkout/orders', $payload)->assertCreated()
            ->assertJsonStructure(['expires_at', 'server_now']);
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        DB::table('orders')->where('id', $order->id)
            ->update(['expires_at' => now()->subMinute()]);

        Artisan::call('lfamilia:expire-unpaid-orders');
        Artisan::call('lfamilia:expire-unpaid-orders');

        $this->assertSame('EXPIRED', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame('RELEASED', DB::table('voucher_redemptions')
            ->where('order_id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)
            ->where('event_type', 'ORDER_EXPIRED')->count());
    }
}
