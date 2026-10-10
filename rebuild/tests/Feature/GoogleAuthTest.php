<?php

namespace Tests\Feature;

use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_google_login_is_disabled_without_encrypted_panel_configuration(): void
    {
        $this->get('/auth/google/redirect')->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_failed_google_callback_returns_to_login_without_authenticating(): void
    {
        IntegrationCredential::create([
            'code' => 'google_oauth',
            'config_ciphertext' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
            ],
            'is_active' => true,
        ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andThrow(new RuntimeException('OAuth state mismatch'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_verified_google_user_must_add_a_phone_before_account_access(): void
    {
        IntegrationCredential::create([
            'code' => 'google_oauth',
            'config_ciphertext' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
            ],
            'is_active' => true,
        ]);

        $googleUser = (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => 'google-sub-123',
            'name' => 'Google Customer',
            'email' => 'google@example.test',
        ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertRedirect(route('account.phone.edit'));

        $user = User::where('google_sub', 'google-sub-123')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone);
        $this->assertNull($user->password);
        $this->get('/account')->assertRedirect(route('account.phone.edit'));

        $this->put('/account/phone', ['phone' => 'invalid-phone'])->assertSessionHasErrors('phone');
        $this->get('/account')->assertRedirect(route('account.phone.edit'));

        $this->put('/account/phone', ['phone' => '081234567890'])->assertRedirect(route('account'));
        $this->assertSame('081234567890', $user->fresh()->phone);
        $this->get('/account')->assertOk();
    }
    public function test_local_password_customer_still_needs_password_confirmation_to_add_phone(): void
    {
        $user = User::create([
            'name' => 'Password Customer',
            'email' => 'local-password@example.test',
            'password' => Hash::make('local-password-123'),
        ]);

        $this->actingAs($user, 'web')
            ->put('/account/phone', ['phone' => '081234567890'])
            ->assertRedirect(route('password.confirm'));

        $this->assertNull($user->fresh()->phone);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/account/phone', ['phone' => '081234567890'])
            ->assertRedirect(route('account'));

        $this->assertSame('081234567890', $user->fresh()->phone);
    }
    public function test_existing_password_account_can_finish_phone_after_fresh_google_login(): void
    {
        $user = User::create([
            'name' => 'Returning Customer',
            'email' => 'returning-google@example.test',
            'password' => Hash::make('local-password-123'),
        ]);
        IntegrationCredential::create([
            'code' => 'google_oauth',
            'config_ciphertext' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
            ],
            'is_active' => true,
        ]);

        $googleUser = (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => 'returning-google-sub',
            'name' => 'Returning Customer',
            'email' => $user->email,
        ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertRedirect(route('account.phone.edit'));
        $this->assertSame('returning-google-sub', $user->fresh()->google_sub);

        // A new Google sign-in should be enough to complete phone onboarding.
        $this->put('/account/phone', ['phone' => '081234567890'])
            ->assertRedirect(route('account'));
        $this->assertSame('081234567890', $user->fresh()->phone);
        $this->assertNotNull($user->fresh()->password);

        // The one-time Google onboarding proof must not authorize later changes.
        $this->put('/account/phone', ['phone' => '081298765432'])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame('081234567890', $user->fresh()->phone);
    }
}
