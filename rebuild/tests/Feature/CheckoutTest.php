<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);
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
            'payment_channel_code' => 'manual_qris',
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
        $this->assertSame(1, DB::table('orders')
            ->where('idempotency_key', $payload['idempotency_key'])->count());

        $order = DB::table('orders')
            ->where('idempotency_key', $payload['idempotency_key'])->firstOrFail();
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(10000, (int) $order->cost_idr);
        $this->assertSame(1000, (int) $order->margin_idr);
        $this->assertSame(11000, (int) $order->total_idr);
        $this->assertSame($catalog['mapping_id'], $snapshot['provider']['mapping_id']);
        $this->assertSame('123456', $snapshot['customer_input']['user_id']);
        $this->assertSame('PENDING_PAYMENT', $order->status);
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $order->id)
            ->where('event_type', 'ORDER_CREATED')->count());
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

        $this->assertSame(0, DB::table('orders')
            ->where('idempotency_key', $payload['idempotency_key'])->count());
    }

    public function test_unknown_or_missing_product_input_is_rejected(): void
    {
        $catalog = $this->catalog();
        $payload = $this->guestPayload($catalog['package_id'], 'checkout-fields-0001');
        $payload['customer_input'] = ['unknown' => 'x'];

        $this->postJson('/checkout/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.unknown']);
        $this->assertSame(0, DB::table('orders')
            ->where('idempotency_key', $payload['idempotency_key'])->count());
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

        $payload = $this->guestPayload($catalog['package_id'], 'checkout-unavailable-0001');
        $this->postJson('/checkout/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package_id']);
        $this->assertSame(0, DB::table('orders')
            ->where('idempotency_key', $payload['idempotency_key'])->count());
    }

    public function test_nickname_service_outage_warns_but_does_not_block_checkout(): void
    {
        DB::table('nickname_game_codes')
            ->where('code', 'mobile-legends')
            ->update(['supports_nickname_check' => true]);

        $catalog = $this->catalog([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'mobile-legends',
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
        ]);
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://kokinpay.invalid',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);
        Http::fake(['https://kokinpay.invalid/*' => Http::response(['status' => false], 503)]);

        $response = $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'checkout-nickname-outage-0001'
        ))->assertCreated();

        $this->assertSame(11000, $response->json('total_idr'));
        $snapshot = json_decode(
            DB::table('orders')->where('idempotency_key', 'checkout-nickname-outage-0001')->value('snapshot'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertTrue($snapshot['nickname']['supported']);
        $this->assertFalse($snapshot['nickname']['verified']);
        $this->assertNotEmpty($snapshot['nickname']['warning']);
    }

    public function test_verified_invalid_nickname_is_rejected(): void
    {
        DB::table('nickname_game_codes')
            ->where('code', 'free-fire')
            ->update(['supports_nickname_check' => true]);

        $catalog = $this->catalog([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://kokinpay.invalid',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);
        Http::fake(['https://kokinpay.invalid/*' => Http::response(['status' => false], 400)]);

        $this->postJson('/checkout/orders', $this->guestPayload(
            $catalog['package_id'],
            'checkout-nickname-invalid-0001'
        ))->assertUnprocessable()->assertJsonValidationErrors(['customer_input.user_id']);

        $this->assertSame(0, DB::table('orders')
            ->where('idempotency_key', 'checkout-nickname-invalid-0001')->count());
    }

    public function test_same_idempotency_key_cannot_be_reused_for_different_checkout(): void
    {
        $catalog = $this->catalog();
        $payload = $this->guestPayload($catalog['package_id'], 'checkout-conflict-0001');
        $this->postJson('/checkout/orders', $payload)->assertCreated();

        $payload['customer_input']['user_id'] = 'DIFFERENT';
        $this->postJson('/checkout/orders', $payload)->assertStatus(409);
        $this->assertSame(1, DB::table('orders')
            ->where('idempotency_key', 'checkout-conflict-0001')->count());
    }

    public function test_inactive_package_cannot_be_quoted(): void
    {
        $catalog = $this->catalog();
        DB::table('product_packages')->where('id', $catalog['package_id'])->update(['is_active' => false]);

        $this->postJson('/checkout/quote', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => null,
            'guest_email' => 'buyer@example.test',
            'guest_phone' => '081234567890',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['package_id']);
    }

    public function test_public_quote_uses_server_price_without_exposing_provider_or_sku(): void
    {
        $catalog = $this->catalog();

        $this->postJson('/checkout/quote', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => null,
            'guest_email' => 'buyer@example.test',
            'guest_phone' => '081234567890',
        ])->assertOk()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonMissingPath('cost_idr')
            ->assertJsonMissingPath('margin_idr')
            ->assertJsonMissingPath('provider_mapping_id')
            ->assertJsonMissingPath('provider_code')
            ->assertJsonMissingPath('provider_sku')
            ->assertJsonMissingPath('external_sku')
            ->assertJsonMissingPath('buyer_sku_code');
    }

    public function test_customer_cannot_override_membership_or_voucher_discount_state(): void
    {
        $catalog = $this->catalog();
        $fields = [
            'member_discount_idr' => 999999,
            'member_tier_code' => 'MAFIA',
            'member_discount_bps' => 10000,
            'membership_tier_code' => 'MAFIA',
            'membership_discount_idr' => 999999,
            'voucher_id' => 999999,
            'voucher_discount_idr' => 999999,
            'voucher_redemption_id' => 999999,
        ];

        $quote = [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => null,
            'guest_email' => 'promo-tamper@example.test',
            'guest_phone' => '081234567890',
            ...$fields,
        ];

        $this->postJson('/checkout/quote', $quote)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($fields));

        $order = $this->guestPayload($catalog['package_id'], 'checkout-promo-tamper-0001') + $fields;

        $this->postJson('/checkout/orders', $order)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($fields));

        $this->assertSame(0, DB::table('orders')
            ->where('idempotency_key', 'checkout-promo-tamper-0001')
            ->count());
    }

    public function test_membership_and_voucher_discounts_are_recalculated_and_stack_server_side(): void
    {
        $catalog = $this->catalog();

        DB::table('membership_tiers')->where('code', 'SILVER')->update([
            'is_active' => true,
            'requirements' => json_encode(['minimum_spend_idr' => 0], JSON_THROW_ON_ERROR),
            'benefits' => json_encode(['discount_bps' => 1000], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Stage 74 Member',
            'email' => 'stage74-member-'.bin2hex(random_bytes(4)).'@example.test',
            'phone' => '081234567899',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $user->forceFill([
            'membership_mode' => 'MANUAL',
            'membership_override_code' => 'SILVER',
            'membership_tier_code' => 'SILVER',
        ])->save();

        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'STACK50',
            'discount_type' => 'PERCENT',
            'discount_value' => 50,
            'minimum_total_idr' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quote = $this->actingAs($user)->postJson('/checkout/quote', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => 'stack50',
        ])->assertOk()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('member_discount_idr', 1100)
            ->assertJsonPath('member_tier_code', 'SILVER')
            ->assertJsonPath('member_discount_bps', 1000)
            ->assertJsonPath('voucher_discount_idr', 4950)
            ->assertJsonPath('discount_idr', 6050)
            ->assertJsonPath('total_idr', 4950);

        $this->assertSame(4950, $quote->json('voucher_discount_idr'));

        $order = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456', 'zone_id' => '9876'],
            'voucher_code' => 'STACK50',
            'idempotency_key' => 'checkout-member-voucher-0001',
        ])->assertCreated()
            ->assertJsonPath('subtotal_idr', 11000)
            ->assertJsonPath('member_discount_idr', 1100)
            ->assertJsonPath('voucher_discount_idr', 4950)
            ->assertJsonPath('discount_idr', 6050)
            ->assertJsonPath('member_tier_code', 'SILVER')
            ->assertJsonPath('member_discount_bps', 1000)
            ->assertJsonPath('fee_idr', 0)
            ->assertJsonPath('total_idr', 4950)
            ->assertJsonPath('voucher_code', 'STACK50')
            ->assertJsonPath('payment_channel_code', 'manual_qris');

        $row = DB::table('orders')->where('order_number', $order->json('order_number'))->firstOrFail();
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1100, $snapshot['membership']['discount_idr']);
        $this->assertSame(4950, $snapshot['voucher']['discount_type'] === 'PERCENT'
            ? $snapshot['pricing']['voucher_discount_idr']
            : -1);
        $this->assertSame($voucherId, (int) $row->voucher_id);
    }

    public function test_voucher_picker_uses_checkout_scope_limits_and_customer_usage_rules(): void
    {
        $catalog = $this->catalog();
        $categoryId = $catalog['product']->category_id;

        $otherProduct = Product::create([
            'category_id' => $categoryId,
            'name' => 'Other Scoped Product '.bin2hex(random_bytes(3)),
            'slug' => 'other-scoped-product-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'MANUAL',
            'is_active' => true,
        ]);

        $scopedId = DB::table('vouchers')->insertGetId([
            'code' => 'SCOPEDOR',
            'discount_type' => 'FIXED',
            'discount_value' => 1500,
            'minimum_total_idr' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('voucher_products')->insert([
            'voucher_id' => $scopedId,
            'product_id' => $otherProduct->id,
        ]);
        DB::table('voucher_categories')->insert([
            'voucher_id' => $scopedId,
            'category_id' => $categoryId,
        ]);

        DB::table('vouchers')->insert([
            'code' => 'TOOHIGH',
            'discount_type' => 'FIXED',
            'discount_value' => 9000,
            'minimum_total_idr' => 999999,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vouchers')->insert([
            'code' => 'ONCEPERBUYER',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'per_customer_limit' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $used = $this->guestPayload($catalog['package_id'], 'checkout-picker-used-0001');
        $used['voucher_code'] = 'ONCEPERBUYER';
        $this->postJson('/checkout/orders', $used)->assertCreated();

        $response = $this->postJson('/checkout/vouchers', [
            'package_id' => $catalog['package_id'],
            'guest_email' => $used['guest_email'],
            'guest_phone' => $used['guest_phone'],
        ])->assertOk();

        $this->assertStringContainsString(
            'no-store',
            strtolower((string) $response->headers->get('Cache-Control'))
        );

        $codes = collect($response->json('vouchers'))->pluck('code');
        $this->assertTrue($codes->contains('SCOPEDOR'));
        $this->assertFalse($codes->contains('TOOHIGH'));
        $this->assertFalse($codes->contains('ONCEPERBUYER'));

        $scoped = collect($response->json('vouchers'))->firstWhere('code', 'SCOPEDOR');
        $this->assertSame(1500, $scoped['discount_idr']);
    }

    public function test_final_order_submit_revalidates_current_nominal_payment_and_voucher_state(): void
    {
        $catalog = $this->catalog();

        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'FINALCHECK',
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quotePayload = [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => 'FINALCHECK',
            'guest_email' => 'final-check@example.test',
            'guest_phone' => '081234567890',
        ];
        $this->postJson('/checkout/quote', $quotePayload)->assertOk();

        DB::table('vouchers')->where('id', $voucherId)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $orderPayload = $this->guestPayload($catalog['package_id'], 'checkout-final-voucher-0001');
        $orderPayload['voucher_code'] = 'FINALCHECK';
        $orderPayload['guest_email'] = 'final-check@example.test';

        $this->postJson('/checkout/orders', $orderPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voucher_code']);
        $this->assertDatabaseMissing('orders', ['idempotency_key' => 'checkout-final-voucher-0001']);

        DB::table('vouchers')->where('id', $voucherId)->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $orderPayload = $this->guestPayload($catalog['package_id'], 'checkout-final-nominal-0001');
        $this->postJson('/checkout/orders', $orderPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseMissing('orders', ['idempotency_key' => 'checkout-final-nominal-0001']);

        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
        DB::table('payment_routes')
            ->where('payment_channel_id', DB::table('payment_channels')->where('code', 'manual_qris')->value('id'))
            ->update(['is_active' => false, 'updated_at' => now()]);

        $orderPayload = $this->guestPayload($catalog['package_id'], 'checkout-final-payment-0001');
        $this->postJson('/checkout/orders', $orderPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_channel_code']);
        $this->assertDatabaseMissing('orders', ['idempotency_key' => 'checkout-final-payment-0001']);
    }
}
