<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_customer_can_register_login_and_cannot_enter_admin_panel(): void
    {
        $this->post('/register', [
            'name' => 'Customer',
            'email' => 'CUSTOMER@example.test',
            'phone' => '081234567890',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ])->assertRedirect();

        $user = User::where('email', 'customer@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $this->assertSame('BASIC', $user->membership_tier_code);
        $this->assertAuthenticatedAs($user, 'web');

        $this->get('/admin/panel')->assertRedirect(route('admin.login'));

        $this->post('/logout')->assertRedirect();
        $this->post('/login', [
            'email' => 'customer@example.test',
            'password' => 'secure-password-123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_password_reset_changes_the_hash_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'Customer',
            'email' => 'reset@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('old-password-123'),
        ]);

        $token = Password::broker('users')->createToken($user);

        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_sanctum_api_requires_a_customer_token(): void
    {
        $this->getJson('/api/account')->assertUnauthorized();

        $user = User::create([
            'name' => 'API Customer',
            'email' => 'api@example.test',
            'phone' => '081234567890',
            'password' => Hash::make('secure-password-123'),
        ]);

        $token = $user->createToken('test-android')->plainTextToken;

        $this->withToken($token)->getJson('/api/account')
            ->assertOk()->assertJsonPath('id', $user->id);
    }
}
