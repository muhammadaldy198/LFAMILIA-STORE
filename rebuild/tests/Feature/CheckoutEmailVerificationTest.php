<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Keputusan D7 (grand opening): verifikasi email ditegakkan.
 * Akun member yang belum verifikasi email tidak bisa checkout
 * sampai verifikasi. Login TIDAK dikunci. Guest tidak kena gate.
 * Repo memakai PHPUnit (bukan Pest) — test ini mengikuti pola repo.
 */
class CheckoutEmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
    }

    private function catalog(): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Verifikasi Game '.bin2hex(random_bytes(3)),
            'slug' => 'verifikasi-game-'.bin2hex(random_bytes(4)),
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
        $package = $product->packages()->create([
            'code' => 'VRF'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'VRF-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}'], JSON_THROW_ON_ERROR),
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['package_id' => $package->id];
    }

    private function enableManualQrisRoute(): void
    {
        // Pola yang sama dengan CheckoutTest: QRIS manual (gateway MANUAL,
        // bukan eksternal) — MIDTRANS tanpa kredensial integrasi selalu
        // masuk daftar gateway unavailable sehingga route-nya terfilter.
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }

    private function makeUser(?string $verifiedAt): User
    {
        $user = User::create([
            'name' => 'Member Verifikasi',
            'email' => 'verifikasi-'.bin2hex(random_bytes(4)).'@example.test',
            'phone' => '081234567890',
            'password' => 'password',
        ]);
        $user->forceFill(['email_verified_at' => $verifiedAt])->save();

        return $user;
    }

    public function test_unverified_member_cannot_checkout(): void
    {
        $catalog = $this->catalog();
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        $this->enableManualQrisRoute();
        $user = $this->makeUser(null);

        $response = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456'],
            'idempotency_key' => 'verify-'.bin2hex(random_bytes(8)),
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('requires_email_verification', true);
        $this->assertSame(0, DB::table('orders')->where('user_id', $user->id)->count());
    }

    public function test_verified_member_can_checkout(): void
    {
        $catalog = $this->catalog();
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        $this->enableManualQrisRoute();
        $user = $this->makeUser(now()->toDateTimeString());

        $response = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456'],
            'idempotency_key' => 'verify-'.bin2hex(random_bytes(8)),
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('orders')->where('user_id', $user->id)->count());
    }

    public function test_guest_checkout_is_not_gated(): void
    {
        $catalog = $this->catalog();
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        $this->enableManualQrisRoute();

        $response = $this->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456'],
            'guest_email' => 'guest-verifikasi@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => 'verify-'.bin2hex(random_bytes(8)),
        ]);

        $response->assertStatus(201);
    }
}
