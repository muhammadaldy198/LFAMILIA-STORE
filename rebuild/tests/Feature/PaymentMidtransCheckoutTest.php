<?php

namespace Tests\Feature;

use App\Jobs\SendFulfillmentJob;
use App\Jobs\SendTransactionalEmailJob;
use App\Jobs\StartFulfillmentJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\FulfillmentService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tes checkout Midtrans dari sisi pesanan: verifikasi status
 * terjadwal memulihkan penyelesaian, checkout tersimulasi yang
 * lunas terkirim sekali, dan pembatalan yang tidak bisa dibuka
 * lagi oleh status basi. Tes callback dan notifikasi Midtrans
 * ada di PaymentMidtransTest.
 */
class PaymentMidtransCheckoutTest extends TestCase
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

    public function test_scheduled_status_verification_recovers_settlement_after_invalid_webhook_signature(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'current-server-key', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([
                    'token' => 'scheduled-status-token',
                    'redirect_url' => 'https://example.test/checkout',
                ]);
            }

            if (preg_match('#https://api\\.sandbox\\.midtrans\\.com/v2/(.+)/status$#', $request->url(), $matches)) {
                return Http::response([
                    'transaction_id' => 'scheduled-status-transaction',
                    'transaction_status' => 'settlement',
                    'status_code' => '200',
                    'order_id' => rawurldecode($matches[1]),
                    'gross_amount' => '11078.00',
                    'fraud_status' => 'accept',
                ]);
            }

            return Http::response([], 404);
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'scheduled-payment-order-unique'
        ))->assertCreated();

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'scheduled-payment-create-unique',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();

        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'scheduled-payment-create-unique')->firstOrFail();

        $this->postJson('/api/payments/midtrans/notification', [
            'transaction_id' => 'scheduled-status-transaction',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => $payment->merchant_reference,
            'gross_amount' => '11078.00',
            'signature_key' => str_repeat('0', 128),
        ])->assertUnauthorized();

        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $payment->order_id)->value('status'));

        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        Artisan::call('lfamilia:reconcile-pending-payments');

        $this->assertSame('PAID', DB::table('orders')->where('id', $payment->order_id)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $payment->id)->value('status'));
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $payment->order_id)->where('event_type', 'PAYMENT_VERIFIED')->count());

        Artisan::call('lfamilia:reconcile-pending-payments');
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $payment->order_id)->where('event_type', 'PAYMENT_VERIFIED')->count());
    }

    /**
     * Simulate a complete sale without money, production credentials, or provider traffic.
     */
    public function test_simulated_checkout_midtrans_paid_and_digiflazz_delivery_complete_once(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');

        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'sandbox-e2e-key', 'is_production' => false],
            'is_active' => true,
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-e2e-test',
                'api_key' => 'supplier-e2e-test-key',
                'webhook_secret' => 'supplier-e2e-webhook-test',
                'testing' => true,
                'base_url' => 'https://supplier-e2e.test',
            ],
            'is_active' => true,
        ]);

        $supplierRequests = 0;
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$supplierRequests) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([
                    'token' => 'e2e-sandbox-token',
                    'redirect_url' => 'https://payments.example.test/checkout',
                ]);
            }
            if (preg_match('#^https://api\.sandbox\.midtrans\.com/v2/(.+)/status$#', $request->url(), $matches)) {
                return Http::response([
                    'transaction_id' => 'midtrans-e2e-simulation',
                    'transaction_status' => 'settlement',
                    'status_code' => '200',
                    'order_id' => rawurldecode($matches[1]),
                    'gross_amount' => '11078.00',
                    'fraud_status' => 'accept',
                ]);
            }
            if ($request->url() === 'https://supplier-e2e.test/v1/transaction') {
                $supplierRequests++;
                $payload = $request->data();
                $this->assertSame('buyer-e2e-test', $payload['username']);
                $this->assertSame('123456', $payload['customer_no']);
                $this->assertTrue($payload['testing']);
                $this->assertSame(
                    md5('buyer-e2e-test'.'supplier-e2e-test-key'.$payload['ref_id']),
                    $payload['sign']
                );

                return Http::response(['data' => [
                    'ref_id' => $payload['ref_id'],
                    'customer_no' => $payload['customer_no'],
                    'buyer_sku_code' => $payload['buyer_sku_code'],
                    'status' => 'Sukses',
                    'message' => 'Sukses (simulasi)',
                    'rc' => '00',
                    'price' => 10000,
                    'sn' => 'E2E-DELIVERY-TEST',
                ]]);
            }

            $this->fail('Unexpected simulated provider URL: '.$request->url());
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'e2e-simulated-order-0001'
        ))->assertCreated();
        $this->assertSame(11078, $checkout->json('total_idr'));

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'e2e-simulated-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk()->assertJsonPath('instructions.token', 'e2e-sandbox-token');

        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'e2e-simulated-payment-0001')->firstOrFail();

        $notification = [
            'transaction_id' => 'midtrans-e2e-simulation',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => $payment->merchant_reference,
            'gross_amount' => '11078.00',
            'fraud_status' => 'accept',
        ];
        $notification['signature_key'] = hash(
            'sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'sandbox-e2e-key'
        );
        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame('PAID', DB::table('orders')->where('id', $payment->order_id)->value('status'));
        Queue::assertPushed(StartFulfillmentJob::class, 1);

        $fulfillment = app(FulfillmentService::class);
        $fulfillment->startOrder((int) $payment->order_id);
        Queue::assertPushed(SendFulfillmentJob::class, 1);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $payment->order_id)->firstOrFail();
        $fulfillment->sendAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $payment->order_id)->value('status'));
        $this->assertSame('SUCCESS', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertSame(1, $supplierRequests);
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $payment->order_id)->count());

        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'duplicate');
        $fulfillment->startOrder((int) $payment->order_id);
        $fulfillment->sendAttempt((int) $attempt->id);
        $this->assertSame(1, $supplierRequests);
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $payment->order_id)->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $payment->order_id)
            ->where('event_type', 'PAYMENT_VERIFIED')->count());
    }

    public function test_midtrans_cancel_stays_cancelled_and_stale_pending_cannot_reopen_order(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([
                    'token' => 'snap-token-cancel',
                    'redirect_url' => 'https://sandbox.midtrans.test/cancel',
                ]);
            }

            if (preg_match('#https://api\\.sandbox\\.midtrans\\.com/v2/(.+)/status$#', $request->url(), $matches)) {
                return Http::response([
                    'transaction_id' => 'midtrans-cancel-1',
                    'transaction_status' => 'cancel',
                    'status_code' => '202',
                    'order_id' => rawurldecode($matches[1]),
                    'gross_amount' => '11078.00',
                    'fraud_status' => 'accept',
                ]);
            }

            return Http::response([], 404);
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'm8-midtrans-cancel-order-0001'
        ))->assertCreated();

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm8-midtrans-cancel-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();

        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'm8-midtrans-cancel-payment-0001')->firstOrFail();
        $notification = [
            'transaction_id' => 'midtrans-cancel-1',
            'transaction_status' => 'cancel',
            'status_code' => '202',
            'order_id' => $payment->merchant_reference,
            'gross_amount' => '11078.00',
            'fraud_status' => 'accept',
        ];
        $notification['signature_key'] = hash('sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'server-test'
        );

        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame('CANCELLED', DB::table('orders')->where('id', $payment->order_id)->value('status'));
        $this->assertSame('CANCELLED', DB::table('payment_transactions')->where('id', $payment->id)->value('status'));
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $payment->order_id)
            ->where('event_type', 'PAYMENT_CANCELLED')->count());

        $result = app(PaymentStateService::class)->apply((int) $payment->id, 'PENDING');
        $this->assertSame('IGNORED_STALE', $result['result']);
        $this->assertSame('CANCELLED', DB::table('orders')->where('id', $payment->order_id)->value('status'));
    }
}
