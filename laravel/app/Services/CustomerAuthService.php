<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CustomerAuthService
{
    public const COOKIE = 'lfamilia_session';
    private const SESSION_DAYS = 30;
    private const PASSWORD_ITERATIONS = 100000;

    /** @return array{customer:array<string,mixed>,token:string,expires_at:string} */
    public function register(array $input): array
    {
        $email = $this->normalizeEmail((string) $input['email']);

        if (DB::table('customer_users')->where('email', $email)->exists()) {
            throw new RuntimeException('Email sudah terdaftar. Silakan masuk.');
        }

        $phone = PhoneNormalizer::whatsapp((string) $input['phone']);
        $salt = bin2hex(random_bytes(16));
        $hash = $this->passwordDigest((string) $input['password'], $salt);
        $id = (string) Str::uuid();

        DB::table('customer_users')->insert([
            'id' => $id,
            'email' => $email,
            'name' => trim((string) $input['name']),
            'phone' => $phone,
            'password_hash' => $hash,
            'password_salt' => $salt,
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->createSession($id);
    }

    /** @return array{customer:array<string,mixed>,token:string,expires_at:string} */
    public function login(string $email, string $password): array
    {
        $row = DB::table('customer_users')
            ->where('email', $this->normalizeEmail($email))
            ->first();

        $salt = $row?->password_salt ?: str_repeat('0', 32);
        $actual = $this->passwordDigest($password, $salt);

        if (!$row || !(bool) $row->is_active || !hash_equals((string) $row->password_hash, $actual)) {
            throw new RuntimeException('Email atau password salah.');
        }

        DB::table('customer_users')->where('id', $row->id)->update([
            'last_login_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->createSession((string) $row->id);
    }

    /** @return array{customer:array<string,mixed>,token:string,expires_at:string} */
    public function loginOrRegisterGoogle(array $identity, ?string $phoneInput): array
    {
        $subject = trim((string) ($identity['subject'] ?? ''));
        $email = $this->normalizeEmail((string) ($identity['email'] ?? ''));
        $name = trim((string) ($identity['name'] ?? '')) ?: 'Pelanggan';
        $picture = trim((string) ($identity['picture'] ?? '')) ?: null;

        if ($subject === '' || $email === '') {
            throw new RuntimeException('Credential Google tidak valid.');
        }

        $customerId = DB::transaction(function () use ($subject, $email, $name, $picture, $phoneInput): string {
            $oauth = DB::table('customer_oauth_accounts')
                ->where('provider', 'google')
                ->where('provider_subject', $subject)
                ->lockForUpdate()
                ->first();

            if ($oauth) {
                $customer = DB::table('customer_users')
                    ->where('id', $oauth->customer_id)
                    ->lockForUpdate()
                    ->first();

                if (!$customer || !(bool) $customer->is_active) {
                    throw new RuntimeException('Akun pelanggan sedang dinonaktifkan.');
                }

                $storedPhone = trim((string) $customer->phone);
                $phone = $storedPhone !== ''
                    ? $storedPhone
                    : ($phoneInput ? PhoneNormalizer::whatsapp($phoneInput) : '');

                if ($phone === '') {
                    throw new RuntimeException('PHONE_REQUIRED');
                }

                DB::table('customer_oauth_accounts')->where('id', $oauth->id)->update([
                    'provider_email' => $email,
                    'avatar_url' => $picture,
                    'updated_at' => now(),
                ]);
                DB::table('customer_users')->where('id', $customer->id)->update([
                    'phone' => $phone,
                    'last_login_at' => now(),
                    'updated_at' => now(),
                ]);

                return (string) $customer->id;
            }

            $customer = DB::table('customer_users')
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($customer && !(bool) $customer->is_active) {
                throw new RuntimeException('Akun pelanggan sedang dinonaktifkan.');
            }

            $storedPhone = $customer ? trim((string) $customer->phone) : '';
            $phone = $storedPhone !== ''
                ? $storedPhone
                : ($phoneInput ? PhoneNormalizer::whatsapp($phoneInput) : '');

            if ($phone === '') {
                throw new RuntimeException('PHONE_REQUIRED');
            }

            $customerId = $customer ? (string) $customer->id : (string) Str::uuid();

            if (!$customer) {
                DB::table('customer_users')->insert([
                    'id' => $customerId,
                    'email' => $email,
                    'name' => $name,
                    'phone' => $phone,
                    'password_hash' => bin2hex(random_bytes(32)),
                    'password_salt' => bin2hex(random_bytes(16)),
                    'balance' => 0,
                    'leaderboard_opt_in' => 0,
                    'is_active' => 1,
                    'last_login_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('customer_users')->where('id', $customerId)->update([
                    'phone' => $phone,
                    'last_login_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('customer_oauth_accounts')->insert([
                'id' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'provider' => 'google',
                'provider_subject' => $subject,
                'provider_email' => $email,
                'avatar_url' => $picture,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $customerId;
        }, 3);

        return $this->createSession($customerId);
    }

    /** @return array<string,mixed>|null */
    public function current(Request $request): ?array
    {
        $token = $request->cookie(self::COOKIE);

        if (!$token) {
            $cookieHeader = (string) $request->headers->get('cookie', '');
            foreach (explode(';', $cookieHeader) as $part) {
                [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($name === self::COOKIE && $value !== '') {
                    $token = rawurldecode($value);
                    break;
                }
            }
        }

        $token = is_string($token) ? $token : '';
        if ($token === '') {
            return null;
        }

        $row = DB::table('customer_sessions as s')
            ->join('customer_users as u', 'u.id', '=', 's.customer_id')
            ->where('s.token_hash', hash('sha256', $token))
            ->where('s.expires_at', '>', now())
            ->where('u.is_active', 1)
            ->select([
                'u.id', 'u.email', 'u.name', 'u.phone', 'u.balance', 'u.leaderboard_opt_in',
            ])
            ->first();

        return $row ? $this->publicCustomer($row) : null;
    }

    public function logout(Request $request): void
    {
        $token = (string) $request->cookie(self::COOKIE, '');
        if ($token !== '') {
            DB::table('customer_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    /** @return array{customer:array<string,mixed>,token:string,expires_at:string} */
    private function createSession(string $customerId): array
    {
        DB::table('customer_sessions')->where('expires_at', '<=', now())->delete();

        $sessionIds = DB::table('customer_sessions')
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->pluck('id')
            ->all();

        foreach (array_slice($sessionIds, 4) as $staleId) {
            DB::table('customer_sessions')->where('id', $staleId)->delete();
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = now()->addDays(self::SESSION_DAYS);

        DB::table('customer_sessions')->insert([
            'id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
            'created_at' => now(),
        ]);

        $customer = DB::table('customer_users')
            ->where('id', $customerId)
            ->where('is_active', 1)
            ->first();

        if (!$customer) {
            throw new RuntimeException('Akun pelanggan tidak ditemukan.');
        }

        return [
            'customer' => $this->publicCustomer($customer),
            'token' => $token,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    private function passwordDigest(string $password, string $saltHex): string
    {
        $salt = @hex2bin($saltHex);
        if ($salt === false) {
            $salt = str_repeat("\0", 16);
        }

        return hash_pbkdf2('sha256', $password, $salt, self::PASSWORD_ITERATIONS, 64, false);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** @return array<string,mixed> */
    private function publicCustomer(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'email' => (string) $row->email,
            'name' => (string) $row->name,
            'phone' => (string) $row->phone,
            'balance' => (int) $row->balance,
            'leaderboardOptIn' => (bool) $row->leaderboard_opt_in,
        ];
    }
}
