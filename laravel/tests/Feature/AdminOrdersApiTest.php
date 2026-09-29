<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOrdersApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_staff_can_complete_manual_order_but_cannot_create_manual_order(): void
    {
        $staff = $this->panelToken('staff-order', 'Staff Order', 'staff', 'staff-password-123');
        $admin = $this->panelToken('admin-order', 'Admin Order', 'admin', 'admin-password-123');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->postJson('/api/admin/orders', [
                'customer' => 'Buyer',
                'phone' => '+6281234567890',
                'customerEmail' => 'buyer@example.com',
                'product' => 'Manual Product',
                'packageName' => 'Paket 1',
                'destination' => '123456',
                'total' => 15000,
                'payment' => 'admin_manual',
            ])
            ->assertForbidden();

        $created = $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/orders', [
                'customer' => 'Buyer',
                'phone' => '+6281234567890',
                'customerEmail' => 'buyer@example.com',
                'product' => 'Manual Product',
                'packageName' => 'Paket 1',
                'destination' => '123456',
                'total' => 15000,
                'payment' => 'admin_manual',
            ])
            ->assertCreated();

        $id = (string) $created->json('id');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->patchJson('/api/admin/orders', [
                'id' => $id,
                'action' => 'complete_manual',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'payment_status' => 'paid',
            'fulfillment_status' => 'success',
            'provider_status' => 'manual_done',
        ]);
    }

    public function test_staff_order_list_hides_financial_and_provider_fields(): void
    {
        $staff = $this->panelToken('staff-view', 'Staff View', 'staff', 'staff-password-123');

        DB::table('orders')->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'reference_id' => 'LF260928STAFF0001',
            'product_slug' => 'game',
            'product_name' => 'Game',
            'package_sku' => 'G10',
            'package_label' => '10',
            'provider_code' => 'digiflazz',
            'provider_sku' => 'SECRET-SKU',
            'fulfillment_type' => 'automatic',
            'delivery_mode' => 'direct',
            'target_template' => '{{destination}}',
            'destination' => '123456',
            'customer_no' => '123456',
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '+6281234567890',
            'customer_inputs_json' => '[]',
            'quantity' => 1,
            'supplier_cost_snapshot' => 9000,
            'base_subtotal' => 10000,
            'subtotal' => 10000,
            'discount_amount' => 0,
            'admin_fee' => 100,
            'total' => 10100,
            'payment_method' => 'qris',
            'payment_channel' => 'mpm',
            'payment_status' => 'paid',
            'fulfillment_status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('orders.0.total', null);

        $this->assertArrayNotHasKey('provider_sku', $response->json('orders.0'));
        $this->assertArrayNotHasKey('supplier_cost_snapshot', $response->json('orders.0'));
        $this->assertArrayNotHasKey('buyer_email', $response->json('orders.0'));

        DB::table('orders')->where('id', '22222222-2222-4222-8222-222222222222')->update([
            'fulfillment_status' => 'needs_review',
            'provider_status' => 'failed',
        ]);
        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->patchJson('/api/admin/orders', [
                'id' => '22222222-2222-4222-8222-222222222222',
                'action' => 'retry_digiflazz',
            ])
            ->assertForbidden();
        Http::assertNothingSent();
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
