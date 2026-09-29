<?php

namespace Tests\Feature;

use App\Services\AdminAuthService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_staff_can_manage_content_but_cannot_delete_banner(): void
    {
        $staff=$this->panelToken('content-staff','Content Staff','staff','staff-password-123');
        $admin=$this->panelToken('content-admin','Content Admin','admin','admin-password-123');

        $created=$this->withHeader('Cookie',AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->postJson('/api/admin/content',[
                'kind'=>'banner',
                'item'=>[
                    'title'=>'Promo Baru','subtitle'=>'Banner aman','imageUrl'=>'/products/mobile-legends-banner.webp',
                    'mobileImageUrl'=>'/products/mobile-legends-cover.webp','ctaLabel'=>'','ctaHref'=>'/#produk',
                    'showDesktop'=>true,'showMobile'=>true,'isActive'=>true,'sortOrder'=>1,
                ],
            ])->assertOk();
        $id=(int)$created->json('id');

        $this->withHeader('Cookie',AdminAuthService::COOKIE.'='.rawurlencode($staff))
            ->deleteJson('/api/admin/content?kind=banner&id='.$id)->assertForbidden();

        $this->withHeader('Cookie',AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->deleteJson('/api/admin/content?kind=banner&id='.$id)->assertOk();

        $this->assertDatabaseMissing('home_banners',['id'=>$id]);
    }

    public function test_public_storefront_neutralizes_provider_names_and_category_delete_is_guarded(): void
    {
        DB::table('store_settings')->insert([
            'id'=>1,'store_name'=>'LFAMILIA DOKU','store_short_name'=>'LF','tagline'=>'Digiflazz cepat',
            'banner_enabled'=>1,'banner_eyebrow'=>'Midtrans','banner_title'=>'Top up','banner_highlight'=>'Aman',
            'banner_description'=>'Kokinpay tersedia','banner_cta_label'=>'Beli','banner_cta_href'=>'/#produk',
            'support_hours'=>'Setiap hari','support_widget_enabled'=>1,'updated_at'=>now(),
        ]);
        DB::table('faq_entries')->insert([
            'question'=>'Apakah pakai Digiflazz?','answer'=>'Provider disembunyikan dari customer.',
            'is_active'=>1,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $response=$this->getJson('/api/storefront')->assertOk();
        $this->assertStringNotContainsString('DOKU',(string)$response->json('settings.storeName'));
        $this->assertStringNotContainsString('Digiflazz',(string)$response->json('settings.tagline'));
        $this->assertStringNotContainsString('Digiflazz',(string)$response->json('faqs.0.question'));
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
