<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\User;
use App\Services\Payment\DokuSignature;
use App\Services\PaymentRoutingService;
use App\Services\PaymentService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tes pemulihan pembayaran yang tersangkut di keadaan
 * pembuatan (creating): pembuatan ulang yang aman, adopsi
 * transaksi gateway yang sudah ada, penghentian yang tidak lagi
 * bisa dibayar, dan pemeriksaan admin atas pembayaran yang
 * keadaannya tidak pasti. Tes alur pembayaran normal ada di
 * class tes pembayaran masing-masing.
 */
class PaymentCreationRecoveryTest extends TestCase
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
    private function creatingPaymentRow(
        int $routeId,
        ?int $orderId,
        string $reference,
        int $amount,
        string $gateway = 'MIDTRANS',
        string $channel = 'qris',
        ?int $topupId = null,
        int $staleMinutes = 10
    ): int {
        return DB::table('payment_transactions')->insertGetId([
            'order_id' => $orderId,
            'wallet_topup_id' => $topupId,
            'payment_route_id' => $routeId,
            'gateway_code' => $gateway,
            'channel_code' => $channel,
            'merchant_reference' => $reference,
            'amount_idr' => $amount,
            'status' => 'CREATING',
            'request_fingerprint' => hash('sha256', $reference),
            'idempotency_key' => 'pr4-'.strtolower($reference),
            'created_at' => now()->subMinutes($staleMinutes),
            'updated_at' => now()->subMinutes($staleMinutes),
        ]);
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
    #[DataProvider('adminUnknownStatuses')]
    public function test_admin_can_verify_unknown_midtrans_payment_without_creating_another_payment(
        string $gatewayStatus,
        string $expectedOrderStatus
    ): void {
        Queue::fake();
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'unknown-admin-order-'.strtolower($gatewayStatus)
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-ADMIN-'.strtoupper($gatewayStatus).'-'.bin2hex(random_bytes(3));
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $order->id,
            'wallet_topup_id' => null,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => $reference,
            'amount_idr' => $order->total_idr,
            'status' => 'UNKNOWN',
            'request_fingerprint' => hash('sha256', $reference),
            'idempotency_key' => 'unknown-admin-payment-'.strtolower($gatewayStatus),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake(function ($request) use ($reference, $order, $gatewayStatus) {
            $this->assertStringContainsString('/v2/'.$reference.'/status', $request->url());

            return Http::response([
                'order_id' => $reference,
                'gross_amount' => (string) $order->total_idr,
                'status_code' => '200',
                'transaction_status' => $gatewayStatus,
            ]);
        });
        $admin = $this->createAdmin([
            'name' => 'Payment Checker',
            'email' => 'payment-check-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('payment-test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin')
            ->post('/admin/orders/'.$order->id.'/check-payment')
            ->assertRedirect();
        $this->assertSame($expectedOrderStatus, DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.payment.checked')
            ->where('target_id', (string) $order->id)->count());
    }

    public static function adminUnknownStatuses(): array
    {
        return [
            'paid' => ['settlement', 'PAID'],
            'failed' => ['failure', 'FAILED'],
            'expired' => ['expire', 'EXPIRED'],
        ];
    }
    public function test_admin_unknown_gateway_unreachable_leaves_status_unchanged(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'unknown-unreachable-order-0001'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-ADMIN-UNREACHABLE-'.bin2hex(random_bytes(3));
        DB::table('payment_transactions')->insert([
            'order_id' => $order->id,
            'wallet_topup_id' => null,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => $reference,
            'amount_idr' => $order->total_idr,
            'status' => 'UNKNOWN',
            'request_fingerprint' => hash('sha256', $reference),
            'idempotency_key' => 'unknown-unreachable-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['error' => 'gateway unavailable'], 503)]);
        $admin = $this->createAdmin([
            'name' => 'Payment Retry Checker',
            'email' => 'payment-retry-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('payment-test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin')
            ->post('/admin/orders/'.$order->id.'/check-payment')
            ->assertStatus(503);
        $this->assertSame('UNKNOWN', DB::table('payment_transactions')
            ->where('order_id', $order->id)->value('status'));
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $order->id)->value('status'));
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
    public function test_recovery_redrives_creation_when_gateway_definitively_has_no_transaction(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-redrive-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-REDRIVE-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);

        $creates = [];
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates[] = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

                return Http::response(['token' => 'recovered-token', 'redirect_url' => 'https://example.test/recovered']);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertCount(1, $creates);
        $this->assertSame($reference, $creates[0]['transaction_details']['order_id']);
        $payment = DB::table('payment_transactions')->where('id', $paymentId)->firstOrFail();
        $this->assertSame('PENDING', $payment->status);
        $this->assertStringContainsString('recovered-token', (string) $payment->public_payload);
    }
    public function test_recovery_adopts_existing_gateway_transaction_without_creating(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-adopt-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-ADOPT-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);

        $creates = 0;
        Http::fake(function ($request) use (&$creates, $reference, $order) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'transaction_id' => 'adopted-transaction',
                    'transaction_status' => 'settlement',
                    'status_code' => '200',
                    'order_id' => $reference,
                    'gross_amount' => number_format((int) $order->total_idr, 2, '.', ''),
                    'fraud_status' => 'accept',
                ]);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'must-not-happen', 'redirect_url' => 'https://example.test/x']);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertSame(0, $creates);
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame('PAID', DB::table('orders')->where('id', $order->id)->value('status'));
    }
    public function test_recovery_adopts_pending_gateway_transaction_out_of_creating(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-adopt-pending-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-ADOPT-PENDING-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);

        Http::fake(function ($request) use ($reference, $order) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'transaction_id' => 'adopted-pending-transaction',
                    'transaction_status' => 'pending',
                    'status_code' => '201',
                    'order_id' => $reference,
                    'gross_amount' => number_format((int) $order->total_idr, 2, '.', ''),
                    'fraud_status' => 'accept',
                ]);
            }

            return Http::response([], 404);
        });

        $outcome = app(PaymentService::class)->recoverStuckCreating($paymentId);

        $this->assertSame('adopted', $outcome);
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $order->id)->value('status'));
    }
    public function test_recovery_terminates_when_order_is_no_longer_payable(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-terminate-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'CANCELLED']);
        $reference = 'PAY-PR4-TERMINATE-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);

        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'must-not-happen', 'redirect_url' => 'https://example.test/x']);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertSame(0, $creates);
        $this->assertSame('FAILED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame('CANCELLED', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame(0, DB::table('order_events')
            ->where('order_id', $order->id)->where('event_type', 'PAYMENT_VERIFIED')->count());
    }
    public function test_recovery_leaves_fresh_creating_payments_untouched(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-fresh-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-FRESH-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr, staleMinutes: 2);

        $statusCalls = 0;
        Http::fake(function ($request) use (&$statusCalls) {
            if (str_contains($request->url(), '/status')) {
                $statusCalls++;
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertSame(0, $statusCalls);
        $this->assertSame('CREATING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }
    public function test_recovery_leaves_row_unchanged_when_gateway_inquiry_is_inconclusive(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-inconclusive-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-INCONCLUSIVE-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);

        Http::fake(['*' => Http::response(['error' => 'gateway unavailable'], 503)]);

        $outcome = app(PaymentService::class)->recoverStuckCreating($paymentId);

        $this->assertSame('inconclusive', $outcome);
        $this->assertSame('CREATING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }
    public function test_recovery_cannot_redrive_the_same_payment_twice(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-twice-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-TWICE-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);

        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'only-once-token', 'redirect_url' => 'https://example.test/once']);
            }

            return Http::response([], 404);
        });

        $service = app(PaymentService::class);
        $this->assertSame('redriven', $service->recoverStuckCreating($paymentId));
        $this->assertSame('skipped', $service->recoverStuckCreating($paymentId));
        $this->assertSame(1, $creates);
    }
    public function test_recovery_adopts_existing_doku_transaction_without_creating(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('virtual_account', 'DOKU', 2500, 0, null, [
            'api_path' => '/checkout/v1/payment',
            'public_paths' => ['payment_url' => 'response.payment.url'],
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'config_ciphertext' => [
                'client_id' => 'MCH-TEST',
                'secret_key' => 'doku-secret',
                'base_url' => 'https://api-sandbox.doku.test',
            ],
            'is_active' => true,
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'virtual_account', 'pr4-recovery-doku-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-DOKU-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow(
            $routeId, (int) $order->id, $reference, (int) $order->total_idr, 'DOKU', 'virtual_account'
        );

        $creates = 0;
        Http::fake(function ($request) use (&$creates, $reference, $order) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/orders/v1/status/')) {
                return Http::response([
                    'order' => ['invoice_number' => $reference, 'amount' => (int) $order->total_idr],
                    'transaction' => ['status' => 'SUCCESS'],
                ]);
            }
            if (str_contains($request->url(), '/checkout/v1/payment')) {
                $creates++;

                return Http::response(['error' => 'must not create'], 500);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertSame(0, $creates);
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame('PAID', DB::table('orders')->where('id', $order->id)->value('status'));
    }
    public function test_admin_check_payment_on_creating_payment_drives_recovery(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-admin-creating-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-ADMIN-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow(
            $routeId, (int) $order->id, $reference, (int) $order->total_idr, staleMinutes: 1
        );

        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'admin-recovered-token', 'redirect_url' => 'https://example.test/admin']);
            }

            return Http::response([], 404);
        });
        $admin = $this->createAdmin([
            'name' => 'Creating Checker',
            'email' => 'creating-check-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('payment-test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin')
            ->post('/admin/orders/'.$order->id.'/check-payment')
            ->assertRedirect();

        $this->assertSame(1, $creates);
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.payment.checked')
            ->where('target_id', (string) $order->id)->count());
    }
    public function test_recovery_redrives_doku_creation_when_gateway_reports_transaction_not_found(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('virtual_account', 'DOKU', 2500, 0, null, [
            'api_path' => '/checkout/v1/payment',
            'public_paths' => ['payment_url' => 'response.payment.url'],
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'config_ciphertext' => [
                'client_id' => 'MCH-TEST',
                'secret_key' => 'doku-secret',
                'base_url' => 'https://api-sandbox.doku.test',
            ],
            'is_active' => true,
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'virtual_account', 'pr4-recovery-doku-redrive-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-DOKU-REDRIVE-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow(
            $routeId, (int) $order->id, $reference, (int) $order->total_idr, 'DOKU', 'virtual_account'
        );

        $dokuSignature = new DokuSignature;
        $creates = [];
        Http::fake(function ($request) use ($dokuSignature, &$creates) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/orders/v1/status/')) {
                // DOKU Check Status contract for an unknown reference:
                // HTTP 404 with case code 4045301 (Transaction Not Found).
                return Http::response([
                    'error' => ['code' => '4045301', 'message' => 'Transaction Not Found'],
                ], 404);
            }
            if (str_contains($request->url(), '/checkout/v1/payment')) {
                $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
                $creates[] = $body;
                $responseData = [
                    'order' => ['invoice_number' => $body['order']['invoice_number']],
                    'response' => ['payment' => ['url' => 'https://sandbox.doku.test/checkout/recovered']],
                ];
                $responseBody = json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $timestamp = now('UTC')->format('Y-m-d\\TH:i:s\\Z');
                $signature = $dokuSignature->signResponse(
                    'MCH-TEST',
                    (string) (($request->header('Request-Id')[0] ?? '')),
                    $timestamp,
                    '/checkout/v1/payment',
                    $responseBody,
                    'doku-secret'
                );

                return Http::response($responseBody, 200, [
                    'Content-Type' => 'application/json',
                    'Response-Timestamp' => $timestamp,
                    'Signature' => $signature,
                ]);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertCount(1, $creates);
        $this->assertSame($reference, $creates[0]['order']['invoice_number']);
        $payment = DB::table('payment_transactions')->where('id', $paymentId)->firstOrFail();
        $this->assertSame('PENDING', $payment->status);
        $this->assertStringContainsString('recovered', (string) $payment->public_payload);
    }
    public function test_recovery_processes_creating_rows_older_than_the_reconciler_window(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-recovery-old-row-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-OLD-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, (int) $order->id, $reference, (int) $order->total_idr);
        // Older than the read-only reconciler's two-day window: recovery must
        // still reach this row, because nothing else ever moves it again.
        DB::table('payment_transactions')->where('id', $paymentId)->update([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'old-row-token', 'redirect_url' => 'https://example.test/old']);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertSame(1, $creates);
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }
}
