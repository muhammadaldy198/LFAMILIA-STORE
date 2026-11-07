<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Jobs\SendTransactionalEmailJob;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Http\Middleware\EnsurePhone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    private function customer(string $email, bool $withPassword = true, ?string $phone = '081234567890'): User
    {
        return User::create([
            'name' => 'Existing Customer',
            'email' => $email,
            'phone' => $phone,
            'phone_normalized' => $phone ? CreateNewUser::normalizePhone($phone) : null,
            'password' => $withPassword ? Hash::make('original-password-123') : null,
        ]);
    }

    private function profile(string $email, string $phone = '081234567890', ?string $password = null): array
    {
        return array_filter([
            'name' => 'Existing Customer',
            'email' => $email,
            'phone' => $phone,
            'current_password' => $password,
        ], fn ($value): bool => $value !== null);
    }

    private function google(string $email, string $subject): void
    {
        IntegrationCredential::create([
            'code' => 'google_oauth',
            'config_ciphertext' => ['client_id' => 'test-client', 'client_secret' => 'test-secret'],
            'is_active' => true,
        ]);
        $google = (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => $subject, 'name' => 'Google Customer', 'email' => $email,
        ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($google);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
    }

    public function test_unverified_preregistered_email_google_claim_erases_attacker_credentials(): void
    {
        $user = $this->customer('preregistered@example.test');
        $this->google($user->email, 'verified-victim-sub');

        $this->get('/auth/google/callback')->assertRedirect(route('account.phone.edit'));
        $adopted = $user->fresh();
        $this->assertSame('verified-victim-sub', $adopted->google_sub);
        $this->assertNotNull($adopted->email_verified_at);
        $this->assertNull($adopted->password);
        $this->assertNull($adopted->phone);
        $this->assertNull($adopted->phone_normalized);
        $this->post('/logout');
        $this->post('/login', [
            'email' => 'preregistered@example.test',
            'password' => 'original-password-123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_verified_email_account_linking_google_keeps_password_and_phone(): void
    {
        $user = $this->customer('verified-before-google@example.test');
        $user->forceFill(['email_verified_at' => now()])->saveQuietly();
        $this->google($user->email, 'verified-registered-sub');

        $this->get('/auth/google/callback')->assertRedirect(route('account'));
        $this->assertSame('081234567890', $user->fresh()->phone);
        $this->assertTrue(Hash::check('original-password-123', $user->fresh()->password));
        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email, 'password' => 'original-password-123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_repeat_google_signin_keeps_existing_credentials(): void
    {
        $user = $this->customer('linked-google@example.test');
        $user->forceFill(['google_sub' => 'already-linked'])->saveQuietly();
        $this->google($user->email, 'already-linked');

        $this->get('/auth/google/callback')->assertRedirect(route('account'));
        $this->assertSame('081234567890', $user->fresh()->phone);
        $this->assertTrue(Hash::check('original-password-123', $user->fresh()->password));
    }

    public function test_new_google_account_still_gets_created_without_password_or_phone(): void
    {
        $this->google('brand-new-google@example.test', 'new-google-sub');

        $this->get('/auth/google/callback')->assertRedirect(route('account.phone.edit'));
        $user = User::where('google_sub', 'new-google-sub')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->phone);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_passwordless_google_email_change_requires_fresh_google_proof(): void
    {
        $user = $this->customer('no-proof@example.test', false);
        $user->forceFill(['google_sub' => 'google-no-proof'])->saveQuietly();
        $this->actingAs($user, 'web')->put('/account/profile',
            $this->profile('stolen@example.test'))->assertSessionHasErrors('current_password');
        $this->assertSame('no-proof@example.test', $user->fresh()->email);
    }

    public function test_expired_google_proof_cannot_change_email(): void
    {
        $user = $this->customer('expired-proof@example.test', false);
        $user->forceFill(['google_sub' => 'google-expired'])->saveQuietly();
        $this->actingAs($user, 'web')->withSession([
            'auth.google_reauth_at' => time() - 601,
            'auth.google_reauth_user_id' => (string) $user->id,
        ])->put('/account/profile', $this->profile('changed-expired@example.test'))
            ->assertSessionHasErrors('current_password');
        $this->assertSame('expired-proof@example.test', $user->fresh()->email);
    }

    public function test_google_proof_from_different_user_cannot_change_email(): void
    {
        $user = $this->customer('wrong-proof@example.test', false);
        $user->forceFill(['google_sub' => 'google-wrong-proof'])->saveQuietly();
        $this->actingAs($user, 'web')->withSession([
            'auth.google_reauth_at' => time(),
            'auth.google_reauth_user_id' => (string) ($user->id + 999),
        ])->put('/account/profile', $this->profile('changed-wrong@example.test'))
            ->assertSessionHasErrors('current_password');
        $this->assertSame('wrong-proof@example.test', $user->fresh()->email);
    }

    public function test_fresh_reauth_callback_allows_one_email_change_and_notifies_old_email(): void
    {
        $user = $this->customer('original-google@example.test', false);
        $user->forceFill(['google_sub' => 'google-reauth-sub'])->saveQuietly();
        $token = $user->createToken('old-api')->plainTextToken;
        $this->assertNotSame('', $token);
        $this->google($user->email, 'google-reauth-sub');

        $this->actingAs($user, 'web')->withSession([
            'auth.google_reauth_pending_at' => time(),
            'auth.google_reauth_pending_user_id' => (string) $user->id,
        ])->get('/auth/google/callback')->assertRedirect(route('account.profile'))
            ->assertSessionHas('auth.google_reauth_user_id', (string) $user->id);

        $this->put('/account/profile', $this->profile('new-google@example.test'))->assertRedirect();
        $this->assertSame('new-google@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull($user->fresh()->remember_token);
        Queue::assertPushed(SendTransactionalEmailJob::class,
            fn (SendTransactionalEmailJob $job): bool => $job->recipient === 'original-google@example.test'
                && $job->templateKey === 'account_email_changed'
                && str_contains($job->text, 'new-google@example.test'));
        Queue::assertPushed(SendTransactionalEmailJob::class,
            fn (SendTransactionalEmailJob $job): bool => $job->recipient === 'new-google@example.test'
                && $job->templateKey === 'verification');

        $this->put('/account/profile', $this->profile('third-google@example.test'))
            ->assertSessionHasErrors('current_password');
        $this->assertSame('new-google@example.test', $user->fresh()->email);
    }

    public function test_reauth_with_another_google_identity_cannot_change_signed_in_account(): void
    {
        $user = $this->customer('my-google@example.test', false);
        $user->forceFill(['google_sub' => 'own-sub'])->saveQuietly();
        $this->google('other-google@example.test', 'other-sub');

        $this->actingAs($user, 'web')->withSession([
            'auth.google_reauth_pending_at' => time(),
            'auth.google_reauth_pending_user_id' => (string) $user->id,
        ])->get('/auth/google/callback')->assertRedirect(route('account.profile'))
            ->assertSessionHasErrors('google');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseMissing('users', ['email' => 'other-google@example.test']);
    }

    public function test_passwordless_account_without_google_must_set_password_first(): void
    {
        $user = $this->customer('no-google@example.test', false);

        $this->actingAs($user, 'web')->put('/account/profile',
            $this->profile('different@example.test'))->assertSessionHasErrors('current_password');
        $this->assertSame('no-google@example.test', $user->fresh()->email);
    }

    public function test_password_account_email_change_requires_correct_current_password(): void
    {
        $user = $this->customer('password-email@example.test');
        $this->actingAs($user, 'web')->put('/account/profile',
            $this->profile('password-new@example.test'))->assertSessionHasErrors('current_password');
        $this->put('/account/profile',
            $this->profile('password-new@example.test', '081234567890', 'original-password-123'))
            ->assertRedirect();
        $this->assertSame('password-new@example.test', $user->fresh()->email);
    }

    public function test_existing_phone_change_requires_current_password(): void
    {
        $user = $this->customer('phone-protected@example.test');
        $this->actingAs($user, 'web')->put('/account/profile',
            $this->profile($user->email, '081298765432'))->assertSessionHasErrors('current_password');
        $this->put('/account/profile',
            $this->profile($user->email, '081298765432', 'incorrect'))
            ->assertSessionHasErrors('current_password');
        $this->assertSame('081234567890', $user->fresh()->phone);
        $this->put('/account/profile',
            $this->profile($user->email, '081298765432', 'original-password-123'))
            ->assertRedirect();
        $this->assertSame('081298765432', $user->fresh()->phone);
    }

    public function test_name_only_change_with_same_phone_needs_no_password(): void
    {
        $user = $this->customer('name-only@example.test');
        $this->actingAs($user, 'web')->put('/account/profile', [
            ...$this->profile($user->email), 'name' => 'Updated Name',
        ])->assertRedirect();
        $this->assertSame('Updated Name', $user->fresh()->name);
    }

    public function test_first_phone_assignment_needs_no_password_in_profile(): void
    {
        $user = $this->customer('first-phone@example.test', true, null);
        $this->withoutMiddleware(EnsurePhone::class);
        $this->actingAs($user, 'web')->put('/account/profile',
            $this->profile($user->email))->assertRedirect();
        $this->assertSame('081234567890', $user->fresh()->phone);
    }

    public function test_passwordless_phone_change_keeps_existing_semantics(): void
    {
        $user = $this->customer('google-phone@example.test', false);
        $user->forceFill(['google_sub' => 'some-google-sub'])->saveQuietly();

        $this->actingAs($user, 'web')->put('/account/profile',
            $this->profile($user->email, '081298765432'))->assertRedirect();
        $this->assertSame('081298765432', $user->fresh()->phone);
    }
}
