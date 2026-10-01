<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\PaymentRoutingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorefrontParityTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Parity Super',
            'email' => 'parity-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{product:Product,package_id:int}
     */
    private function catalog(): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Parity Game '.bin2hex(random_bytes(3)),
            'publisher' => 'Parity Publisher',
            'slug' => 'parity-game-'.bin2hex(random_bytes(4)),
            'description' => 'Parity description',
            'manual_instructions' => 'INTERNAL ONLY',
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
            'code' => 'PARITY'.bin2hex(random_bytes(2)),
            'name' => '100 Diamonds',
            'group_name' => 'Diamonds',
            'nominal_value' => 100,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $product->notices()->create([
            'title' => 'Periksa ID',
            'body' => 'Pastikan tujuan sudah benar.',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'PARITY-SKU-'.bin2hex(random_bytes(3)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $product, 'package_id' => $package->id];
    }

    private function guestOrder(Product $product, int $packageId, string $number, string $phone = '+62 812-3456-7890'): int
    {
        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'guest_email' => 'guest@example.test',
            'guest_phone' => $phone,
            'product_id' => $product->id,
            'product_package_id' => $packageId,
            'status' => 'SUCCESS',
            'customer_input' => json_encode(['user_id' => '123456']),
            'snapshot' => json_encode(['product' => ['name' => $product->name]]),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'total_idr' => 11000,
            'idempotency_key' => 'idem-'.$number,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_delivery_is_only_visible_after_success_on_page_and_polling(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.165']);
        $catalog = $this->catalog();
        $orderId = $this->guestOrder($catalog['product'], $catalog['package_id'], 'LF261001-DELIVERY');
        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'PROCESSING',
            'delivery_payload' => json_encode(['code' => 'PRIVATE-DELIVERY-CODE']),
        ]);

        $this->withSession(['guest_order_id' => $orderId])
            ->get('/orders/guest/LF261001-DELIVERY')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Guest/OrderStatus')
                ->where('order.delivery', null)
            );
        $this->getJson('/orders/guest/LF261001-DELIVERY/events')
            ->assertOk()->assertJsonPath('order.delivery', null);

        DB::table('orders')->where('id', $orderId)->update(['status' => 'SUCCESS']);
        $this->get('/orders/guest/LF261001-DELIVERY')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.delivery.code', 'PRIVATE-DELIVERY-CODE')
            );
        $this->getJson('/orders/guest/LF261001-DELIVERY/events')
            ->assertOk()->assertJsonPath('order.delivery.code', 'PRIVATE-DELIVERY-CODE');
        $this->withSession(['guest_order_id' => $orderId + 1])
            ->get('/orders/guest/LF261001-DELIVERY')->assertNotFound();
    }

    public function test_footer_assets_and_customer_product_parity_are_available_without_internal_data(): void
    {
        $catalog = $this->catalog();

        $this->assertDatabaseHas('store_assets', ['key' => 'footer_banner_desktop']);
        $this->assertDatabaseHas('store_assets', ['key' => 'footer_banner_mobile']);

        $this->get('/catalog/'.$catalog['product']->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Show')
                ->where('product.publisher', 'Parity Publisher')
                ->missing('product.manual_instructions')
                ->where('packages.0.group_name', 'Diamonds')
                ->where('notices.0.title', 'Periksa ID')
                ->missing('packages.0.external_sku')
                ->missing('packages.0.provider_code')
                ->etc());
    }

    public function test_public_payment_presentation_has_groups_but_never_gateway_identity(): void
    {
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
            'is_active' => true,
            'is_maintenance' => false,
        ]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);

        $channels = app(PaymentRoutingService::class)->publicOrderChannels(null);
        $manual = collect($channels)->firstWhere('code', 'manual_qris');

        $this->assertIsArray($manual);
        $this->assertSame('qris', $manual['group']);
        $this->assertArrayHasKey('description', $manual);
        $this->assertArrayNotHasKey('gateway_code', $manual);
        $this->assertArrayNotHasKey('provider_channel', $manual);
    }

    public function test_public_tracker_accepts_common_indonesian_phone_formats_and_masks_sensitive_data(): void
    {
        $catalog = $this->catalog();
        $this->guestOrder($catalog['product'], $catalog['package_id'], 'LF260930-TRACKTEST');

        $trackingToken = null;
        foreach (['081234567890', '6281234567890', '+62 812-3456-7890'] as $phone) {
            $response = $this->postJson('/orders/track/search', ['query' => $phone])
                ->assertOk()
                ->assertJsonPath('mode', 'phone')
                ->assertJsonPath('orders.0.maskedReferenceId', 'LF260••••TEST')
                ->assertJsonMissingPath('orders.0.referenceId');

            $this->assertStringNotContainsString('LF260930-TRACKTEST', $response->getContent());
            $trackingToken ??= $response->json('orders.0.trackingToken');
        }

        $this->assertIsString($trackingToken);
        $tracked = $this->postJson('/orders/track/status', ['tracking_token' => $trackingToken])
            ->assertOk()
            ->assertJsonPath('order.referenceId', 'LF260••••TEST')
            ->assertJsonPath('order.referenceMasked', true);
        $this->assertStringNotContainsString('LF260930-TRACKTEST', $tracked->getContent());

        $invoice = $this->postJson('/orders/track/search', ['query' => 'LF260930-TRACKTEST'])
            ->assertOk()->assertJsonPath('mode', 'order');
        $this->assertSame('Parity Game', substr((string) $invoice->json('order.productName'), 0, 11));
        $this->assertStringNotContainsString('PARITY-SKU', $invoice->getContent());
        $this->assertStringNotContainsString('DIGIFLAZZ', $invoice->getContent());
        $this->assertStringContainsString('12', (string) $invoice->json('order.destination'));
    }

    public function test_public_tracker_keeps_cancelled_distinct_from_failed_and_expired(): void
    {
        $catalog = $this->catalog();
        $orderId = $this->guestOrder($catalog['product'], $catalog['package_id'], 'LF260930-CANCEL01');
        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'CANCELLED',
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/orders/track/search', ['query' => 'LF260930-CANCEL01'])
            ->assertOk()
            ->assertJsonPath('mode', 'order')
            ->assertJsonPath('order.fulfillmentStatus', 'cancelled')
            ->assertJsonPath('order.paymentStatus', 'cancelled');

        $this->assertStringNotContainsString('DIGIFLAZZ', $response->getContent());
        $this->assertStringNotContainsString('PARITY-SKU', $response->getContent());
    }

    public function test_public_tracker_masks_refunds_and_keeps_internal_events_private(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.163']);
        $catalog = $this->catalog();
        $orderId = $this->guestOrder($catalog['product'], $catalog['package_id'], 'LF261001-REFUND01');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'REFUND']);
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'event_type' => 'DIGIFLAZZ_SECRET_SKU',
            'correlation_id' => (string) Str::uuid(),
            'from_status' => null,
            'to_status' => null,
            'metadata' => json_encode(['secret' => 'PRIVATE-RESULT']),
            'created_at' => now(),
        ]);

        $search = $this->postJson('/orders/track/search', ['query' => '081234567890'])
            ->assertOk()
            ->assertJsonPath('orders.0.status', 'refunded')
            ->assertJsonMissingPath('orders.0.referenceId');
        $status = $this->postJson('/orders/track/status', [
            'tracking_token' => $search->json('orders.0.trackingToken'),
        ])->assertOk()
            ->assertJsonPath('order.referenceMasked', true)
            ->assertJsonPath('order.fulfillmentStatus', 'refunded')
            ->assertJsonPath('order.paymentStatus', 'refunded')
            ->assertJsonPath('order.events.0.label', 'Status pesanan diperbarui')
            ->assertJsonPath('order.events.0.status', null);

        foreach (['LF261001-REFUND01', 'guest@example.test', '081234567890', 'DIGIFLAZZ', 'SECRET_SKU', 'PRIVATE-RESULT', '123456'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $status->getContent());
        }

        $feed = $this->getJson('/orders/track/feed')->assertOk();
        $this->assertSame('refunded', collect($feed->json('transactions'))
            ->firstWhere('maskedReferenceId', 'LF261••••ND01')['status']);
        $this->assertStringNotContainsString('LF261001-REFUND01', $feed->getContent());
        $this->assertStringContainsString('no-store', $status->headers->get('Cache-Control'));
    }

    public function test_public_tracker_rejects_corrupted_tokens_and_empty_search_is_unambiguous(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.164']);
        foreach (['corrupted-token', Crypt::encryptString('"scalar"'), Crypt::encryptString('null'), Crypt::encryptString('{"id":-1}')] as $token) {
            $this->postJson('/orders/track/status', ['tracking_token' => $token])->assertNotFound();
        }
        $this->postJson('/orders/track/search', ['query' => '081111111111'])
            ->assertOk()->assertJsonPath('mode', 'phone')->assertJsonPath('orders', []);
        $this->postJson('/orders/track/search', ['query' => 'not-a-phone'])
            ->assertUnprocessable();
    }

    public function test_logged_customer_can_save_game_account_and_guest_success_order_can_review_once(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.155']);
        $catalog = $this->catalog();
        $user = User::create([
            'name' => 'Parity User',
            'email' => 'parity-user-'.bin2hex(random_bytes(3)).'@example.test',
            'email_verified_at' => now(),
            'phone' => '081234567890',
            'password' => Hash::make('VeryStrongPassword123!'),
            'membership_tier_code' => 'BASIC',
        ]);

        $this->actingAs($user);
        $this->postJson('/account/game-accounts', [
            'product_id' => $catalog['product']->id,
            'label' => 'Akun Utama',
            'customer_input' => ['user_id' => '123456'],
        ])->assertCreated()->assertJsonPath('label', 'Akun Utama');
        $this->assertDatabaseHas('saved_game_accounts', [
            'user_id' => $user->id,
            'product_id' => $catalog['product']->id,
            'label' => 'Akun Utama',
        ]);

        auth('web')->logout();
        $orderId = $this->guestOrder($catalog['product'], $catalog['package_id'], 'LF260930-REVIEW01');
        $this->postJson('/reviews', [
            'product_slug' => $catalog['product']->slug,
            'order_number' => 'LF260930-REVIEW01',
            'access_code' => null,
            'rating' => 5,
            'body' => 'Proses cepat dan sesuai.',
            'display_name' => 'Guest Review',
        ])->assertUnprocessable()->assertJsonValidationErrors(['access_code']);

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson('/reviews', [
                'product_slug' => $catalog['product']->slug,
                'order_number' => 'LF260930-REVIEW01',
                'rating' => 5,
                'body' => 'Proses cepat dan sesuai.',
                'display_name' => 'Guest Review',
            ])->assertCreated();

        $this->assertDatabaseHas('product_reviews', [
            'order_id' => $orderId,
            'rating' => 5,
            'display_name' => 'Guest Review',
        ]);

        $this->withSession(['guest_order_id' => $orderId])
            ->postJson('/reviews', [
                'product_slug' => $catalog['product']->slug,
                'order_number' => 'LF260930-REVIEW01',
                'rating' => 4,
                'body' => 'Ulasan kedua tidak boleh.',
            ])->assertUnprocessable();
    }

    public function test_popular_now_restores_legacy_priority_and_can_be_controlled_from_promo(): void
    {
        $catalog = $this->catalog();
        $catalog['product']->update(['popular' => true]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Index')
                ->where('popularProducts.0.slug', $catalog['product']->slug)
                ->where('popularProducts.0.popular', true)
                ->etc());

        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin');
        $this->put('/admin/vouchers/popular/'.$catalog['product']->id, [
            'popular' => false,
        ])->assertRedirect();

        $this->assertFalse((bool) $catalog['product']->fresh()->popular);
    }

    public function test_super_admin_can_manage_product_notice_group_publisher_and_review_visibility(): void
    {
        $catalog = $this->catalog();
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/catalog/products/'.$catalog['product']->id, [
            'category_id' => $catalog['product']->category_id,
            'name' => $catalog['product']->name,
            'publisher' => 'Publisher Baru',
            'description' => 'Updated',
            'manual_instructions' => null,
            'margin_percent' => 12,
            'sort_order' => 2,
            'is_active' => true,
            'nickname_check_enabled' => false,
        ])->assertRedirect();

        $this->put('/admin/catalog/packages/'.$catalog['package_id'], [
            'code' => DB::table('product_packages')->where('id', $catalog['package_id'])->value('code'),
            'name' => '100 Diamonds',
            'group_name' => 'Promo Diamonds',
            'nominal_value' => 100,
            'sort_order' => 3,
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/admin/catalog/products/'.$catalog['product']->id.'/notices', [
            'title' => 'Jam layanan',
            'body' => 'Proses sesuai jam layanan.',
            'sort_order' => 2,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame('Publisher Baru', $catalog['product']->fresh()->publisher);
        $this->assertSame('Promo Diamonds', DB::table('product_packages')
            ->where('id', $catalog['package_id'])->value('group_name'));
        $this->assertDatabaseHas('product_notices', [
            'product_id' => $catalog['product']->id,
            'title' => 'Jam layanan',
        ]);

        $orderId = $this->guestOrder($catalog['product'], $catalog['package_id'], 'LF260930-ADMINRVW');
        $review = ProductReview::create([
            'order_id' => $orderId,
            'product_id' => $catalog['product']->id,
            'display_name' => 'Review Admin',
            'rating' => 5,
            'body' => 'Bagus.',
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->put('/admin/content/reviews/'.$review->id, ['is_active' => false])->assertRedirect();
        $this->assertFalse($review->fresh()->is_active);
    }
}
