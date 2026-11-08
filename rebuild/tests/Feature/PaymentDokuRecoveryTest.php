<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\Payment\DokuSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tes pemulihan pembuatan pembayaran DOKU: adopsi transaksi
 * DOKU yang sudah ada dan pembuatan ulang ketika DOKU
 * melaporkan transaksi tidak ditemukan. Tes pemulihan
 * gateway lain ada di PaymentCreationRecoveryTest.
 */
class PaymentDokuRecoveryTest extends TestCase
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
}
