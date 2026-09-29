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


    public function test_admin_can_delete_faq(): void
    {
        $admin = $this->panelToken('faq-admin','FAQ Admin','admin','admin-password-123');
        $id = (int) DB::table('faq_entries')->insertGetId([
            'question' => 'Apakah FAQ ini bisa dihapus?',
            'answer' => 'Ya, admin dapat menghapus FAQ dari panel.',
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Cookie', AdminAuthService::COOKIE.'='.rawurlencode($admin))
            ->deleteJson('/api/admin/faqs?id='.$id)
            ->assertOk();

        $this->assertDatabaseMissing('faq_entries', ['id' => $id]);
    }


    public function test_footer_banners_are_saved_and_public_announcement_is_removed(): void
    {
        $admin = $this->panelToken('brand-admin', 'Brand Admin', 'admin', 'admin-password-123');
        $cookie = AdminAuthService::COOKIE.'='.rawurlencode($admin);
        $settings = $this->withHeader('Cookie', $cookie)
            ->getJson('/api/admin/storefront')->assertOk()->json('settings');

        $this->withHeader('Cookie', $cookie)
            ->putJson('/api/admin/storefront', [
                ...$settings,
                'footerBannerDesktopUrl' => '/brand/footer-desktop-test.webp',
                'footerBannerMobileUrl' => '/brand/footer-mobile-test.webp',
                'announcement' => 'Pemesanan tersedia 24 jam',
            ])->assertOk();

        $this->getJson('/api/storefront')->assertOk()
            ->assertJsonPath('settings.footerBannerDesktopUrl', '/brand/footer-desktop-test.webp')
            ->assertJsonPath('settings.footerBannerMobileUrl', '/brand/footer-mobile-test.webp')
            ->assertJsonPath('settings.announcement', null);
        $this->assertDatabaseHas('store_settings', ['id' => 1, 'announcement' => null]);
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
