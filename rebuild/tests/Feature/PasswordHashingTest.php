<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Audit PR 9 — password hashing moves from bcrypt to Argon2id.
 *
 * Existing accounts hold bcrypt hashes. They must keep working: the
 * Argon2id hasher verifies them through password_verify, and the login
 * guards rehash them to Argon2id on the next successful login. These
 * tests pin the driver configuration and prove the migration end to end
 * for both the customer and the admin guard, so nobody is locked out or
 * forced through a password reset by the switch.
 */
class PasswordHashingTest extends TestCase
{
    private const PASS = 'argon-migration-password-123';

    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
    }

    private function legacyBcryptHash(): string
    {
        $hash = password_hash(self::PASS, PASSWORD_BCRYPT);
        $this->assertStringStartsWith('$2y$', $hash, 'fixture harus benar-benar hash bcrypt lama');

        return $hash;
    }

    public function test_configuration_selects_argon2id_with_the_pinned_cost(): void
    {
        $this->assertSame('argon2id', config('hashing.driver'));
        $this->assertSame(65536, config('hashing.argon.memory'));
        $this->assertSame(4, config('hashing.argon.time'));
        $this->assertSame(1, config('hashing.argon.threads'));
        $this->assertTrue(config('hashing.rehash_on_login'));
    }

    public function test_new_hashes_are_argon2id_and_verify(): void
    {
        $hash = Hash::make(self::PASS);

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertSame('argon2id', Hash::info($hash)['algoName']);
        $this->assertTrue(Hash::check(self::PASS, $hash));
        $this->assertFalse(Hash::check('bukan-password-ini', $hash));
        $this->assertFalse(Hash::needsRehash($hash));
    }

    public function test_legacy_bcrypt_hash_verifies_through_the_default_driver(): void
    {
        $legacy = $this->legacyBcryptHash();

        // Hash::check is what the password-confirmation and
        // current-password endpoints call; it must answer, not throw,
        // for a not-yet-migrated user.
        $this->assertTrue(Hash::check(self::PASS, $legacy));
        $this->assertFalse(Hash::check('bukan-password-ini', $legacy));
        $this->assertTrue(Hash::needsRehash($legacy));
    }

    public function test_verified_customer_with_bcrypt_password_logs_in_and_is_rehashed(): void
    {
        $user = User::create([
            'name' => 'Pelanggan Lama',
            'email' => 'legacy-customer@example.test',
            'phone' => '081200000010',
            'password' => $this->legacyBcryptHash(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASS,
        ])->assertRedirect('/account');
        $this->assertAuthenticatedAs($user->fresh(), 'web');

        $stored = $user->fresh()->password;
        $this->assertStringStartsWith('$argon2id$', $stored, 'login harus menaikkan hash ke Argon2id');
        $this->assertTrue(Hash::check(self::PASS, $stored));
    }

    public function test_bcrypt_driver_still_verifies_argon2id_hashes(): void
    {
        // If the driver were ever switched back to bcrypt, users whose
        // hashes were already upgraded must not be locked out: with
        // algorithm verification on, the bcrypt hasher throws on an
        // Argon2id hash instead of checking it.
        $this->assertFalse(config('hashing.bcrypt.verify'));

        $argonHash = Hash::make(self::PASS);
        $this->assertStringStartsWith('$argon2id$', $argonHash);
        $this->assertTrue(Hash::driver('bcrypt')->check(self::PASS, $argonHash));
        $this->assertFalse(Hash::driver('bcrypt')->check('bukan-password-ini', $argonHash));
    }

    public function test_customer_rehash_does_not_overwrite_a_password_rotated_mid_login(): void
    {
        $user = User::create([
            'name' => 'Pelanggan Rotasi',
            'email' => 'rotated-customer@example.test',
            'phone' => '081200000012',
            'password' => $this->legacyBcryptHash(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        // Simulate a password rotation that commits after the login
        // callback has loaded and verified this user but before its
        // rehash write: flip the stored hash the moment the model is
        // hydrated for the login attempt.
        $rotatedHash = Hash::make('rotated-password-456');
        $flipped = false;
        User::retrieved(function (User $loaded) use (&$flipped, $user, $rotatedHash) {
            if (! $flipped && $loaded->is($user)) {
                $flipped = true;
                DB::table('users')->where('id', $user->id)->update(['password' => $rotatedHash]);
            }
        });

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASS,
        ])->assertRedirect('/account');

        $this->assertTrue($flipped, 'rotasi harus benar-benar terjadi di tengah login');
        $stored = DB::table('users')->where('id', $user->id)->value('password');
        $this->assertSame($rotatedHash, $stored, 'rehash tidak boleh menimpa password yang sudah dirotasi');
        $this->assertTrue(Hash::check('rotated-password-456', $stored));
        $this->assertFalse(Hash::check(self::PASS, $stored));
    }

    public function test_admin_with_bcrypt_password_logs_in_and_is_rehashed(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Admin Lama',
            'email' => 'legacy-admin@example.test',
            'password' => $this->legacyBcryptHash(),
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => self::PASS,
        ])->assertRedirect(route('admin.panel'));
        $this->assertAuthenticatedAs($admin->fresh(), 'admin');

        $stored = $admin->fresh()->password;
        $this->assertStringStartsWith('$argon2id$', $stored, 'login admin harus menaikkan hash ke Argon2id');
        $this->assertTrue(Hash::check(self::PASS, $stored));
    }

    public function test_password_reset_stores_an_argon2id_password(): void
    {
        $user = User::create([
            'name' => 'Pelanggan Reset',
            'email' => 'reset-customer@example.test',
            'phone' => '081200000013',
            'password' => $this->legacyBcryptHash(),
        ]);

        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'reset-password-baru-789',
            'password_confirmation' => 'reset-password-baru-789',
        ])->assertRedirect(route('login'));

        $stored = $user->fresh()->password;
        $this->assertStringStartsWith('$argon2id$', $stored, 'reset kata sandi harus menyimpan hash Argon2id');
        $this->assertTrue(Hash::check('reset-password-baru-789', $stored));
        $this->assertFalse(Hash::check(self::PASS, $stored));
    }

    public function test_customer_password_change_from_a_legacy_hash_stores_argon2id(): void
    {
        $user = User::create([
            'name' => 'Pelanggan Ganti',
            'email' => 'change-customer@example.test',
            'phone' => '081200000014',
            'password' => $this->legacyBcryptHash(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        // The change endpoint first verifies the CURRENT password
        // (a legacy bcrypt hash through the Argon2id default driver)
        // and then stores the new one.
        $this->actingAs($user)->put('/account/password', [
            'current_password' => self::PASS,
            'password' => 'password-pengganti-345',
            'password_confirmation' => 'password-pengganti-345',
        ])->assertRedirect();

        $stored = $user->fresh()->password;
        $this->assertStringStartsWith('$argon2id$', $stored, 'ganti kata sandi harus menyimpan hash Argon2id');
        $this->assertTrue(Hash::check('password-pengganti-345', $stored));
        $this->assertFalse(Hash::check(self::PASS, $stored));
    }

    public function test_registration_stores_an_argon2id_password(): void
    {
        $this->post('/register', [
            'name' => 'Pendaftar Argon',
            'email' => 'argon-register@example.test',
            'phone' => '081200000011',
            'password' => self::PASS,
            'password_confirmation' => self::PASS,
        ])->assertRedirect(route('login'));

        $user = User::where('email', 'argon-register@example.test')->firstOrFail();
        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertTrue(Hash::check(self::PASS, $user->password));
    }
}
