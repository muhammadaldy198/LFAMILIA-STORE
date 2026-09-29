<?php

namespace Tests\Feature;

use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_google_login_is_disabled_without_encrypted_panel_configuration(): void
    {
        $this->get('/auth/google/redirect')->assertStatus(503);
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
        $this->get('/account')->assertRedirect(route('account.phone.edit'));

        $this->put('/account/phone', ['phone' => '081234567890'])->assertRedirect(route('account'));
        $this->get('/account')->assertOk();
    }
}
