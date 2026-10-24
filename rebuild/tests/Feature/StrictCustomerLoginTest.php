<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Butir 3 — login ketat: akun yang emailnya belum diverifikasi ditolak dan TIDAK
 * menghasilkan sesi; setelah verifikasi login berhasil; akun Google otomatis
 * terverifikasi; login admin tidak rusak.
 */
class StrictCustomerLoginTest extends TestCase
{
    private const FIXTURE_PASS = 'strict-password-123';

    private const FIXTURE_BAD_PASS = 'bukan-sandi-ini';

    private const FIXTURE_ADMIN_PASS = 'strict-admin-123';

    private const FIXTURE_OAUTH_ID = 'strict-client-id';

    private const FIXTURE_OAUTH_SECRET = 'strict-client-secret';

    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
    }

    private function unverified(string $email = 'strict-unverified@example.test'): User
    {
        $user = User::create([
            'name' => 'Belum Verifikasi', 'email' => $email, 'phone' => '081200000001',
            'password' => Hash::make(self::FIXTURE_PASS),
        ]);
        $this->assertNull($user->fresh()->email_verified_at, 'fixture harus benar-benar belum terverifikasi');

        return $user;
    }

    private function verified(string $email = 'strict-verified@example.test'): User
    {
        $user = $this->unverified($email);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_unverified_customer_is_rejected_and_no_session_is_created(): void
    {
        $user = $this->unverified();

        $this->post('/login', [
            'email' => $user->email, 'password' => self::FIXTURE_PASS,
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertFalse(auth('web')->check());
        $this->assertNull(auth('web')->user());
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_wrong_password_on_unverified_account_does_not_reveal_verification_state(): void
    {
        $user = $this->unverified('strict-wrong-password@example.test');

        $this->post('/login', [
            'email' => $user->email, 'password' => self::FIXTURE_BAD_PASS,
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertArrayNotHasKey('status', session()->all());
    }

    public function test_verified_customer_can_login_after_clicking_verification_link(): void
    {
        // Daftar lewat endpoint sungguhan supaya email verifikasi benar-benar terkirim.
        $this->post('/register', [
            'name' => 'Alur Verifikasi', 'email' => 'strict-flow@example.test',
            'phone' => '081200000003', 'password' => self::FIXTURE_PASS,
            'password_confirmation' => self::FIXTURE_PASS,
        ])->assertRedirect(route('login'));
        $user = User::where('email', 'strict-flow@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        // Sebelum verifikasi: ditolak, tidak ada sesi.
        $this->post('/login', ['email' => $user->email, 'password' => self::FIXTURE_PASS])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');

        // Tautan diambil dari email verifikasi yang benar-benar dikirim sistem.
        $this->assertGreaterThan(0, Queue::pushed(SendTransactionalEmailJob::class)->count(), 'email verifikasi tidak terkirim');
        $mails = Queue::pushed(SendTransactionalEmailJob::class)
            ->filter(fn (SendTransactionalEmailJob $job): bool => str_contains((string) $job->text, '/email/confirm/'.$user->id.'/'));
        $this->assertSame(1, $mails->count(), 'email verifikasi untuk akun ini tidak ditemukan');
        $this->assertSame(1, preg_match('~https?://\S+~', (string) $mails->first()->text, $matches));

        $this->get($matches[0])->assertRedirect(route('login'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('web');

        // Sesudah verifikasi: login berhasil.
        $this->post('/login', ['email' => $user->email, 'password' => self::FIXTURE_PASS])
            ->assertRedirect('/account');
        $this->assertAuthenticatedAs($user->fresh(), 'web');
    }

    public function test_registration_does_not_log_the_customer_in(): void
    {
        $this->post('/register', [
            'name' => 'Pendaftar Baru', 'email' => 'strict-register@example.test',
            'phone' => '081200000002', 'password' => self::FIXTURE_PASS,
            'password_confirmation' => self::FIXTURE_PASS,
        ])->assertRedirect(route('login'));

        $this->assertGuest('web');
        $user = User::where('email', 'strict-register@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public function test_tampered_verification_link_is_rejected(): void
    {
        $user = $this->unverified('strict-tampered@example.test');
        $valid = URL::temporarySignedRoute(
            'customer.verification.verify', now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        // Hash email salah dan tanda tangan dihapus -> harus ditolak, email tetap belum terverifikasi.
        $this->get(str_replace(sha1($user->getEmailForVerification()), sha1('orang-lain@example.test'), $valid))
            ->assertForbidden();
        $this->get(route('customer.verification.verify', ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]))
            ->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);

        // Tautan sah tetap bekerja.
        $this->get($valid)->assertRedirect(route('login'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_google_account_is_automatically_verified_and_can_log_in(): void
    {
        IntegrationCredential::create([
            'code' => 'google_oauth',
            'config_ciphertext' => ['client_id' => self::FIXTURE_OAUTH_ID, 'client_secret' => self::FIXTURE_OAUTH_SECRET],
            'is_active' => true,
        ]);
        $googleUser = (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => 'strict-google-sub', 'name' => 'Google Ketat', 'email' => 'strict-google@example.test',
        ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertRedirect(route('account.phone.edit'));

        $user = User::where('google_sub', 'strict-google-sub')->firstOrFail();
        $this->assertNotNull($user->email_verified_at, 'akun Google harus otomatis terverifikasi');
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_existing_unverified_account_is_verified_by_google_login(): void
    {
        $user = $this->unverified('strict-google-existing@example.test');
        IntegrationCredential::create([
            'code' => 'google_oauth',
            'config_ciphertext' => ['client_id' => self::FIXTURE_OAUTH_ID, 'client_secret' => self::FIXTURE_OAUTH_SECRET],
            'is_active' => true,
        ]);
        $googleUser = (new GoogleUser)->setRaw(['email_verified' => true])->map([
            'id' => 'strict-google-existing-sub', 'name' => 'Google Ketat', 'email' => $user->email,
        ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at, 'akun lokal belum verifikasi harus ikut terverifikasi lewat Google');
    }

    public function test_admin_login_still_works_and_is_not_blocked_by_email_verification(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Admin Ketat', 'email' => 'strict-admin@example.test',
            'password' => bcrypt('strict-admin-123'), 'role' => 'SUPER_ADMIN',
            'permissions' => [], 'is_active' => true,
        ]);
        $this->assertInstanceOf(AdminUser::class, $admin);

        $this->post('/admin/login', ['email' => 'strict-admin@example.test', 'password' => self::FIXTURE_ADMIN_PASS])
            ->assertRedirect(route('admin.panel'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get('/admin/panel')->assertOk();
        // Login admin tidak boleh ikut membuat sesi pelanggan.
        $this->assertGuest('web');

        $this->post('/admin/logout')->assertRedirect();
        $this->assertGuest('admin');
    }

    public function test_unverified_customer_can_request_a_new_verification_link_before_login(): void
    {
        $user = $this->unverified();

        $this->from('/login')
            ->post('/email/verification-resend', ['email' => $user->email])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->assertSame(1, Queue::pushed(SendTransactionalEmailJob::class)
            ->filter(fn (SendTransactionalEmailJob $job): bool => $job->recipient === $user->email
                && str_contains((string) $job->text, '/email/confirm/'.$user->id.'/'))->count());
    }

    public function test_resend_does_not_reveal_whether_an_email_is_registered(): void
    {
        $this->from('/login')
            ->post('/email/verification-resend', ['email' => 'tidak-terdaftar@example.test'])
            ->assertRedirect('/login')
            ->assertSessionHas('status');
    }
}
