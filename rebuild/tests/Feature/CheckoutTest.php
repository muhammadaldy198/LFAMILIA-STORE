<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{product:Product,package_id:int,mapping_id:int}
     */
    private function catalog(array $productOverrides = []): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Checkout Game '.bin2hex(random_bytes(3)),
            'slug' => 'checkout-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
            ...$productOverrides,
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
            'code' => 'PKG'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $product, 'package_id' => $package->id, 'mapping_id' => $mappingId];
    }

    private function guestPayload(int $packageId, string $key): array
    {
        return [
            'package_id' => $packageId,
            'customer_input' => ['user_id' => '123456', 'zone_id' => '9876'],
            'voucher_code' => null,
            'guest_email' => 'buyer@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => $key,
        ];
    }

    public function test_server_calculates_price_and_idempotent_guest_retry_returns_same_order_and_access_code(): void
    {
        $catalog = $this->catalog();
        $payload = $this->guestPayload($catalog['package_id'], 'checkout-idempotency-0001');

        $first = $this->postJson('/checkout/orders', $payload)->assertCreated();
        $second = $this->postJson('/checkout/orders', $payload)->assertOk();

        $this->assertSame($first->json('order_number'), $second->json('order_number'));
        $this->assertSame($first->json('access_code'), $second->json('access_code'));
        $this->assertSame(11000, $first->json('total_idr'));
        $this->assertSame(1, DB::table('orders')->count());

        $order = DB::table('orders')->first();
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(10000, (int) $order->cost_idr);
        $this->assertSame(1000, (int) $order->margin_idr);
        $this->assertSame(11000, (int) $order->total_idr);
        $this->assertSame($catalog['mapping_id'], $snapshot['provider']['mapping_id']);
        $this->assertSame('123456', $snapshot['customer_input']['user_id']);
        $this->assertSame('PENDING_PAYMENT', $order->status);
        $this->assertSame(1, DB::table('order_events')->where('event_type', 'ORDER_CREATED')->count());
    }

    public function test_client_price_provider_and_sku_are_rejected(): void
    {
        $catalog = $this->catalog();
        $payload = $this->guestPayload($catalog['package_id'], 'checkout-manipulation-0001');
        $payload['total_idr'] = 1;
        $payload['provider_mapping_id'] = 999;
        $payload['buyer_sku_code'] = 'FORGED';

        $this->postJson('/checkout/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total_idr', 'provider_mapping_id', 'buyer_sku_code']);

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_unknown_or_missing_product_input_is_rejected(): void
    {
        $catalog = $this->catalog();
        $payload = $this->guestPayload($catalog['package_id'], 'checkout-fields-0001');
        $payload['customer_input'] = ['unknown' => 'x'];

        $this->postJson('/checkout/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.unknown']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_voucher_is_reserved_atomically_and_quota_cannot_be_reused(): void
    {
        $catalog = $this->catalog();
        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'ONLYONE',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'total_quota' => 1,
            'per_customer_limit' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = $this->guestPayload($catalog['package_id'], 'checkout-voucher-0001');
        $first['voucher_code'] = 'onlyone';
        $this->postJson('/checkout/orders', $first)
            ->assertCreated()
            ->assertJsonPath('total_idr', 10000);

        $second = $this->guestPayload($catalog['package_id'], 'checkout-voucher-0002');
        $second['guest_email'] = 'other@example.test';
        $second['guest_phone'] = '089999999999';
        $second['voucher_code'] = 'ONLYONE';
        $this->postJson('/checkout/orders', $second)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voucher_code']);

        $this->assertSame(1, DB::table('voucher_redemptions')->where('voucher_id', $voucherId)->count());
        $this->assertSame('RESERVED', DB::table('voucher_redemptions')->where('voucher_id', $voucherId)->value('status'));
    }

    public function test_package_without_eligible_active_mapping_cannot_checkout(): void
    {
        $catalog = $this->catalog();
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'max_price_idr' => 9000,
        ]);

        $this->postJson('/checkout/orders', $this->guestPayload($catalog['package_id'], 'checkout-unavailable-0001'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package_id']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_nickname_service_outage_warns_but_does_not_block_checkout(): void
    {
        $catalog = $this->catalog([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'mobile-legends',
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
        ]);
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => ['api_key' => 'test-secret', 'base_url' => 'https://kokinpay.invalid'],
            'is_active' => true,
        ]);
        Http::fake(['https://kokinpay.invalid/*' => Http::response(['status' => false], 503)]);

        $response = $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'checkout-nickname-outage-0001'
        ))->assertCreated();

        $this->assertSame(11000, $response->json('total_idr'));
        $snapshot = json_decode(DB::table('orders')->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($snapshot['nickname']['supported']);
        $this->assertFalse($snapshot['nickname']['verified']);
        $this->assertNotEmpty($snapshot['nickname']['warning']);
    }

    public function test_verified_invalid_nickname_is_rejected(): void
    {
        $catalog = $this->catalog([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => ['api_key' => 'test-secret', 'base_url' => 'https://kokinpay.invalid'],
            'is_active' => true,
        ]);
        Http::fake(['https://kokinpay.invalid/*' => Http::response(['status' => false], 400)]);

        $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'checkout-nickname-invalid-0001'
        ))->assertUnprocessable()->assertJsonValidationErrors(['customer_input.user_id']);

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_same_idempotency_key_cannot_be_reused_for_different_checkout(): void
    {
        $catalog = $this->catalog();
        $payload = $this->guestPayload($catalog['package_id'], 'checkout-conflict-0001');
        $this->postJson('/checkout/orders', $payload)->assertCreated();

        $payload['customer_input']['user_id'] = 'DIFFERENT';
        $this->postJson('/checkout/orders', $payload)->assertStatus(409);
        $this->assertSame(1, DB::table('orders')->count());
    }
}
