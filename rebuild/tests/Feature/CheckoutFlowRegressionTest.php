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
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CheckoutFlowRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake([SendTransactionalEmailJob::class]);
    }

    public function test_guest_customer_can_complete_checkout_to_payment_page_with_one_server_price_snapshot(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Final Checkout Regression',
            'slug' => 'final-checkout-regression',
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'nickname_check_enabled' => false,
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $product->fields()->create([
            'field_key' => 'zone_id',
            'label' => 'Zone ID',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $package = $product->packages()->create([
            'code' => 'FINAL100',
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'FINAL-SKU-100',
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_gateways')->where('code', 'MIDTRANS')->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        DB::table('payment_channels')->where('code', 'qris')->update([
            'is_active' => true,
            'fee_flat_idr' => 0,
            'fee_percent_bps' => 70,
            'updated_at' => now(),
        ]);
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $gatewayId = DB::table('payment_gateways')->where('code', 'MIDTRANS')->value('id');
        DB::table('payment_routes')->updateOrInsert(
            ['payment_channel_id' => $channelId, 'payment_gateway_id' => $gatewayId],
            [
                'provider_channel' => 'qris',
                'configuration' => null,
                'priority' => 0,
                'fee_flat_idr' => 0,
                'fee_percent_bps' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([
                    'token' => 'stage-78-snap-token',
                    'redirect_url' => 'https://sandbox.midtrans.test/stage-78',
                ]);
            }

            return Http::response([], 404);
        });

        $this->get('/catalog/'.$product->slug)
            ->assertOk()
            ->assertSee('Final Checkout Regression')
            ->assertSee('100 Diamonds')
            ->assertSee('User ID');

        $quotePayload = [
            'package_id' => $package->id,
            'payment_channel_code' => 'qris',
            'voucher_code' => null,
            'guest_email' => 'stage78@example.test',
            'guest_phone' => '081234567890',
        ];
        $quote = $this->postJson('/checkout/quote', $quotePayload)
            ->assertOk()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('fee_idr', 78)
            ->assertJsonPath('total_idr', 11078)
            ->assertJsonPath('payment_channel_code', 'qris');

        // Customers can see the full, gateway-inclusive total before typing contact details.
        $earlyQuote = $this->postJson('/checkout/quote', [
            'package_id' => $package->id,
            'payment_channel_code' => 'qris',
        ])->assertOk()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('total_idr', 11078);
        $this->assertSame($quote->json('total_idr'), $earlyQuote->json('total_idr'));

        // A real order still requires verified buyer contact information.
        $this->postJson('/checkout/orders', [
            'package_id' => $package->id,
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '123456'],
            'idempotency_key' => 'stage-78-missing-contact',
        ])->assertUnprocessable()->assertJsonValidationErrors(['guest_email', 'guest_phone']);

        $order = $this->postJson('/checkout/orders', [
            ...$quotePayload,
            'customer_input' => ['user_id' => '123456', 'zone_id' => '9876'],
            'idempotency_key' => 'stage-78-order-0001',
        ])->assertCreated()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('fee_idr', 78)
            ->assertJsonPath('total_idr', 11078)
            ->assertJsonPath('payment_channel_code', 'qris');

        foreach (['subtotal_idr', 'fee_idr', 'total_idr', 'payment_channel_code'] as $field) {
            $this->assertSame($quote->json($field), $order->json($field));
        }

        $payment = $this->postJson('/payments/orders/'.$order->json('order_number'), [
            'idempotency_key' => 'stage-78-payment-0001',
            'access_code' => $order->json('access_code'),
        ])->assertOk()
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('channel_code', 'qris')
            ->assertJsonPath('amount_idr', 11078)
            ->assertJsonPath('instructions.token', 'stage-78-snap-token');

        $this->get('/payment?invoice='.$order->json('order_number'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payment/Show')
                ->where('order.order_number', $order->json('order_number'))
                ->where('order.total_idr', 11078)
                ->where('payment.id', $payment->json('payment_id'))
                ->where('payment.status', 'PENDING')
                ->where('payment.channel_code', 'qris')
                ->where('payment.amount_idr', 11078)
                ->where('payment.instructions.token', 'stage-78-snap-token')
                ->etc());

        $this->assertSame(1, DB::table('orders')->where('idempotency_key', 'stage-78-order-0001')->count());
        $this->assertSame(1, DB::table('payment_transactions')->where('idempotency_key', 'stage-78-payment-0001')->count());
        Http::assertSentCount(1);
    }
}
