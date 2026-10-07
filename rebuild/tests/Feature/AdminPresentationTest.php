<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\CustomerPresentationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPresentationTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(array $permissions = [], string $role = 'ADMIN'): AdminUser
    {
        return $this->createAdmin([
            'name' => 'Content Admin', 'email' => 'presentation-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('StrongPassword123!'), 'role' => $role,
            'permissions' => $permissions, 'is_active' => true,
        ]);
    }

    public function test_content_admin_saves_public_copy_and_independent_mobile_desktop_geometry(): void
    {
        $service = app(CustomerPresentationService::class);
        $key = array_key_first($service->registry());
        $payload = $service->saved();
        $payload['text'][$key] = 'Tulisan toko diperbarui';
        $payload['typography']['mobile']['page'] = 26;
        $payload['typography']['desktop']['page'] = 32;
        $payload['typography']['mobile']['controlHeight'] = 38;
        $payload['sections']['tutorial'] = false;
        $this->actingAs($this->admin(['content.manage']), 'admin')
            ->get('/admin/content/presentation')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Presentation'));
        $this->put('/admin/content/presentation', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/faq')->assertOk()->assertInertia(fn ($page) => $page
            ->where('storefront.presentation.text', fn ($text) => ($text[$key] ?? null) === 'Tulisan toko diperbarui')
            ->where('storefront.presentation.typography.mobile.page', 26)
            ->where('storefront.presentation.typography.desktop.page', 32)
            ->where('storefront.presentation.sections.tutorial', false));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.presentation.updated']);
    }

    public function test_admin_without_content_permission_and_guest_cannot_edit_presentation(): void
    {
        $payload = app(CustomerPresentationService::class)->saved();
        $this->get('/admin/content/presentation')->assertRedirect('/admin/login');
        $this->actingAs($this->admin(['orders.view']), 'admin')->put('/admin/content/presentation', $payload)->assertForbidden();
    }

    public function test_invalid_typography_and_unregistered_keys_are_rejected(): void
    {
        $this->actingAs($this->admin(['content.manage']), 'admin');
        $payload = app(CustomerPresentationService::class)->saved();
        $payload['typography']['mobile']['page'] = 500;
        $this->put('/admin/content/presentation', $payload)->assertSessionHasErrors('typography');
        $payload = app(CustomerPresentationService::class)->saved();
        $payload['text']['integration.api_key'] = 'invalid';
        $this->put('/admin/content/presentation', $payload)->assertSessionHasErrors('text');
        $this->assertDatabaseMissing('system_settings', ['key' => 'store.customer_presentation']);
    }

    public function test_public_payload_drops_unknown_settings_and_text_renders_as_text(): void
    {
        $service = app(CustomerPresentationService::class);
        $key = array_key_first($service->registry());
        DB::table('system_settings')->updateOrInsert(['key' => 'store.customer_presentation'], [
            'value' => json_encode(['text' => [$key => '<script>alert(1)</script>', 'secret' => 'hidden'], 'api_key' => 'hidden']),
        ]);
        $saved = $service->saved();
        $this->assertSame('<script>alert(1)</script>', $saved['text'][$key]);
        $this->assertArrayNotHasKey('secret', $saved['text']);
        $this->assertArrayNotHasKey('api_key', $saved);
        $this->get('/support')->assertOk();
    }

    public function test_global_search_respects_customer_and_catalog_permissions(): void
    {
        User::create(['name' => 'UniquePrivateCustomer', 'email' => 'private-search@example.test', 'password' => Hash::make('StrongPassword123!')]);
        $this->actingAs($this->admin(['orders.view']), 'admin')
            ->getJson('/admin/search?q=UniquePrivateCustomer')->assertOk()->assertExactJson(['results' => []]);
        $this->actingAs($this->admin(['customers.view']), 'admin')
            ->getJson('/admin/search?q=UniquePrivateCustomer')->assertOk()->assertJsonPath('results.0.title', 'UniquePrivateCustomer');
    }
}
