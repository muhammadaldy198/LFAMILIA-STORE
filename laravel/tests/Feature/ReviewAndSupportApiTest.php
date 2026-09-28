<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewAndSupportApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_guest_paid_order_can_review_once_and_staff_can_moderate(): void
    {
        $productId = DB::table('products')->insertGetId([
            'slug' => 'review-game',
            'name' => 'Review Game',
            'publisher' => '',
            'category' => 'game',
            'initials' => 'RG',
            'accent' => 'lime',
            'input_label' => 'ID',
            'input_placeholder' => '123',
            'needs_server' => 0,
            'popular' => 0,
            'instant' => 0,
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'manual_timezone' => 'Asia/Jakarta',
            'package_tabs_enabled' => 0,
            'package_tabs_json' => '[]',
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_packages')->insert([
            'product_id' => $productId,
            'sku' => 'RG10',
            'label' => '10',
            'price' => 10000,
            'pricing_mode' => 'manual',
            'margin_type' => 'fixed',
            'margin_value' => 0,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'reference_id' => 'LF260928ABCDEF12345678',
            'product_slug' => 'review-game',
            'product_name' => 'Review Game',
            'package_sku' => 'RG10',
            'package_label' => '10',
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'destination' => '123',
            'buyer_name' => 'Muhammad Test',
            'buyer_email' => 'guest@example.com',
            'buyer_phone' => '+6281234567890',
            'customer_inputs_json' => '[]',
            'quantity' => 1,
            'base_subtotal' => 10000,
            'subtotal' => 10000,
            'discount_amount' => 0,
            'admin_fee' => 0,
            'total' => 10000,
            'payment_method' => 'qris',
            'payment_channel' => 'mpm',
            'payment_status' => 'paid',
            'fulfillment_status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'productSlug' => 'review-game',
            'rating' => 5,
            'title' => 'Mantap',
            'body' => 'Proses cepat dan aman.',
            'referenceId' => 'LF260928ABCDEF12345678',
            'phone' => '081234567890',
        ];

        $this->postJson('/api/reviews', $payload)
            ->assertOk();

        $this->postJson('/api/reviews', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error', 'Invoice ini sudah pernah digunakan untuk memberikan ulasan.');

        $public = $this->getJson('/api/reviews?product=review-game')
            ->assertOk()
            ->assertJsonPath('reviews.0.isVerifiedPurchase', true);
        $this->assertNotSame('Muhammad Test', $public->json('reviews.0.customerName'));

        $staff = $this->panelToken('review-staff', 'Review Staff', 'staff', 'staff-password-123');
        $reviewId = (int) DB::table('product_reviews')->where('order_id', $orderId)->value('id');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->patchJson('/api/admin/reviews', [
                'id' => $reviewId,
                'isVisible' => false,
            ])
            ->assertOk();

        $this->getJson('/api/reviews?product=review-game')
            ->assertOk()
            ->assertJsonCount(0, 'reviews');
    }

    public function test_customer_support_is_owned_and_staff_can_reply(): void
    {
        [$customerId, $customerToken] = $this->customerSession();
        $staff = $this->panelToken('support-staff', 'Support Staff', 'staff', 'staff-password-123');

        $created = $this->withHeader('Cookie', 'lfamilia_session='.rawurlencode($customerToken))
            ->postJson('/api/account/support', [
                'kind' => 'support',
                'subject' => 'Pesanan belum masuk',
                'message' => 'Mohon bantu periksa pesanan saya sekarang.',
            ])
            ->assertCreated();

        $requestId = (string) $created->json('id');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->patchJson('/api/admin/support', [
                'id' => $requestId,
                'status' => 'resolved',
                'reply' => 'Sudah kami periksa dan selesai.',
            ])
            ->assertOk();

        $this->withHeader('Cookie', 'lfamilia_session='.rawurlencode($customerToken))
            ->getJson('/api/account/support')
            ->assertOk()
            ->assertJsonPath('requests.0.id', $requestId)
            ->assertJsonPath('requests.0.status', 'resolved')
            ->assertJsonPath('requests.0.staff_reply', 'Sudah kami periksa dan selesai.');

        $this->assertDatabaseHas('customer_support_requests', [
            'id' => $requestId,
            'customer_id' => $customerId,
            'handled_by' => 'support-staff',
        ]);
    }

    /** @return array{0:string,1:string} */
    private function customerSession(): array
    {
        $id = (string) Str::uuid();
        DB::table('customer_users')->insert([
            'id' => $id,
            'email' => 'support@example.com',
            'name' => 'Support User',
            'phone' => '+6281234567890',
            'password_hash' => str_repeat('a', 64),
            'password_salt' => str_repeat('b', 32),
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'support-session-'.$id;
        DB::table('customer_sessions')->insert([
            'id' => (string) Str::uuid(),
            'customer_id' => $id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        return [$id, $token];
    }

    private function panelToken(string $username, string $name, string $role, string $password): string
    {
        $auth = app(AdminAuthService::class);
        $auth->createCredential($username, $name, $password, true);
        DB::table('admin_users')->insert([
            'email' => $username,
            'name' => $name,
            'role' => $role,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $auth->login(
            $username,
            $password,
            $role === 'staff' ? 'staff' : 'backoffice',
        )['token'];
    }
}
