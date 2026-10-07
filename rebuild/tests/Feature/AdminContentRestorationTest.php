<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\HomeBanner;
use App\Models\NewsArticle;
use App\Models\Product;
use App\Models\SitePopup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminContentRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Content regression',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('content-regression-only'),
            'role' => 'SUPER_ADMIN',
            'permissions' => [],
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_content_workspace_only_lists_storefront_assets_not_payment_assets(): void
    {
        $this->login();
        DB::table('store_assets')->updateOrInsert(
            ['key' => 'payment_header'],
            ['target_url' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->get('/admin/content')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Content')
            ->where('assets', function ($assets): bool {
                $keys = collect($assets)->pluck('key')->all();

                return in_array('logo', $keys, true)
                    && in_array('footer_banner_desktop', $keys, true)
                    && ! in_array('manual_qris', $keys, true)
                    && ! in_array('payment_header', $keys, true)
                    && ! in_array('popup', $keys, true);
            }));
    }

    public function test_news_slug_is_normalized_before_uniqueness_check_on_create_and_update(): void
    {
        $this->login();
        $existing = NewsArticle::create([
            'slug' => 'promo-baru',
            'title' => 'Promo yang sudah ada',
            'summary' => null,
            'body' => 'Isi',
            'source_label' => 'LFAMILIA News',
            'sort_order' => 0,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $payload = [
            'slug' => 'Promo Baru',
            'title' => 'Duplikat',
            'summary' => '',
            'body' => 'Isi duplikat',
            'source_label' => 'LFAMILIA News',
            'sort_order' => 1,
            'is_active' => true,
            'published_at' => null,
        ];
        $this->post('/admin/content/news', $payload)->assertSessionHasErrors('slug');
        $this->assertSame(1, NewsArticle::where('slug', 'promo-baru')->count());

        $other = NewsArticle::create([
            'slug' => 'artikel-lain',
            'title' => 'Artikel lain',
            'summary' => null,
            'body' => 'Isi lain',
            'source_label' => 'LFAMILIA News',
            'sort_order' => 2,
            'is_active' => true,
            'published_at' => now(),
        ]);
        $this->put('/admin/content/news/'.$other->id, [
            ...$payload,
            'title' => 'Artikel lain diperbarui',
        ])->assertSessionHasErrors('slug');

        $this->assertSame('artikel-lain', $other->fresh()->slug);
        $this->assertSame('promo-baru', $existing->fresh()->slug);
    }

    public function test_banner_requires_at_least_one_target_device(): void
    {
        $this->login();

        $this->post('/admin/content/banners', [
            'title' => 'Banner validasi',
            'subtitle' => null,
            'cta_label' => null,
            'cta_href' => '/promo',
            'show_desktop' => false,
            'show_mobile' => false,
            'sort_order' => 0,
            'is_active' => true,
        ])->assertSessionHasErrors('show_desktop');

        $this->assertSame(0, HomeBanner::where('title', 'Banner validasi')->count());
    }

    public function test_single_popup_has_no_action_buttons_and_can_be_deleted(): void
    {
        $this->login();
        $popup = SitePopup::query()->firstOrFail();

        $this->put('/admin/content/popups/'.$popup->id, [
            'title' => 'Informasi terbaru',
            'body' => 'Satu panel tanpa tombol tambahan.',
            'dismiss_days' => 5,
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $popup->refresh();
        $this->assertSame('Informasi terbaru', $popup->title);
        $this->assertNull($popup->primary_label);
        $this->assertNull($popup->primary_href);
        $this->assertNull($popup->secondary_label);
        $this->assertNull($popup->secondary_href);
        $this->assertSame(1, SitePopup::count());

        $this->delete('/admin/content/popups/'.$popup->id)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(0, SitePopup::count());
    }

    public function test_content_settings_do_not_overwrite_store_contact_settings(): void
    {
        $this->login();
        foreach ([
            'store.support_whatsapp' => '081234567890',
            'store.email' => 'support@example.test',
            'store.business_hours' => '08.00–22.00 WIB',
            'store.support_url' => '/contact',
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->put('/admin/content/settings', [
            'support_widget_enabled' => true,
            'support_cta_enabled' => true,
            'support_cta_label' => 'PERLU BANTUAN?',
            'support_cta_title' => 'Kami siap membantu.',
            'support_cta_body' => 'Hubungi tim jika ada kendala.',
            'support_cta_button' => 'Buka Bantuan',
            'footer_description' => 'Deskripsi footer baru.',
            'home_news_title' => 'Berita LFAMILIA',
            'home_news_intro' => 'Info terbaru dari toko.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            '081234567890',
            json_decode((string) DB::table('system_settings')->where('key', 'store.support_whatsapp')->value('value'), true)
        );
        $this->assertSame(
            'support@example.test',
            json_decode((string) DB::table('system_settings')->where('key', 'store.email')->value('value'), true)
        );
        $this->assertSame(
            '08.00–22.00 WIB',
            json_decode((string) DB::table('system_settings')->where('key', 'store.business_hours')->value('value'), true)
        );
        $this->assertSame(
            'Kami siap membantu.',
            json_decode((string) DB::table('system_settings')->where('key', 'store.support_cta_title')->value('value'), true)
        );

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('storefront.supportCtaEnabled', true)
            ->where('storefront.supportCtaLabel', 'PERLU BANTUAN?')
            ->where('storefront.supportCtaTitle', 'Kami siap membantu.')
            ->where('storefront.supportCtaBody', 'Hubungi tim jika ada kendala.')
            ->where('storefront.supportCtaButton', 'Buka Bantuan')
            ->where('storefront.supportWhatsapp', '081234567890')
            ->where('storefront.supportEmail', 'support@example.test'));
    }

    public function test_review_checkbox_payload_uses_real_booleans(): void
    {
        $this->login();

        $category = Category::create([
            'name' => 'Review category '.bin2hex(random_bytes(3)),
            'slug' => 'review-category-'.bin2hex(random_bytes(4)),
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Review product '.bin2hex(random_bytes(3)),
            'slug' => 'review-product-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'MANUAL',
            'margin_percent' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'REV'.bin2hex(random_bytes(3)),
            'name' => 'Review package',
            'nominal_value' => 1,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'REVIEW-'.bin2hex(random_bytes(6)),
            'guest_email' => 'review@example.test',
            'guest_phone' => '081234567896',
            'product_id' => $product->id,
            'product_package_id' => $package->id,
            'status' => 'SUCCESS',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'cost_idr' => 1000,
            'margin_idr' => 0,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 1000,
            'idempotency_key' => 'review-order-'.bin2hex(random_bytes(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_reviews')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => null,
            'display_name' => 'Aktif',
            'rating' => 5,
            'body' => 'Review aktif',
            'is_active' => 1,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/content')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Content')
            ->where('reviews', function ($reviews): bool {
                $review = collect($reviews)->firstWhere('display_name', 'Aktif');

                return is_array($review) && $review['is_active'] === true;
            }));
    }

    public function test_content_page_checkbox_payload_uses_real_booleans(): void
    {
        $this->login();

        DB::table('content_pages')->where('key', 'terms')->update([
            'is_active' => 1,
            'updated_at' => now(),
        ]);
        DB::table('content_pages')->where('key', 'refund')->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);

        $this->get('/admin/content')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Content')
            ->where('pages', function ($pages): bool {
                $items = collect($pages)->keyBy('key');

                return $items->has('terms')
                    && $items->has('refund')
                    && $items->get('terms')['is_active'] === true
                    && $items->get('refund')['is_active'] === false;
            }));
    }
}
