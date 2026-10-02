<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN', array $permissions = ['settings.manage']): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Settings Test',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => Hash::make('settings-restoration-password'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function storePayload(array $overrides = []): array
    {
        return array_replace([
            'store_name' => 'LFAMILIA STORE TEST',
            'tagline' => 'Top up cepat untuk pengujian',
            'support_whatsapp' => '+6281234567890',
            'instagram_url' => 'https://instagram.com/lfamilia.test',
            'email' => 'support@example.test',
            'discord_url' => 'https://discord.gg/example',
            'support_url' => '/contact',
            'business_hours' => 'Setiap hari, 08.00–23.00 WIB',
            'legal_name' => 'PT LFAMILIA TEST',
            'registration_id' => 'NIB-TEST-123',
            'address' => 'Jakarta, Indonesia',
        ], $overrides);
    }

    public function test_settings_workspace_is_dedicated_and_uses_friendly_membership_fields(): void
    {
        $this->login();

        DB::table('membership_tiers')->where('code', 'SILVER')->update([
            'requirements' => json_encode([
                'minimum_spend_idr' => 100000,
                'legacy_flag' => true,
            ], JSON_THROW_ON_ERROR),
            'benefits' => json_encode([
                'discount_bps' => 150,
                'benefit_notes' => "Prioritas layanan\nPromo khusus",
                'legacy_benefit' => 'dipertahankan',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings')
                ->where('canExport', true)
                ->has('settings')
                ->has('tiers', 6)
                ->where('tiers.1.code', 'SILVER')
                ->where('tiers.1.minimum_spend_idr', 100000)
                ->where('tiers.1.discount_percent', 1.5)
                ->where('tiers.1.benefit_notes', "Prioritas layanan\nPromo khusus")
                ->where('tiers.1.has_legacy_requirements', true)
                ->where('tiers.1.has_legacy_benefits', true)
                ->has('summary'));
    }

    public function test_regular_admin_with_permission_can_manage_settings_but_cannot_export(): void
    {
        $this->login('ADMIN', ['dashboard.view', 'settings.manage']);

        $this->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings')
                ->where('canExport', false));

        $this->get('/admin/configuration/export')->assertForbidden();
    }

    public function test_store_settings_save_version_audit_and_are_used_by_customer_frontend(): void
    {
        $admin = $this->login();

        DB::table('system_settings')->insert([
            'key' => 'store.name',
            'value' => json_encode('OLD STORE', JSON_THROW_ON_ERROR),
            'version' => 4,
            'updated_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->put('/admin/settings', $this->storePayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'LFAMILIA STORE TEST',
            json_decode((string) DB::table('system_settings')->where('key', 'store.name')->value('value'), true)
        );
        $this->assertSame(
            5,
            (int) DB::table('system_settings')->where('key', 'store.name')->value('version')
        );
        $this->assertSame(
            'PT LFAMILIA TEST',
            json_decode((string) DB::table('system_settings')->where('key', 'store.legal_name')->value('value'), true)
        );
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'action' => 'settings.store.updated',
            'target_type' => 'system_setting',
            'target_id' => 'store',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('storefront.storeName', 'LFAMILIA STORE TEST')
                ->where('storefront.supportWhatsapp', '+6281234567890')
                ->where('storefront.supportEmail', 'support@example.test')
                ->where('storefront.supportHours', 'Setiap hari, 08.00–23.00 WIB'));

        $this->get('/status')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('merchant.legalName', 'PT LFAMILIA TEST')
                ->where('merchant.registrationId', 'NIB-TEST-123')
                ->where('merchant.address', 'Jakarta, Indonesia'));
    }

    public function test_store_settings_validate_urls_and_support_link_server_side(): void
    {
        $this->login();

        $this->put('/admin/settings', $this->storePayload([
            'instagram_url' => 'javascript:alert(1)',
            'support_url' => '//evil.example/path',
        ]))->assertSessionHasErrors(['instagram_url', 'support_url']);
    }

    public function test_friendly_membership_update_preserves_legacy_metadata_and_is_audited(): void
    {
        $admin = $this->login();

        DB::table('membership_tiers')->where('code', 'GOLD')->update([
            'requirements' => json_encode([
                'minimum_spend_idr' => 100000,
                'legacy_requirement' => 'keep',
            ], JSON_THROW_ON_ERROR),
            'benefits' => json_encode([
                'discount_bps' => 100,
                'legacy_benefit' => 'keep',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->put('/admin/settings/membership/GOLD', [
            'is_active' => true,
            'minimum_spend_idr' => 250000,
            'discount_percent' => 2.75,
            'benefit_notes' => "Prioritas layanan\nBonus promo tertentu",
        ])->assertRedirect()->assertSessionHasNoErrors();

        $tier = DB::table('membership_tiers')->where('code', 'GOLD')->firstOrFail();
        $requirements = json_decode((string) $tier->requirements, true, 512, JSON_THROW_ON_ERROR);
        $benefits = json_decode((string) $tier->benefits, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(250000, $requirements['minimum_spend_idr']);
        $this->assertSame('keep', $requirements['legacy_requirement']);
        $this->assertSame(275, $benefits['discount_bps']);
        $this->assertSame('keep', $benefits['legacy_benefit']);
        $this->assertSame("Prioritas layanan\nBonus promo tertentu", $benefits['benefit_notes']);

        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'action' => 'membership_tier.updated',
            'target_type' => 'membership_tier',
            'target_id' => 'GOLD',
        ]);
    }

    public function test_legacy_membership_json_payload_remains_compatible(): void
    {
        $this->login();

        $this->put('/admin/settings/membership/SILVER', [
            'is_active' => true,
            'requirements' => '{"minimum_spend_idr":150000}',
            'benefits' => '{"discount_bps":225}',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $tier = DB::table('membership_tiers')->where('code', 'SILVER')->firstOrFail();
        $this->assertSame(
            ['minimum_spend_idr' => 150000],
            json_decode((string) $tier->requirements, true, 512, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(
            ['discount_bps' => 225],
            json_decode((string) $tier->benefits, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function test_safe_configuration_export_is_super_only_audited_and_excludes_credentials(): void
    {
        $admin = $this->login();

        DB::table('system_settings')->updateOrInsert(['key' => 'store.name'], [
            'value' => json_encode('EXPORT STORE', JSON_THROW_ON_ERROR),
            'version' => 1,
            'updated_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('system_settings')->updateOrInsert(['key' => 'integration.fake_secret'], [
            'value' => json_encode('should-never-export', JSON_THROW_ON_ERROR),
            'version' => 1,
            'updated_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'is_active' => true,
            'config_ciphertext' => ['server_key' => 'super-secret-value'],
        ]);

        $response = $this->get('/admin/configuration/export')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $content = $response->getContent();
        $this->assertStringContainsString('lfamilia-safe-config-v1', $content);
        $this->assertStringContainsString('EXPORT STORE', $content);
        $this->assertStringNotContainsString('should-never-export', $content);
        $this->assertStringNotContainsString('super-secret-value', $content);
        $this->assertStringNotContainsString('integration_credentials', $content);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => (string) $admin->id,
            'action' => 'configuration.exported',
            'target_type' => 'configuration',
            'target_id' => 'safe-json',
        ]);
    }

    public function test_settings_permission_is_enforced_server_side(): void
    {
        $this->login('ADMIN', ['dashboard.view']);

        $this->get('/admin/settings')->assertForbidden();
        $this->put('/admin/settings', $this->storePayload())->assertForbidden();
        $this->put('/admin/settings/membership/SILVER', [
            'is_active' => true,
            'minimum_spend_idr' => 100000,
            'discount_percent' => 1,
            'benefit_notes' => '',
        ])->assertForbidden();
    }
}
