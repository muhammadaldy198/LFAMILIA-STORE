<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\PaymentRoutingService;
use App\Services\PaymentService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tes validasi saat pembayaran dimulai dan dibuat:
 * pembuatan eksternal yang tidak pasti tidak dicoba ulang
 * membabi buta, kegagalan koneksi menandai pembayaran tidak
 * diketahui, klaim pembuatan mencegah permintaan ganda, dan
 * status baris terkunci divalidasi ulang saat mulai. Tes
 * pemulihan sesudah tersangkut ada di PaymentCreationRecoveryTest.
 */
class PaymentStartValidationTest extends TestCase
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

    private function seedMidtransCredential(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
    }

    public function test_uncertain_external_create_is_not_blindly_retried(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['error' => 'upstream'], 503),
        ]);

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'm7-uncertain-order-0001'
        ))->assertCreated();

        $payload = [
            'idempotency_key' => 'm7-uncertain-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ];
        Log::spy();
        $this->postJson('/payments/orders/'.$checkout->json('order_number'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), $payload)
            ->assertOk()->assertJsonPath('status', 'UNKNOWN');

        $orderId = DB::table('orders')->where('order_number', $checkout->json('order_number'))->value('id');
        $paymentId = DB::table('payment_transactions')->where('order_id', $orderId)->value('id');

        Log::shouldHaveReceived('warning')->once()->with(
            'Payment transaction marked UNKNOWN, needs manual reconciliation',
            [
                'payment_transaction_id' => $paymentId,
                'order_id' => $orderId,
                'gateway_code' => 'MIDTRANS',
            ]
        );

        $this->get('/payment?invoice='.urlencode((string) $checkout->json('order_number')).'&resume=1&transaction_status=settlement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payment/Show')
                ->where('order.status', 'PENDING_PAYMENT')
                ->where('payment.id', $paymentId)
                ->where('payment.status', 'UNKNOWN')
            );

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $orderId)->count());
    }

    public function test_connection_exception_marks_payment_unknown_and_notifies_admin(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => function (): never {
                throw new ConnectionException('Simulated connection loss.');
            },
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'm7-connection-order-0001'
        ))->assertCreated();

        Log::spy();
        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-connection-payment-0001', 'access_code' => $checkout->json('access_code'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $orderId = DB::table('orders')->where('order_number', $checkout->json('order_number'))->value('id');
        $payment = DB::table('payment_transactions')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('UNKNOWN', $payment->status);
        $this->assertSame(1, DB::table('admin_notifications')->where('event_type', 'payment.unknown')->count());
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $orderId)->count());
    }

    public function test_external_create_claim_prevents_duplicate_gateway_request_from_stale_payment_object(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-single-claim',
                'redirect_url' => 'https://sandbox.midtrans.test/single-claim',
            ]),
        ]);

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'audit-single-claim-order-0001'
        ))->assertCreated();

        $order = DB::table('orders')
            ->where('order_number', $checkout->json('order_number'))
            ->firstOrFail();

        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $order->id,
            'wallet_topup_id' => null,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'amount_idr' => $order->total_idr,
            'status' => 'CREATING',
            'request_fingerprint' => hash('sha256', 'audit-single-claim'),
            'idempotency_key' => 'audit-single-claim-payment-0001',
            'expires_at' => $order->expires_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_transactions')->where('id', $paymentId)->update([
            'merchant_reference' => 'PAY-AUDIT-'.$paymentId,
            'updated_at' => now(),
        ]);

        $stalePayment = DB::table('payment_transactions')->where('id', $paymentId)->firstOrFail();
        $route = app(PaymentRoutingService::class)->byRouteId($routeId);
        $service = app(PaymentService::class);
        $method = new \ReflectionMethod($service, 'createExternal');
        $method->setAccessible(true);

        $first = $method->invoke($service, $stalePayment, $order, $route);
        $second = $method->invoke($service, $stalePayment, $order, $route);

        $this->assertSame('PENDING', $first['status']);
        $this->assertSame('PENDING', $second['status']);
        Http::assertSentCount(1);
    }

    public function test_fast_paid_callback_during_create_is_not_downgraded_by_late_create_response(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->url() !== 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([], 404);
            }

            $merchantReference = (string) data_get($request->data(), 'transaction_details.order_id');
            $payment = DB::table('payment_transactions')
                ->where('merchant_reference', $merchantReference)
                ->firstOrFail();

            app(PaymentStateService::class)->apply((int) $payment->id, 'PAID', [
                'source' => 'test_fast_callback',
            ]);

            return Http::response([
                'token' => 'snap-token-fast-callback',
                'redirect_url' => 'https://sandbox.midtrans.test/fast-callback',
            ]);
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'audit-fast-callback-order-0001'
        ))->assertCreated();

        $response = $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'audit-fast-callback-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();

        $response->assertJsonPath('status', 'PAID')
            ->assertJsonPath('instructions.token', 'snap-token-fast-callback');

        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'audit-fast-callback-payment-0001')
            ->firstOrFail();
        $this->assertSame('PAID', $payment->status);
    }

    public function test_start_order_revalidates_status_from_the_locked_row(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'should-never-be-created', 'redirect_url' => 'https://example.test/x']);
            }

            return Http::response([], 404);
        });
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-locked-status-order'
        ))->assertCreated();
        $stale = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        // The order is cancelled after the caller's snapshot was taken.
        DB::table('orders')->where('id', $stale->id)->update(['status' => 'CANCELLED']);

        try {
            app(PaymentService::class)->startOrder($stale, 'pr4-locked-status-payment', null);
            $this->fail('startOrder should have rejected the cancelled order.');
        } catch (ValidationException $exception) {
            $this->assertSame('Pesanan tidak menunggu pembayaran.', $exception->errors()['payment'][0]);
        }
        $this->assertSame(0, DB::table('payment_transactions')->where('order_id', $stale->id)->count());
        $this->assertSame(0, $creates);
    }

    public function test_start_order_revalidates_expiry_from_the_locked_row(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        Http::fake(['*' => Http::response([], 404)]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-locked-expiry-order'
        ))->assertCreated();
        $stale = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        // The order reaches its expiry after the caller's snapshot was taken.
        DB::table('orders')->where('id', $stale->id)->update(['expires_at' => now()->subMinute()]);

        try {
            app(PaymentService::class)->startOrder($stale, 'pr4-locked-expiry-payment', null);
            $this->fail('startOrder should have rejected the expired order.');
        } catch (ValidationException $exception) {
            $this->assertSame('Pesanan sudah kedaluwarsa.', $exception->errors()['payment'][0]);
        }
        $this->assertSame('EXPIRED', DB::table('orders')->where('id', $stale->id)->value('status'));
        $this->assertSame(0, DB::table('payment_transactions')->where('order_id', $stale->id)->count());
    }
}
