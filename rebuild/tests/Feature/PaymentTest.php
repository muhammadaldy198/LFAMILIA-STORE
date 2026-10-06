<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\StoreAsset;
use App\Models\User;
use App\Services\Payment\DokuSignature;
use App\Services\PaymentRoutingService;
use App\Services\PaymentService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentTest extends TestCase
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

    public function test_payment_channel_fee_is_server_side_and_gateway_is_not_exposed(): void
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
            ->assertJsonPath('fee_idr', 500)
            ->assertJsonPath('total_idr', 11500)
            ->assertJsonMissing(['gateway_code' => 'MANUAL_QRIS']);

        $order = DB::table('orders')->where('order_number', $response->json('order_number'))->firstOrFail();
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(500, (int) $order->fee_idr);
        $this->assertSame(11500, (int) $order->total_idr);
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
                    'gross_amount' => '11077.00',
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
        $this->assertSame(11077, $checkout->json('total_idr'));

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
            'gross_amount' => '11077.00',
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
                    'gross_amount' => '11077.00',
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
            'gross_amount' => '11077.00',
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
                    'gross_amount' => '11077.00',
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
            'gross_amount' => '11077.00',
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

        $this->assertSame(0, DB::table('payment_callbacks')
            ->where('gateway_code', 'MIDTRANS')
            ->where('event_id', 'fake')->count());
    }

    public function test_doku_direct_request_and_signed_callback_mark_order_paid(): void
    {
        $catalog = $this->catalog();
        $this->route('virtual_account', 'DOKU', 2500, 0, null, [
            'api_path' => '/doku-virtual-account/v2/payment-code',
            'public_paths' => ['va_number' => 'virtual_account_info.virtual_account_number'],
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'config_ciphertext' => [
                'client_id' => 'MCH-TEST',
                'secret_key' => 'doku-secret',
                'base_url' => 'https://api-sandbox.doku.test',
            ],
            'is_active' => true,
        ]);

        $dokuSignature = new DokuSignature;
        Http::fake(function ($request) use ($dokuSignature) {
            $requestId = (string) (($request->header('Request-Id')[0] ?? ''));
            $clientId = (string) (($request->header('Client-Id')[0] ?? ''));
            $this->assertSame('MCH-TEST', $clientId);
            $this->assertStringStartsWith('HMACSHA256=', (string) (($request->header('Signature')[0] ?? '')));

            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $responseData = [
                'order' => ['invoice_number' => $body['order']['invoice_number']],
                'virtual_account_info' => ['virtual_account_number' => '88000000123456'],
            ];
            $responseBody = json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $timestamp = now('UTC')->format('Y-m-d\\TH:i:s\\Z');
            $signature = $dokuSignature->signResponse(
                'MCH-TEST',
                $requestId,
                $timestamp,
                '/doku-virtual-account/v2/payment-code',
                $responseBody,
                'doku-secret'
            );

            return Http::response($responseBody, 200, [
                'Content-Type' => 'application/json',
                'Response-Timestamp' => $timestamp,
                'Signature' => $signature,
            ]);
        });

        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'],
            'virtual_account',
            'm7-doku-order-0001'
        ))->assertCreated();
        $this->assertSame(13500, $checkout->json('total_idr'));

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-doku-payment-0001',
            'access_code' => $checkout->json('access_code'),
        ])->assertOk()
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('instructions.va_number', '88000000123456');

        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'm7-doku-payment-0001')->firstOrFail();
        $payload = [
            'service' => ['id' => 'VIRTUAL_ACCOUNT'],
            'transaction' => ['status' => 'SUCCESS', 'date' => now('UTC')->format('Y-m-d\\TH:i:s\\Z')],
            'order' => ['invoice_number' => $payment->merchant_reference, 'amount' => 13500],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $requestId = 'doku-event-0001';
        $timestamp = now('UTC')->format('Y-m-d\\TH:i:s\\Z');
        $signature = $dokuSignature->sign(
            'MCH-TEST',
            $requestId,
            $timestamp,
            '/api/payments/doku/notification',
            $raw,
            'doku-secret'
        );

        $response = $this->call('POST', '/api/payments/doku/notification', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CLIENT_ID' => 'MCH-TEST',
            'HTTP_REQUEST_ID' => $requestId,
            'HTTP_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_SIGNATURE' => $signature,
        ], $raw);
        $response->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame('PAID', DB::table('orders')->where('id', $payment->order_id)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $payment->id)->value('status'));
    }

    public function test_wallet_payment_debits_once_on_idempotent_retry(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Wallet Buyer',
            'email' => 'wallet@example.test',
            'phone' => '081234567891',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        DB::table('wallets')->where('user_id', $user->id)->update(['balance_idr' => 50000]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'm7-wallet-order-0001',
        ])->assertCreated();

        $first = $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-wallet-payment-0001',
        ])->assertOk();
        $second = $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-wallet-payment-0001',
        ])->assertOk();

        $first->assertJsonPath('status', 'PAID');
        $second->assertJsonPath('status', 'PAID');
        $this->assertSame(39000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
        $walletId = DB::table('wallets')->where('user_id', $user->id)->value('id');
        $this->assertSame(1, DB::table('wallet_ledger')
            ->where('wallet_id', $walletId)->where('source', 'CHECKOUT')->count());
        $this->assertSame('PAID', DB::table('orders')
            ->where('order_number', $checkout->json('order_number'))->value('status'));
    }

    public function test_wallet_payment_cannot_overdraw_or_create_ledger_when_balance_is_insufficient(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Low Balance Buyer',
            'email' => 'low-balance@example.test',
            'phone' => '081234567893',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        DB::table('wallets')->where('user_id', $user->id)->update(['balance_idr' => 5000]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '999999'],
            'voucher_code' => null,
            'idempotency_key' => 'm11-wallet-low-order-0001',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm11-wallet-low-payment-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $this->assertSame(5000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('wallet_id', DB::table('wallets')->where('user_id', $user->id)->value('id'))
            ->where('source', 'CHECKOUT')
            ->count());
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')
            ->where('order_number', $checkout->json('order_number'))->value('status'));
        $this->assertSame('REJECTED', DB::table('payment_transactions')
            ->where('idempotency_key', 'm11-wallet-low-payment-0001')->value('status'));
    }

    public function test_wallet_checkout_is_blocked_while_topup_refund_review_is_open(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Refund Hold Buyer',
            'email' => 'refund-hold@example.test',
            'phone' => '081234567895',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_idr' => 50000]);
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        DB::table('wallet_topups')->insert([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'REFUND_REVIEW',
            'idempotency_key' => 'refund-hold-topup-0001',
            'request_fingerprint' => hash('sha256', 'refund-hold-topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '777777'],
            'voucher_code' => null,
            'idempotency_key' => 'refund-hold-order-0001',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'refund-hold-payment-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $this->assertSame(50000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('wallet_id', $wallet->id)
            ->where('source', 'CHECKOUT')
            ->count());
    }

    public function test_wallet_topup_paid_callback_credit_is_idempotent_and_fee_is_not_credited(): void
    {
        $user = User::create([
            'name' => 'Topup Buyer',
            'email' => 'topup@example.test',
            'phone' => '081234567892',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');

        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'PENDING_PAYMENT',
            'idempotency_key' => 'm7-topup-direct-0001',
            'request_fingerprint' => hash('sha256', 'topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'TOPUP-TEST-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'idempotency_key' => 'm7-topup-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $states = app(PaymentStateService::class);
        $states->apply($paymentId, 'PAID');
        $states->apply($paymentId, 'PAID');

        $this->assertSame(10000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(1, DB::table('wallet_ledger')->where('source', 'TOPUP')->count());
        $this->assertSame('PAID', DB::table('wallet_topups')->where('id', $topupId)->value('status'));

        $states->apply($paymentId, 'REFUNDED');
        $states->apply($paymentId, 'PAID');

        $this->assertSame(0, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(1, DB::table('wallet_ledger')->where('source', 'REFUND')->count());
        $this->assertSame('REFUNDED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('REFUNDED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }

    public function test_refunded_topup_with_spent_balance_enters_review_and_can_be_reconciled_safely(): void
    {
        $user = User::create([
            'name' => 'Refund Review Buyer',
            'email' => 'refund-review@example.test',
            'phone' => '081234567894',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');

        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'PENDING_PAYMENT',
            'idempotency_key' => 'refund-review-topup-0001',
            'request_fingerprint' => hash('sha256', 'refund-review-topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'REFUND-REVIEW-TEST-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'idempotency_key' => 'refund-review-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $states = app(PaymentStateService::class);
        $states->apply($paymentId, 'PAID');
        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance_idr' => 3000,
            'updated_at' => now(),
        ]);

        $result = $states->apply($paymentId, 'REFUNDED');
        $this->assertSame('REFUND_REVIEW', $result['status']);
        $this->assertSame('REFUND_REVIEW', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('REFUNDED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('idempotency_key', 'wallet-topup-refund:'.$paymentId)->count());

        try {
            $states->resolveTopupRefundReview($topupId, 42);
            $this->fail('Refund review must not create a negative wallet balance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance_idr' => 10000,
            'updated_at' => now(),
        ]);
        $resolved = $states->resolveTopupRefundReview($topupId, 42);

        $this->assertSame('REFUNDED', $resolved['status']);
        $this->assertSame(0, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame('REFUNDED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $ledger = DB::table('wallet_ledger')
            ->where('idempotency_key', 'wallet-topup-refund:'.$paymentId)
            ->firstOrFail();
        $this->assertSame(-10000, (int) $ledger->amount_idr);
        $this->assertSame('admin_user', $ledger->actor_type);
        $this->assertSame('42', $ledger->actor_id);

        $again = $states->resolveTopupRefundReview($topupId, 42);
        $this->assertSame('ALREADY_REFUNDED', $again['result']);
        $this->assertSame(1, DB::table('wallet_ledger')
            ->where('idempotency_key', 'wallet-topup-refund:'.$paymentId)->count());
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
        $this->postJson('/payments/orders/'.$checkout->json('order_number'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $this->postJson('/payments/orders/'.$checkout->json('order_number'), $payload)
            ->assertOk()->assertJsonPath('status', 'UNKNOWN');

        $orderId = DB::table('orders')->where('order_number', $checkout->json('order_number'))->value('id');
        $paymentId = DB::table('payment_transactions')->where('order_id', $orderId)->value('id');

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

        $admin = tap(AdminUser::create([
            'name' => 'Admin Payment',
            'email' => 'payadmin@example.test',
            'password' => Hash::make('StrongPassword123!'),
        ]), fn ($admin) => $admin->forceFill([
            'role' => 'ADMIN',
            'permissions' => ['payments.manage'],
            'is_active' => true,
        ])->save());

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
            ->assertJsonPath('fee_idr', 500)
            ->assertJsonPath('total_idr', 10500);

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
            ->assertJsonPath('fee_idr', 500)
            ->assertJsonPath('total_idr', 10500)
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
        $this->assertSame(500, (int) $persisted->fee_idr);
        $this->assertSame(10500, (int) $persisted->total_idr);
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
}
