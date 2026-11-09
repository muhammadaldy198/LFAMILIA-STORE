<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tes callback dan notifikasi Midtrans: penyelesaian yang
 * terverifikasi dan idempoten, tantangan status dari server,
 * tanda tangan palsu, dan notifikasi ganda. Tes checkout
 * Midtrans ada di PaymentMidtransCheckoutTest; tes DOKU ada
 * di PaymentDokuTest dan PaymentDokuRecoveryTest.
 */
class PaymentMidtransTest extends TestCase
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

    public function test_midtrans_settlement_is_verified_idempotent_and_stale_pending_cannot_downgrade_paid(): void
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
                    'token' => 'snap-token-test',
                    'redirect_url' => 'https://sandbox.midtrans.test/pay',
                ]);
            }

            if (preg_match('#https://api\.sandbox\.midtrans\.com/v2/(.+)/status$#', $request->url(), $matches)) {
                return Http::response([
                    'transaction_id' => 'midtrans-transaction-1',
                    'transaction_status' => 'settlement',
                    'status_code' => '200',
                    'order_id' => rawurldecode($matches[1]),
                    'gross_amount' => '11078.00',
                    'fraud_status' => 'accept',
                    'settlement_time' => now()->format('Y-m-d H:i:s'),
                ]);
            }

            return Http::response([], 404);
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'qris',
            'm7-midtrans-order-0001'
        ))->assertCreated();
        $this->assertSame(11078, $checkout->json('total_idr'));

        $payment = $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-midtrans-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();
        $payment->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('instructions.token', 'snap-token-test')
            ->assertJsonMissing(['gateway_code' => 'MIDTRANS']);

        $row = DB::table('payment_transactions')
            ->where('idempotency_key', 'm7-midtrans-payment-0001')->firstOrFail();
        $notification = [
            'transaction_id' => 'midtrans-transaction-1',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => $row->merchant_reference,
            'gross_amount' => '11078.00',
            'fraud_status' => 'accept',
            'settlement_time' => '2026-09-30 10:00:00',
        ];
        $notification['signature_key'] = hash('sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'server-test'
        );

        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame('PAID', DB::table('orders')->where('id', $row->order_id)->value('status'));
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $row->order_id)
            ->where('event_type', 'PAYMENT_VERIFIED')->count());

        $stale = $notification;
        $stale['transaction_status'] = 'pending';
        unset($stale['settlement_time']);
        $this->postJson('/api/payments/midtrans/notification', $stale)->assertOk();

        $this->assertSame('PAID', DB::table('orders')->where('id', $row->order_id)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $row->id)->value('status'));
    }

    public function test_midtrans_callback_cannot_override_server_status_challenge(): void
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
                    'token' => 'snap-token-security',
                    'redirect_url' => 'https://sandbox.midtrans.test/security',
                ]);
            }

            if (preg_match('#https://api\.sandbox\.midtrans\.com/v2/(.+)/status$#', $request->url(), $matches)) {
                return Http::response([
                    'transaction_id' => 'midtrans-security-1',
                    'transaction_status' => 'pending',
                    'status_code' => '201',
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
            'm10-midtrans-order-0001'
        ))->assertCreated();

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm10-midtrans-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();

        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'm10-midtrans-payment-0001')->firstOrFail();
        $notification = [
            'transaction_id' => 'midtrans-security-1',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => $payment->merchant_reference,
            'gross_amount' => '11078.00',
            'fraud_status' => 'accept',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];
        $notification['signature_key'] = hash('sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'server-test'
        );

        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk();

        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $payment->order_id)->value('status'));
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $payment->id)->value('status'));
        $this->assertSame(0, DB::table('order_events')
            ->where('order_id', $payment->order_id)
            ->where('event_type', 'PAYMENT_VERIFIED')->count());
    }

    public function test_midtrans_rejects_fake_signature(): void
    {
        $this->catalog();
        Log::spy();
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test'],
            'is_active' => true,
        ]);

        $this->postJson('/api/payments/midtrans/notification', [
            'transaction_id' => 'fake',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => 'fake-order',
            'gross_amount' => '10000.00',
            'signature_key' => 'not-valid',
        ])->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->with('Payment webhook rejected', [
            'gateway' => 'MIDTRANS',
            'reason' => 'INVALID_SIGNATURE',
            'payment_transaction_id' => null,
        ]);

        $this->assertSame(0, DB::table('payment_callbacks')
            ->where('gateway_code', 'MIDTRANS')
            ->where('event_id', 'fake')->count());
    }

    public function test_midtrans_notifications_signed_with_any_configured_signature_key_are_accepted(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => [
                'server_key' => 'server-test',
                'signature_secret_key' => 'signature-secret-test',
                'previous_signature_secret_key' => 'previous-signature-secret-test',
                'is_production' => false,
            ],
            'is_active' => true,
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                return Http::response(['token' => 'sig-token', 'redirect_url' => 'https://example.test/pay']);
            }
            if (preg_match('#/v2/(.+)/status$#', $request->url(), $matches)) {
                $reference = rawurldecode($matches[1]);
                $local = DB::table('payment_transactions')
                    ->where('merchant_reference', $reference)->firstOrFail();

                return Http::response([
                    'transaction_id' => 'sig-transaction',
                    'transaction_status' => 'settlement',
                    'status_code' => '200',
                    'order_id' => $reference,
                    'gross_amount' => number_format((int) $local->amount_idr, 2, '.', ''),
                    'fraud_status' => 'accept',
                    'settlement_time' => '2026-11-09 10:00:00',
                ]);
            }

            return Http::response([], 404);
        });

        // Since 28 October 2026 Midtrans signs with the Signature Secret Key;
        // the previous Signature Secret Key covers the regeneration retry
        // overlap, and the Server Key is the legacy signer. All must verify.
        $signingKeys = [
            'ssk' => 'signature-secret-test',
            'prev' => 'previous-signature-secret-test',
            'legacy' => 'server-test',
        ];
        foreach ($signingKeys as $suffix => $signingKey) {
            $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
                $catalog['package_id'], 'qris', 'sig-key-order-'.$suffix
            ))->assertCreated();
            $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
                'idempotency_key' => 'sig-key-payment-'.$suffix,
                'access_code' => $checkout->json('access_code'),
            ])->assertOk();
            $payment = DB::table('payment_transactions')
                ->where('idempotency_key', 'sig-key-payment-'.$suffix)->firstOrFail();

            $gross = number_format((int) $payment->amount_idr, 2, '.', '');
            $notification = [
                'transaction_id' => 'sig-transaction',
                'transaction_status' => 'settlement',
                'status_code' => '200',
                'order_id' => $payment->merchant_reference,
                'gross_amount' => $gross,
                'fraud_status' => 'accept',
                'settlement_time' => '2026-11-09 10:00:00',
            ];
            $notification['signature_key'] = hash('sha512',
                $notification['order_id'].$notification['status_code'].$notification['gross_amount'].$signingKey
            );

            $this->postJson('/api/payments/midtrans/notification', $notification)
                ->assertOk()->assertJsonPath('status', 'ok');
            $this->assertSame('PAID', DB::table('payment_transactions')
                ->where('id', $payment->id)->value('status'));
        }
    }

    public function test_midtrans_notification_signed_with_an_unrelated_key_is_rejected_when_signature_secret_key_is_configured(): void
    {
        $this->catalog();
        Log::spy();
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => [
                'server_key' => 'server-test',
                'signature_secret_key' => 'signature-secret-test',
            ],
            'is_active' => true,
        ]);

        $notification = [
            'transaction_id' => 'fake',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => 'fake-order',
            'gross_amount' => '10000.00',
        ];
        $notification['signature_key'] = hash('sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'unrelated-key'
        );

        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->with('Payment webhook rejected', [
            'gateway' => 'MIDTRANS',
            'reason' => 'INVALID_SIGNATURE',
            'payment_transaction_id' => null,
        ]);
    }

    public function test_midtrans_notifications_with_identical_tuples_for_two_payments_are_both_processed(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                return Http::response(['token' => 'tuple-token', 'redirect_url' => 'https://example.test/pay']);
            }
            if (preg_match('#/v2/(.+)/status$#', $request->url(), $matches)) {
                $reference = rawurldecode($matches[1]);
                $local = DB::table('payment_transactions')
                    ->where('merchant_reference', $reference)->firstOrFail();

                return Http::response([
                    'transaction_id' => '',
                    'transaction_status' => 'pending',
                    'status_code' => '200',
                    'order_id' => $reference,
                    'gross_amount' => number_format((int) $local->amount_idr, 2, '.', ''),
                    'fraud_status' => 'accept',
                ]);
            }

            return Http::response([], 404);
        });

        $payments = [];
        foreach (['a', 'b'] as $suffix) {
            $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
                $catalog['package_id'], 'qris', 'pr4-tuple-order-'.$suffix
            ))->assertCreated();
            $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
                'idempotency_key' => 'pr4-tuple-payment-'.$suffix,
                'access_code' => $checkout->json('access_code'),
            ])->assertOk();
            $payments[$suffix] = DB::table('payment_transactions')
                ->where('idempotency_key', 'pr4-tuple-payment-'.$suffix)->firstOrFail();
        }

        foreach (['a', 'b'] as $suffix) {
            $payment = $payments[$suffix];
            $gross = number_format((int) $payment->amount_idr, 2, '.', '');
            // Identical dedupe tuples: no transaction_id, no settlement_time,
            // same transaction_status and status_code for both payments.
            $notification = [
                'transaction_status' => 'pending',
                'status_code' => '200',
                'order_id' => $payment->merchant_reference,
                'gross_amount' => $gross,
                'fraud_status' => 'accept',
            ];
            $notification['signature_key'] = hash('sha512',
                $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'server-test'
            );
            $this->postJson('/api/payments/midtrans/notification', $notification)
                ->assertOk()->assertJsonPath('status', 'ok');
        }

        $this->assertSame(2, DB::table('payment_callbacks')->where('gateway_code', 'MIDTRANS')->count());
    }

    public function test_midtrans_duplicate_notification_for_the_same_payment_is_still_rejected(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                return Http::response(['token' => 'dup-token', 'redirect_url' => 'https://example.test/pay']);
            }
            if (preg_match('#/v2/(.+)/status$#', $request->url(), $matches)) {
                $reference = rawurldecode($matches[1]);
                $local = DB::table('payment_transactions')
                    ->where('merchant_reference', $reference)->firstOrFail();

                return Http::response([
                    'transaction_id' => 'dup-transaction',
                    'transaction_status' => 'settlement',
                    'status_code' => '200',
                    'order_id' => $reference,
                    'gross_amount' => number_format((int) $local->amount_idr, 2, '.', ''),
                    'fraud_status' => 'accept',
                    'settlement_time' => '2026-11-07 10:00:00',
                ]);
            }

            return Http::response([], 404);
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-duplicate-order'
        ))->assertCreated();
        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'pr4-duplicate-payment',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk();
        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'pr4-duplicate-payment')->firstOrFail();

        $gross = number_format((int) $payment->amount_idr, 2, '.', '');
        $notification = [
            'transaction_id' => 'dup-transaction',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'order_id' => $payment->merchant_reference,
            'gross_amount' => $gross,
            'fraud_status' => 'accept',
            'settlement_time' => '2026-11-07 10:00:00',
        ];
        $notification['signature_key'] = hash('sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'server-test'
        );

        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/payments/midtrans/notification', $notification)
            ->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $payment->id)->value('status'));
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $payment->order_id)->where('event_type', 'PAYMENT_VERIFIED')->count());
    }
}
