<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\AdminChoiceSettings;
use App\Services\PublicOrderStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminChoiceSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN'): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Choice Tester',
            'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => bcrypt('only-for-tests'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : ['settings.manage'],
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_superadmin_can_edit_labels_and_add_choices_without_changing_existing_codes(): void
    {
        $this->login();
        $defaults = app(AdminChoiceSettings::class)->all();
        $defaults['cancellations'][0]['label'] = 'Stok pemasok kosong';
        $defaults['cancellations'][] = ['code' => 'permintaan_admin', 'label' => 'Permintaan admin', 'active' => true];
        $defaults['support'][] = ['code' => 'PRODUCT', 'label' => 'Informasi Produk', 'active' => true];

        $this->put('/admin/choice-settings', $defaults)
            ->assertRedirect()->assertSessionHasNoErrors();

        $choices = app(AdminChoiceSettings::class);
        $this->assertSame('Stok pemasok kosong', $choices->labels('cancellations')['stok_habis']);
        $this->assertSame('Permintaan admin', $choices->labels('cancellations')['permintaan_admin']);
        $this->assertSame('Informasi Produk', $choices->labels('support')['PRODUCT']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.admin_choices.updated',
            'target_id' => 'store.admin_choices',
        ]);
    }

    public function test_existing_reason_codes_cannot_be_removed(): void
    {
        $this->login();
        $defaults = app(AdminChoiceSettings::class)->all();
        array_shift($defaults['cancellations']);

        $this->put('/admin/choice-settings', $defaults)
            ->assertSessionHasErrors('cancellations');
    }

    public function test_regular_admin_cannot_change_superadmin_choices(): void
    {
        $this->login('ADMIN');
        $this->get('/admin/choice-settings')->assertForbidden();
        $this->put('/admin/choice-settings', app(AdminChoiceSettings::class)->all())->assertForbidden();
    }

    public function test_public_status_label_reads_existing_customer_text_editor(): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => 'store.customer_presentation'], [
            'value' => json_encode(['text' => ['order.status.SUCCESS.label' => 'Pesanan sudah terkirim']], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
        $this->assertSame('Pesanan sudah terkirim', app(PublicOrderStatusService::class)->label('SUCCESS'));
    }
}
