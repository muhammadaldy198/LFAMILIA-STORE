<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\StoreAsset;
use App\Services\PaymentRoutingService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tes pembayaran seputar checkout dan sesi pembayaran: QRIS
 * manual, sesi pembayaran asal, kedaluwarsa pembayaran, aturan
 * rute dan kutipan harga, serta konfirmasi manual oleh admin.
 * Tes gateway eksternal, dompet, tautan bertanda tangan, topup,
 * dan pemulihan ada di class tes pembayaran masing-masing.
 */
class PaymentCheckoutTest extends TestCase
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

    public function test_manual_qris_never_adds_customer_fee_and_gateway_is_not_exposed(): void
    {
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS', 500);

        $response = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'm7-fee-server-side-0001'
        ))->assertCreated();

        $response->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('discount_idr', 0)
            ->assertJsonPath('fee_idr', 0)
            ->assertJsonPath('total_idr', 11000)
            ->assertJsonMissing(['gateway_code' => 'MANUAL_QRIS']);

        $order = DB::table('orders')->where('order_number', $response->json('order_number'))->firstOrFail();
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, (int) $order->fee_idr);
        $this->assertSame(11000, (int) $order->total_idr);
        $this->assertSame('manual_qris', $snapshot['payment']['channel_code']);
        $this->assertSame('MANUAL_QRIS', $snapshot['payment']['gateway_code']);
    }

    public function test_manual_qris_pending_alert_is_created_only_after_qr_payment_is_ready(): void
    {
        Storage::fake(config('media-library.disk_name', 'public'));
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS', 500);

        $asset = StoreAsset::where('key', 'manual_qris')->firstOrFail();
        $asset->forceFill(['is_active' => true])->save();
        $asset->clearMediaCollection('image');
        $asset->addMedia(UploadedFile::fake()->image('manual-qris.png', 600, 600))
            ->toMediaCollection('image', config('media-library.disk_name', 'public'));

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'stage-8-2-manual-order-0001'
        ))->assertCreated();

        $this->assertSame(0, DB::table('admin_notifications')
            ->where('event_type', 'payment.manual_qris.pending')->count());

        $payload = [
            'idempotency_key' => 'stage-8-2-manual-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ];
        $first = $this->postJson('/payments/orders/'.$checkout->json('order_number'), $payload)->assertOk();
        $second = $this->postJson('/payments/orders/'.$checkout->json('order_number'), $payload)->assertOk();

        $first->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('instructions.kind', 'manual_qris');
        $this->assertNotEmpty($first->json('instructions.qr_url'));
        $this->assertSame($first->json('payment_id'), $second->json('payment_id'));
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'payment.manual_qris.pending')->count());
    }

    public function test_checkout_quote_and_original_payment_session_remain_accessible(): void
    {
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS');

        $this->postJson('/checkout/quote', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => null,
            'guest_email' => 'pay@example.test',
            'guest_phone' => '081234567890',
        ])->assertOk();

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'manual_qris', 'payment-recovery-regression-0001'
        ))->assertCreated();

        $this->get('/payment?invoice='.urlencode((string) $checkout->json('order_number')))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Payment/Show'));
    }

    public function test_payment_page_ignores_redirect_status_query_and_uses_server_state(): void
    {
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS');

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'm8-redirect-status-order-0001'
        ))->assertCreated();

        $this->get('/payment?invoice='.urlencode((string) $checkout->json('order_number')).'&transaction_status=settlement&status_code=200')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payment/Show')
                ->where('order.status', 'PENDING_PAYMENT')
                ->where('payment', null)
            );
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

    public function test_admin_can_confirm_manual_qris_once(): void
    {
        $catalog = $this->catalog();
        $routeId = $this->route('manual_qris', 'MANUAL_QRIS');
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'm7-manual-order-0001'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->first();

        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $order->id,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MANUAL_QRIS',
            'channel_code' => 'manual_qris',
            'merchant_reference' => 'MANUAL-'.$order->id,
            'amount_idr' => $order->total_idr,
            'status' => 'PENDING',
            'idempotency_key' => 'm7-manual-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->createAdmin([
            'name' => 'Admin Payment',
            'email' => 'payadmin@example.test',
            'password' => Hash::make('StrongPassword123!'),
            'role' => 'ADMIN',
            'permissions' => ['payments.manage'],
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['admin.password_confirmed_at' => time()])
            ->post('/admin/payments/manual/'.$paymentId.'/confirm')
            ->assertRedirect();

        $this->assertSame('PAID', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.manual.confirmed')->count());
    }

    public function test_customer_cannot_override_internal_payment_route_or_gateway(): void
    {
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS', 500);

        $payload = [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => null,
            'guest_email' => 'route-tamper@example.test',
            'guest_phone' => '081234567890',
            'payment_route_id' => 999999,
            'payment_gateway_id' => 999999,
            'gateway_code' => 'MIDTRANS',
            'gateway_kind' => 'EXTERNAL',
            'provider_channel' => 'forged-channel',
        ];

        $this->postJson('/checkout/quote', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'payment_route_id',
                'payment_gateway_id',
                'gateway_code',
                'gateway_kind',
                'provider_channel',
            ]);

        $orderPayload = $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'm7-route-tamper-order-0001'
        ) + [
            'payment_route_id' => 999999,
            'payment_gateway_id' => 999999,
            'gateway_code' => 'MIDTRANS',
            'gateway_kind' => 'EXTERNAL',
            'provider_channel' => 'forged-channel',
        ];

        $this->postJson('/checkout/orders', $orderPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'payment_route_id',
                'payment_gateway_id',
                'gateway_code',
                'gateway_kind',
                'provider_channel',
            ]);

        $this->assertSame(0, DB::table('orders')
            ->where('idempotency_key', 'm7-route-tamper-order-0001')
            ->count());
    }

    public function test_guest_wallet_is_visible_only_as_unavailable_and_cannot_be_quoted(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $channels = app(PaymentRoutingService::class)->publicOrderChannels(null);
        $wallet = collect($channels)->firstWhere('code', 'saldo');

        $this->assertIsArray($wallet);
        $this->assertFalse($wallet['available']);
        $this->assertArrayNotHasKey('gateway_code', $wallet);
        $this->assertArrayNotHasKey('route_id', $wallet);

        $this->postJson('/checkout/quote', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'voucher_code' => null,
            'guest_email' => 'guest-wallet@example.test',
            'guest_phone' => '081234567890',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_channel_code']);
    }

    public function test_maintenance_gateway_removes_channel_from_public_order_choices(): void
    {
        $this->route('manual_qris', 'MANUAL_QRIS');
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
            'is_maintenance' => true,
            'updated_at' => now(),
        ]);

        $channels = app(PaymentRoutingService::class)->publicOrderChannels(null);

        $this->assertNull(collect($channels)->firstWhere('code', 'manual_qris'));
    }

    public function test_quote_and_created_order_share_the_same_server_financial_summary(): void
    {
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS', 500);

        DB::table('vouchers')->insert([
            'code' => 'SUMMARY1000',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quote = $this->postJson('/checkout/quote', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => 'SUMMARY1000',
            'guest_email' => 'summary@example.test',
            'guest_phone' => '081234567890',
        ])->assertOk()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('member_discount_idr', 0)
            ->assertJsonPath('voucher_discount_idr', 1000)
            ->assertJsonPath('discount_idr', 1000)
            ->assertJsonPath('fee_idr', 0)
            ->assertJsonPath('total_idr', 10000);

        $payload = $this->guestCheckout(
            $catalog['package_id'],
            'manual_qris',
            'm7-summary-order-0001'
        );
        $payload['voucher_code'] = 'SUMMARY1000';
        $payload['guest_email'] = 'summary@example.test';

        $order = $this->postJson('/checkout/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('member_discount_idr', 0)
            ->assertJsonPath('voucher_discount_idr', 1000)
            ->assertJsonPath('discount_idr', 1000)
            ->assertJsonPath('fee_idr', 0)
            ->assertJsonPath('total_idr', 10000)
            ->assertJsonPath('voucher_code', 'SUMMARY1000')
            ->assertJsonPath('payment_channel_code', 'manual_qris')
            ->assertJsonMissingPath('cost_idr')
            ->assertJsonMissingPath('margin_idr')
            ->assertJsonMissingPath('gateway_code')
            ->assertJsonMissingPath('provider_mapping_id');

        foreach ([
            'subtotal_idr',
            'member_discount_idr',
            'voucher_discount_idr',
            'discount_idr',
            'fee_idr',
            'total_idr',
            'voucher_code',
            'payment_channel_code',
        ] as $field) {
            $this->assertSame($quote->json($field), $order->json($field));
        }

        $persisted = DB::table('orders')->where('order_number', $order->json('order_number'))->firstOrFail();
        $this->assertSame(1000, (int) $persisted->discount_idr);
        $this->assertSame(0, (int) $persisted->fee_idr);
        $this->assertSame(10000, (int) $persisted->total_idr);
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

    public function test_public_channel_is_hidden_when_external_gateway_credentials_are_not_ready(): void
    {
        $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $midtransGatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        DB::table('payment_routes')->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', '<>', $midtransGatewayId)
            ->update(['is_active' => false, 'updated_at' => now()]);

        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => [],
            'is_active' => true,
        ]);

        $channels = app(PaymentRoutingService::class)->publicOrderChannels(null);

        $this->assertNull(collect($channels)->firstWhere('code', 'qris'));
    }

    // ---------------------------------------------------------------
    // Audit PR 4: locked-state revalidation, CREATING recovery,
    // payment-scoped Midtrans dedupe.
    // ---------------------------------------------------------------
}
