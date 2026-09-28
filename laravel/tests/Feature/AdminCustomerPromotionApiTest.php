<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCustomerPromotionApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_customer_admin_preserves_rbac_ledger_and_safe_cleanup(): void
    {
        $owner = $this->panelToken('owner-customer', 'Owner Customer', 'super_admin', 'owner-password-123');
        $admin = $this->panelToken('admin-customer', 'Admin Customer', 'admin', 'admin-password-123');
        $staff = $this->panelToken('staff-customer', 'Staff Customer', 'staff', 'staff-password-123');
        $customerId = (string) Str::uuid();
        $this->customer($customerId, 'member@example.com', now()->subDays(2));

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->getJson('/api/admin/members')
            ->assertForbidden();

        $adminView = $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->getJson('/api/admin/members')
            ->assertOk();
        $adminView->assertJsonMissingPath('settings');
        $this->assertArrayNotHasKey('balance', $adminView->json('members.0'));

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->putJson('/api/admin/members', [
                'settings' => [
                    ['tier' => 'basic', 'discountPercent' => 0, 'benefits' => 'Basic'],
                    ['tier' => 'gold', 'discountPercent' => 1, 'benefits' => 'Gold'],
                    ['tier' => 'diamond', 'discountPercent' => 2, 'benefits' => 'Diamond'],
                    ['tier' => 'platinum', 'discountPercent' => 3, 'benefits' => 'Platinum'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.1.discountPercent', 1);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->patchJson('/api/admin/members', [
                'customerId' => $customerId,
                'role' => 'gold',
                'addBalance' => 5000,
                'reason' => 'Bonus test',
            ])
            ->assertOk();

        $this->assertDatabaseHas('customer_users', [
            'id' => $customerId,
            'tier_mode' => 'manual',
            'tier_override' => 'gold',
            'tier_progress_bonus' => 1000000,
            'balance' => 5000,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'customer_id' => $customerId,
            'direction' => 'credit',
            'amount' => 5000,
            'balance_before' => 0,
            'balance_after' => 5000,
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->putJson('/api/admin/balances', [
                'accountType' => 'customer',
                'targetId' => $customerId,
                'operation' => 'debit',
                'amount' => 1000,
                'reason' => 'Koreksi test',
            ])
            ->assertOk()
            ->assertJsonPath('balanceAfter', 4000);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->deleteJson('/api/admin/members?id='.$customerId)
            ->assertStatus(409);

        $dormantId = (string) Str::uuid();
        $this->customer($dormantId, 'dormant@example.com', now()->subDays(45));
        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->postJson('/api/admin/customer-cleanup')
            ->assertOk()
            ->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('customer_users', ['id' => $dormantId]);
    }

    public function test_promotion_admin_guards_history_capacity_and_flash_sale_price(): void
    {
        $admin = $this->panelToken('promo-admin', 'Promo Admin', 'admin', 'admin-password-123');
        $staff = $this->panelToken('promo-staff', 'Promo Staff', 'staff', 'staff-password-123');
        $productId = $this->productWithPackage();
        $this->assertGreaterThan(0, $productId);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->getJson('/api/admin/promotions')
            ->assertForbidden();

        $starts = now()->subHour()->toIso8601String();
        $ends = now()->addDay()->toIso8601String();
        $voucher = [
            'kind' => 'voucher',
            'code' => 'HEMAT10',
            'name' => 'Hemat 10',
            'description' => 'Voucher test',
            'discountType' => 'percentage',
            'discountValue' => 10,
            'minPurchase' => 1000,
            'maxDiscount' => 5000,
            'usageLimit' => 10,
            'startsAt' => $starts,
            'endsAt' => $ends,
            'isActive' => true,
        ];

        $created = $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/promotions', $voucher)
            ->assertCreated();
        $voucherId = (int) $created->json('id');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/promotions', [
                'kind' => 'flash',
                'productSlug' => 'promo-game',
                'packageSku' => 'PG10',
                'salePrice' => 9000,
                'badge' => 'Promo',
                'stockLimit' => 5,
                'startsAt' => $starts,
                'endsAt' => $ends,
                'isActive' => true,
            ])
            ->assertCreated();

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/promotions', [
                'kind' => 'flash',
                'productSlug' => 'promo-game',
                'packageSku' => 'PG10',
                'salePrice' => 10000,
                'badge' => 'Invalid',
                'stockLimit' => 5,
                'startsAt' => $starts,
                'endsAt' => $ends,
                'isActive' => true,
            ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'Harga flash sale harus lebih rendah dari harga normal.');

        DB::table('discount_vouchers')->where('id', $voucherId)->update(['reserved_count' => 1]);
        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->deleteJson('/api/admin/promotions?kind=voucher&id='.$voucherId)
            ->assertStatus(400)
            ->assertJsonPath('error', 'Promo tidak dapat dihapus saat masih memiliki reservasi pembayaran aktif.');

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->getJson('/api/admin/promotions')
            ->assertOk()
            ->assertJsonPath('vouchers.0.code', 'HEMAT10')
            ->assertJsonPath('flashSales.0.packageSku', 'PG10');
    }

    private function customer(string $id, string $email, $createdAt): void
    {
        DB::table('customer_users')->insert([
            'id' => $id,
            'email' => $email,
            'name' => 'Customer',
            'phone' => '+6281234567890',
            'password_hash' => 'hash',
            'password_salt' => 'salt',
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function productWithPackage(): int
    {
        $productId = (int) DB::table('products')->insertGetId([
            'slug' => 'promo-game',
            'name' => 'Promo Game',
            'publisher' => '',
            'category' => 'game',
            'initials' => 'PG',
            'accent' => 'lime-500',
            'input_label' => 'ID',
            'input_placeholder' => '123456',
            'input_fields_json' => '[]',
            'needs_server' => 0,
            'popular' => 0,
            'instant' => 1,
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
            'sku' => 'PG10',
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

        return $productId;
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

        return $auth->login($username, $password, $role === 'staff' ? 'staff' : 'backoffice')['token'];
    }
}
