<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDigiflazzApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->saveIntegrationSetting('digiflazz_environment', 'development');
        $this->saveIntegrationProfile('digiflazz', 'direct', 'development', [
            'username' => 'buyer-test',
            'apiKey' => 'dev-secret',
            'transactionApiUrl' => 'https://api.test/v1/transaction',
            'priceListUrl' => 'https://api.test/v1/price-list',
            'webhookSecret' => 'webhook-secret',
        ]);
    }

    public function test_admin_can_sync_pricelist_and_manage_server_owned_digiflazz_pricing(): void
    {
        $admin = $this->panelToken('df-admin', 'Digi Admin', 'admin', 'admin-password-123');
        $staff = $this->panelToken('df-staff', 'Digi Staff', 'staff', 'staff-password-123');
        [$productId, $packageId] = $this->digiflazzPackage();

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->getJson('/api/admin/digiflazz-pricing')
            ->assertForbidden();

        Http::fake([
            'https://api.test/v1/price-list' => Http::response([
                'data' => [[
                    'buyer_sku_code' => 'ML5',
                    'product_name' => 'Mobile Legends 5 Diamonds',
                    'category' => 'Games',
                    'brand' => 'Mobile Legends',
                    'type' => 'Umum',
                    'seller_name' => 'Seller A',
                    'price' => 9200,
                    'buyer_product_status' => true,
                    'seller_product_status' => true,
                    'unlimited_stock' => true,
                    'stock' => 0,
                    'multi' => true,
                    'start_cut_off' => '00:00',
                    'end_cut_off' => '00:00',
                    'desc' => 'Normal',
                ]],
            ], 200),
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/digiflazz-pricing', ['syncNow' => true])
            ->assertOk()
            ->assertJsonPath('result.updated', 1)
            ->assertJsonPath('result.cached', 1);

        $this->assertDatabaseHas('product_packages', [
            'id' => $packageId,
            'supplier_price' => 9200,
            'provider_max_price' => 9500,
            'price' => 10500,
        ]);
        $this->assertDatabaseHas('digiflazz_seller_monitor', [
            'package_id' => $packageId,
            'seller_name' => 'Seller A',
            'current_price' => 9200,
            'health' => 'healthy',
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->putJson('/api/admin/digiflazz-pricing', [
                'packageId' => $packageId,
                'maxPrice' => 10000,
                'marginType' => 'percent',
                'marginValue' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('pricing.currentCost', 9200)
            ->assertJsonPath('pricing.sellingPrice', 11000);

        $this->assertDatabaseHas('product_packages', [
            'id' => $packageId,
            'provider_max_price' => 10000,
            'margin_type' => 'percent',
            'margin_value' => 10,
            'price' => 11000,
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->getJson('/api/admin/digiflazz-pricing?catalog=1')
            ->assertOk()
            ->assertJsonPath('catalog.0.buyerSkuCode', 'ML5')
            ->assertJsonPath('cache.count', 1);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->getJson('/api/admin/digiflazz-monitor')
            ->assertOk()
            ->assertJsonPath('summary.healthy', 1)
            ->assertJsonPath('items.0.packageId', $packageId)
            ->assertJsonPath('api.environment', null);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/digiflazz-pricing', [
                'productId' => $productId,
                'packageSku' => 'ML5-LOCAL',
            ])
            ->assertOk()
            ->assertJsonPath('result.updated', 1);
    }

    public function test_owner_monitor_reads_balance_without_exposing_credentials(): void
    {
        $owner = $this->panelToken('df-owner', 'Digi Owner', 'super_admin', 'owner-password-123');
        $this->digiflazzPackage();

        Http::fake([
            'https://api.test/v1/cek-saldo' => Http::response([
                'data' => ['deposit' => 123456, 'rc' => '00'],
            ], 200),
        ]);

        $response = $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->getJson('/api/admin/digiflazz-monitor')
            ->assertOk()
            ->assertJsonPath('api.ready', true)
            ->assertJsonPath('api.balance', 123456)
            ->assertJsonPath('api.environment', 'development');

        $payload = json_encode($response->json());
        $this->assertStringNotContainsString('dev-secret', $payload);
        $this->assertStringNotContainsString('buyer-test', $payload);
    }

    /** @return array{0:int,1:int} */
    private function digiflazzPackage(): array
    {
        $productId = (int) DB::table('products')->insertGetId([
            'slug' => 'mobile-legends',
            'name' => 'Mobile Legends',
            'publisher' => 'Moonton',
            'category' => 'game',
            'initials' => 'ML',
            'accent' => 'blue-500',
            'input_label' => 'User ID',
            'input_placeholder' => '123456',
            'input_fields_json' => '[]',
            'needs_server' => 1,
            'popular' => 1,
            'instant' => 1,
            'fulfillment_type' => 'automatic',
            'target_template' => '{{destination}}{{server}}',
            'manual_timezone' => 'Asia/Jakarta',
            'package_tabs_enabled' => 0,
            'package_tabs_json' => '[]',
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $packageId = (int) DB::table('product_packages')->insertGetId([
            'product_id' => $productId,
            'sku' => 'ML5-LOCAL',
            'label' => '5 Diamonds',
            'price' => 10500,
            'provider_code' => 'digiflazz',
            'provider_sku' => 'ML5',
            'supplier_price' => 9000,
            'provider_max_price' => 9500,
            'pricing_mode' => 'auto',
            'margin_type' => 'fixed',
            'margin_value' => 1000,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$productId, $packageId];
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