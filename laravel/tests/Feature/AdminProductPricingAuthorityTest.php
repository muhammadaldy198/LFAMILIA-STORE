<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminProductPricingAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_admin_product_edit_cannot_override_existing_digiflazz_pricing_authority(): void
    {
        $admin=$this->panelToken('product-admin','Product Admin','admin','admin-password-123');
        $staff=$this->panelToken('product-staff','Product Staff','staff','staff-password-123');

        $productId=DB::table('products')->insertGetId([
            'slug'=>'price-game','name'=>'Price Game','publisher'=>'','category'=>'game',
            'initials'=>'PG','accent'=>'lime-500','input_label'=>'ID','input_placeholder'=>'123456',
            'input_fields_json'=>'[]','needs_server'=>0,'popular'=>0,'instant'=>1,
            'fulfillment_type'=>'automatic','target_template'=>'{{destination}}','manual_timezone'=>'Asia/Jakarta',
            'package_tabs_enabled'=>0,'package_tabs_json'=>'[]','is_active'=>1,'sort_order'=>0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('product_packages')->insert([
            'product_id'=>$productId,'sku'=>'PG10','label'=>'10','price'=>10500,
            'provider_code'=>'digiflazz','provider_sku'=>'DF-PG10','supplier_price'=>9000,
            'provider_max_price'=>9500,'pricing_mode'=>'auto','margin_type'=>'fixed','margin_value'=>1000,
            'is_active'=>1,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $payload=[
            'dbId'=>$productId,'slug'=>'price-game','name'=>'Price Game','publisher'=>'','category'=>'game',
            'imageUrl'=>'','bannerUrl'=>'','description'=>'','initials'=>'PG','accent'=>'lime-500',
            'inputLabel'=>'ID','inputPlaceholder'=>'123456','inputFields'=>[],
            'needsServer'=>false,'popular'=>false,'instant'=>true,'fulfillmentType'=>'automatic',
            'targetTemplate'=>'{{destination}}','manualInstructions'=>'','manualOpenTime'=>null,'manualCloseTime'=>null,
            'manualTimezone'=>'Asia/Jakarta','packageTabsEnabled'=>false,'packageTabs'=>[],
            'isActive'=>true,'sortOrder'=>0,'notices'=>[],
            'packages'=>[[
                'id'=>'PG10','label'=>'10','price'=>1,'providerCode'=>'digiflazz','providerSku'=>'DF-PG10',
                'supplierPrice'=>1,'providerMaxPrice'=>1,'pricingMode'=>'manual',
                'marginType'=>'percent','marginValue'=>999999,'isActive'=>true,'sortOrder'=>0,
            ]],
        ];

        $this->withHeader('Cookie',AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->patchJson('/api/admin/products',$payload)->assertForbidden();

        $this->withHeader('Cookie',AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->patchJson('/api/admin/products',$payload)->assertOk();

        $this->assertDatabaseHas('product_packages',[
            'product_id'=>$productId,'sku'=>'PG10','price'=>10500,'supplier_price'=>9000,
            'provider_max_price'=>9500,'pricing_mode'=>'auto','margin_type'=>'fixed','margin_value'=>1000,
        ]);
    }

    public function test_new_digiflazz_package_ignores_client_supplier_and_max_price(): void
    {
        $admin=$this->panelToken('product-owner','Product Owner','super_admin','owner-password-123');
        $payload=[
            'slug'=>'new-game','name'=>'New Game','publisher'=>'','category'=>'game',
            'imageUrl'=>'','bannerUrl'=>'','description'=>'','initials'=>'NG','accent'=>'lime-500',
            'inputLabel'=>'ID','inputPlaceholder'=>'123456','inputFields'=>[],
            'needsServer'=>false,'popular'=>false,'instant'=>true,'fulfillmentType'=>'automatic',
            'targetTemplate'=>'{{destination}}','manualInstructions'=>'','manualOpenTime'=>null,'manualCloseTime'=>null,
            'manualTimezone'=>'Asia/Jakarta','packageTabsEnabled'=>false,'packageTabs'=>[],
            'isActive'=>true,'sortOrder'=>0,'notices'=>[],
            'packages'=>[[
                'id'=>'NG10','label'=>'10','price'=>12000,'providerCode'=>'digiflazz','providerSku'=>'DF-NG10',
                'supplierPrice'=>1,'providerMaxPrice'=>1,'pricingMode'=>'manual',
                'marginType'=>'percent','marginValue'=>900,'isActive'=>true,'sortOrder'=>0,
            ]],
        ];

        $this->withHeader('Cookie',AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->postJson('/api/admin/products',$payload)->assertCreated();

        $this->assertDatabaseHas('product_packages',[
            'sku'=>'NG10','price'=>12000,'supplier_price'=>null,'provider_max_price'=>null,
            'pricing_mode'=>'auto','margin_type'=>'fixed','margin_value'=>0,
        ]);
    }


    public function test_super_admin_can_load_products_with_seller_monitor(): void
    {
        $owner = $this->panelToken('product-owner-list','Product Owner List','super_admin','owner-password-123');

        DB::table('digiflazz_seller_monitor')->insert([
            'package_id' => 999,
            'seller_name' => 'Seller Test',
            'baseline_price' => 1000,
            'current_price' => 1000,
            'status' => 'active',
            'last_checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($owner))
            ->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('role', 'super_admin')
            ->assertJsonPath('databaseReady', true)
            ->assertJsonCount(1, 'sellerMonitor');
    }


    private function panelToken(string $username,string $name,string $role,string $password): string
    {
        $auth=app(AdminAuthService::class);
        $auth->createCredential($username,$name,$password,true);
        DB::table('admin_users')->insert([
            'email'=>$username,'name'=>$name,'role'=>$role,'is_active'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $auth->login($username,$password,$role==='staff'?'staff':'backoffice')['token'];
    }
}
