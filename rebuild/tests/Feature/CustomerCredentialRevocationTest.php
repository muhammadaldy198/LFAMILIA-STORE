<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerCredentialRevocationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function customer(string $email, ?string $password = 'old-customer-password-123'): User
    {
        $user = User::create([
            'name' => 'Credential Security Customer',
            'email' => $email,
            'phone' => '081234567890',
            'password' => $password === null ? null : Hash::make($password),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_profile_password_change_invalidates_existing_reset_token_but_new_token_still_works(): void
    {
        $user = $this->customer('profile-reset-token@example.test');
        $staleToken = Password::broker('users')->createToken($user);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $this->post('/login', [
            'email' => $user->email, 'password' => 'old-customer-password-123',
        ])->assertRedirect();
        $this->put('/account/password', [
            'current_password' => 'old-customer-password-123',
            'password' => 'changed-customer-password-123',
            'password_confirmation' => 'changed-customer-password-123',
        ])->assertRedirect();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        Auth::guard('web')->logoutCurrentDevice();
        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $staleToken,
            'password' => 'attacker-password-123',
            'password_confirmation' => 'attacker-password-123',
        ])->assertSessionHasErrors();

        $freshToken = Password::broker('users')->createToken($user->fresh());
        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $freshToken,
            'password' => 'fresh-reset-password-123',
            'password_confirmation' => 'fresh-reset-password-123',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('fresh-reset-password-123', $user->fresh()->password));
    }

    public function test_first_password_for_google_only_customer_revokes_existing_reset_token(): void
    {
        $user = $this->customer('first-password-token@example.test', null);
        Password::broker('users')->createToken($user);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $this->actingAs($user, 'web')->put('/account/password', [
            'password' => 'first-customer-password-123',
            'password_confirmation' => 'first-customer-password-123',
        ])->assertRedirect();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_email_change_continues_invalidating_tokens_for_previous_email(): void
    {
        $user = $this->customer('before-email-change@example.test');
        Password::broker('users')->createToken($user);
        $this->actingAs($user, 'web')->put('/account/profile', [
            'name' => $user->name,
            'email' => 'after-email-change@example.test',
            'phone' => '081234567890',
            'current_password' => 'old-customer-password-123',
        ])->assertRedirect();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'before-email-change@example.test']);
    }

    public function test_stateful_api_session_expires_after_password_reset_and_new_login_works(): void
    {
        $user = $this->customer('stateful-api-security@example.test');
        $this->post('/login', [
            'email' => $user->email, 'password' => 'old-customer-password-123',
        ])->assertRedirect();
        $this->get('/account')->assertOk();

        $headers = ['Origin' => 'http://localhost'];
        $this->getJson('/api/account', $headers)->assertOk()->assertJsonPath('id', $user->id);
        $originalStamp = session('security.customer_auth_fingerprint');
        $this->assertIsString($originalStamp);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'new-customer-password-123',
            'password_confirmation' => 'new-customer-password-123',
        ]);
        Auth::forgetGuards();
        $this->getJson('/api/account', $headers)->assertUnauthorized();
        $this->assertGuest('web');

        $this->post('/login', [
            'email' => $user->email, 'password' => 'new-customer-password-123',
        ])->assertRedirect();
        $this->getJson('/api/account', $headers)->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_bearer_token_issued_after_password_change_is_unaffected_by_api_session_middleware(): void
    {
        $user = $this->customer('bearer-api-security@example.test');
        $user->forceFill(['password' => Hash::make('new-customer-password-123')])->save();
        $token = $user->createToken('fresh-api-session')->plainTextToken;

        $this->withToken($token)->getJson('/api/account')
            ->assertOk()->assertJsonPath('id', $user->id);
    }
}
