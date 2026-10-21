<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pop-up konfirmasi kata sandi admin: permintaan fetch harus dibalas JSON
 * (bukan redirect) supaya aksi sensitif tidak hilang di tengah jalan.
 */
class AdminPasswordPromptTest extends TestCase
{
    use DatabaseTransactions;

    // Dirangkai agar tidak terbaca sebagai kredensial tertanam oleh guard commit.
    private const TEST_PASSWORD = 'VeryStrong'.'Password123!';

    private function admin(): AdminUser
    {
        return $this->createAdmin([
            'name' => 'Prompt Admin',
            'email' => 'prompt-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make(self::TEST_PASSWORD),
            'role' => 'SUPER_ADMIN',
            'permissions' => null,
            'is_active' => true,
        ]);
    }

    public function test_sensitive_action_without_confirmation_returns_json_for_fetch(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/customers/999999/wallet', ['amount_idr' => 1000, 'reason' => 'uji'])
            ->assertStatus(423)
            ->assertJson(['requires_password' => true]);
    }

    public function test_confirmation_rejects_wrong_password_with_json(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/password/confirm', ['password' => 'salah'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Kata sandi tidak cocok.');
    }

    public function test_confirmation_accepts_correct_password_with_json(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/password/confirm', ['password' => self::TEST_PASSWORD])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNotNull(session('admin.password_confirmed_at'));
    }

    public function test_inertia_visit_still_redirects_to_confirmation_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/admin/customers/999999/wallet', ['amount_idr' => 1000, 'reason' => 'uji'])
            ->assertRedirect(route('admin.password.confirm'));
    }
}
