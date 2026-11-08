<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\Payment\DokuSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tes checkout DOKU: permintaan checkout dan callback
 * bertanda tangan menandai pesanan lunas. Tes pemulihan
 * pembuatan DOKU ada di PaymentDokuRecoveryTest.
 */
class PaymentDokuTest extends TestCase
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

    public function test_doku_checkout_request_and_signed_callback_mark_order_paid(): void
    {
        $catalog = $this->catalog();
        $this->route('virtual_account', 'DOKU', 2500, 0, null, [
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

        $dokuSignature = new DokuSignature;
        $gatewayStatus = 'PENDING';
        $gatewayAmountMismatch = false;
        Http::fake(function ($request) use ($dokuSignature, &$gatewayStatus, &$gatewayAmountMismatch) {
            $requestId = (string) (($request->header('Request-Id')[0] ?? ''));
            $clientId = (string) (($request->header('Client-Id')[0] ?? ''));
            $this->assertSame('MCH-TEST', $clientId);
            $this->assertStringStartsWith('HMACSHA256=', (string) (($request->header('Signature')[0] ?? '')));

            if ($request->method() === 'GET') {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                $this->assertStringStartsWith('/orders/v1/status/', $path);
                $this->assertSame(
                    $dokuSignature->signGet(
                        $clientId, $requestId,
                        (string) ($request->header('Request-Timestamp')[0] ?? ''),
                        $path, 'doku-secret'
                    ),
                    (string) ($request->header('Signature')[0] ?? '')
                );
                $reference = rawurldecode(substr($path, strlen('/orders/v1/status/')));
                $local = DB::table('payment_transactions')
                    ->where('merchant_reference', $reference)->firstOrFail();

                return Http::response([
                    'order' => [
                        'invoice_number' => $reference,
                        'amount' => $gatewayAmountMismatch ? ((int) $local->amount_idr + 1) : (int) $local->amount_idr,
                    ],
                    'transaction' => ['status' => $gatewayStatus],
                ]);
            }

            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $responseData = [
                'order' => ['invoice_number' => $body['order']['invoice_number']],
                'response' => ['payment' => ['url' => 'https://sandbox.doku.test/checkout/order-123']],
            ];
            $responseBody = json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $timestamp = now('UTC')->format('Y-m-d\\TH:i:s\\Z');
            $signature = $dokuSignature->signResponse(
                'MCH-TEST',
                $requestId,
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
            ->assertJsonPath('instructions.payment_url', 'https://sandbox.doku.test/checkout/order-123');

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
        $response->assertStatus(503);
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $payment->order_id)->value('status'));

        $gatewayAmountMismatch = true;
        $gatewayStatus = 'SUCCESS';
        $this->call('POST', '/api/payments/doku/notification', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CLIENT_ID' => 'MCH-TEST',
            'HTTP_REQUEST_ID' => $requestId,
            'HTTP_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_SIGNATURE' => $signature,
        ], $raw)->assertStatus(422);
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $payment->order_id)->value('status'));

        $gatewayAmountMismatch = false;
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
}
