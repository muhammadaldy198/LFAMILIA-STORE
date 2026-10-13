<?php

namespace Tests\Feature;

use App\Services\StoreThemeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StoreThemeTest extends TestCase
{
    use DatabaseTransactions;

    private function editor(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Theme Admin', 'email' => 'theme-admin@example.test',
            'password' => Hash::make('Theme-test-password-123'), 'role' => 'ADMIN',
            'permissions' => ['content.manage'], 'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');
    }

    public function test_theme_is_saved_shared_and_audited_without_overwriting_other_settings(): void
    {
        $this->editor();
        $service = app(StoreThemeService::class);
        $colors = $service->defaults();
        $colors['primary'] = '#75aaff';
        DB::table('system_settings')->updateOrInsert(['key' => 'catalog.default_margin_percent'], ['value' => '15']);
        $this->put('/admin/content/theme', ['colors' => $colors])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('#75AAFF', $service->saved()['primary']);
        $this->get('/faq')->assertInertia(fn ($page) => $page->where('theme.primary', '#75AAFF'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.theme.updated']);
        $this->assertSame(15, json_decode((string) DB::table('system_settings')->where('key', 'catalog.default_margin_percent')->value('value'), true));
        $this->put('/admin/content/theme', ['colors' => $service->defaults()])->assertSessionHasNoErrors();
        $this->assertSame($service->defaults(), $service->saved());
    }

    public function test_theme_requires_content_permission_and_accepts_only_registered_hex_colors(): void
    {
        $colors = app(StoreThemeService::class)->defaults();
        $this->put('/admin/content/theme', ['colors' => $colors])->assertRedirect('/admin/login');
        $this->actingAs($this->createAdmin([
            'name' => 'Order Admin', 'email' => 'theme-no-access@example.test',
            'password' => Hash::make('Theme-test-password-123'), 'role' => 'ADMIN',
            'permissions' => ['orders.view'], 'is_active' => true,
        ]), 'admin')->put('/admin/content/theme', ['colors' => $colors])->assertForbidden();
        $this->editor();
        $colors['primary'] = 'url(https://example.test)';
        $this->put('/admin/content/theme', ['colors' => $colors])->assertSessionHasErrors('colors.primary');
        $colors = app(StoreThemeService::class)->defaults();
        $colors['arbitrary-css'] = '#FFFFFF';
        $this->put('/admin/content/theme', ['colors' => $colors])->assertSessionHasErrors('colors');
    }

    public function test_unreadable_palette_is_rejected_and_corrupt_settings_fall_back(): void
    {
        $this->editor();
        $service = app(StoreThemeService::class);
        $colors = $service->defaults();
        $colors['text'] = $colors['card'];
        $this->put('/admin/content/theme', ['colors' => $colors])->assertSessionHasErrors('colors.text');
        DB::table('system_settings')->updateOrInsert(['key' => 'store.theme'], ['value' => '"invalid"']);
        $this->assertSame($service->defaults(), $service->saved());
        $this->assertSame($service->defaults(), $service->sanitize(['primary' => 'red', 'unknown' => '#FFFFFF']));
    }
}
