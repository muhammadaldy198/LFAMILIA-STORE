<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tes pemulihan pembayaran pesanan yang tersangkut di
 * keadaan pembuatan (creating): pembuatan ulang yang aman,
 * adopsi transaksi gateway yang sudah ada, penghentian yang
 * tidak lagi bisa dibayar, dan baris lama yang diproses
 * perekonsiliasi. Tes DOKU ada di PaymentDokuRecoveryTest;
 * tes validasi awal pembuatan ada di PaymentStartValidationTest.
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
