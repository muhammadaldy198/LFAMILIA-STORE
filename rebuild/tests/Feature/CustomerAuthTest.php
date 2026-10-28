<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    private const FIXTURE_PASS = 'secure-password-123';

    private const FIXTURE_NEW_PASS = 'new-password-123';

    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_unverified_customer_cannot_login_and_no_session_is_created(): void
    {
        User::create([
            'name' => 'Unverified Customer',
            'email' => 'unverified@example.test',
            'phone' => '081234567890',
            'password' => Hash::make(self::FIXTURE_PASS),
        ]);

        $this->post('/login', [
            'email' => 'unverified@example.test',
            'password' => self::FIXTURE_PASS,
        ])->assertSessionHasErrors([
            'email' => 'Verifikasi email terlebih dahulu melalui tautan yang kami kirim sebelum masuk.',
        ]);

        $this->assertGuest('web');
    }

    public function test_customer_registers_verifies_email_then_can_login(): void
    {
        $this->post('/register', [
            'name' => 'Customer',
            'email' => 'CUSTOMER@example.test',
            'phone' => '081234567891',
            'password' => self::FIXTURE_PASS,
            'password_confirmation' => self::FIXTURE_PASS,
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Pendaftaran berhasil. Periksa email untuk memverifikasi akun sebelum masuk.');

        $user = User::where('email', 'customer@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $this->assertSame('BASIC', $user->membership_tier_code);
        $this->assertSame('6281234567891', $user->phone_normalized);
        $this->assertNull($user->email_verified_at);
        $this->assertGuest('web');

        $verificationEmail = Queue::pushed(SendTransactionalEmailJob::class)->first();
        $this->assertInstanceOf(SendTransactionalEmailJob::class, $verificationEmail);
        $this->assertSame(1, preg_match('~https?://\\S+~', $verificationEmail->text, $matches));

        $this->get($matches[0])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Email berhasil diverifikasi. Silakan masuk.');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('web');

        $this->post('/login', [
            'email' => 'customer@example.test',
            'password' => self::FIXTURE_PASS,
        ])->assertRedirect('/account');
        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/admin/panel')->assertRedirect(route('admin.login'));
    }

    public function test_password_reset_changes_the_hash_with_a_valid_token(): void
    {
        $user = User::create([
            'name' => 'Customer',
            'email' => 'reset@example.test',
            'phone' => '081234567890',
            'password' => Hash::make(self::FIXTURE_PASS),
        ]);

        // Reset request notification preserves existing token URL and
        // includes the full customer-facing security/help copy.
        $user->sendPasswordResetNotification('example-reset-token');
        Queue::assertPushed(SendTransactionalEmailJob::class, fn (SendTransactionalEmailJob $job): bool => $job->recipient === $user->email
            && str_contains($job->subject, 'Atur ulang kata sandi')
            && str_contains($job->text, route('password.reset', [
                'token' => 'example-reset-token', 'email' => $user->email,
            ]))
            && str_contains($job->text, 'Jika kamu tidak meminta ini')
            && str_contains($job->text, 'support@lfamilia.com'));

        $token = Password::broker('users')->createToken($user);

        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => self::FIXTURE_NEW_PASS,
            'password_confirmation' => self::FIXTURE_NEW_PASS,
        ])->assertRedirect();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password-123', $user->fresh()->password));
        Queue::assertPushed(SendTransactionalEmailJob::class, fn (SendTransactionalEmailJob $job): bool => $job->recipient === $user->email
            && str_contains($job->subject, 'berhasil diatur ulang')
            && str_contains($job->text, 'Kata sandi akun LFAMILIA STORE berhasil diatur ulang')
            && str_contains($job->text, 'support@lfamilia.com'));
    }

    public function test_sanctum_api_requires_a_customer_token(): void
    {
        $this->getJson('/api/account')->assertUnauthorized();

        $user = User::create([
            'name' => 'API Customer',
            'email' => 'api@example.test',
            'phone' => '081234567890',
            'password' => Hash::make(self::FIXTURE_PASS),
        ]);

        $token = $user->createToken('test-android')->plainTextToken;

        $this->withToken($token)->getJson('/api/account')
            ->assertOk()->assertJsonPath('id', $user->id);
    }
}
