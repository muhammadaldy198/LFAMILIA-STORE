<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminAuthService
{
    public const COOKIE = 'lfamilia_panel_session';
    private const CREDENTIAL_PREFIX = '__lfadmin__:';
    private const SESSION_HOURS = 12;
    private const PASSWORD_ITERATIONS = 100000;

    public function normalizeId(string $value): string
    {
        return strtolower(trim($value));
    }

    public function validId(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]{3,32}$/', $this->normalizeId($value));
    }

    /** @return array{admin:array<string,mixed>,token:string,expires_at:string} */
    public function login(string $usernameInput, string $password, ?string $area = null): array
    {
        $username = $this->normalizeId($usernameInput);
        $admin = DB::table('admin_users')
            ->whereRaw('LOWER(email) = ?', [$username])
            ->first(['id', 'email', 'name', 'role', 'is_active']);
        $credential = $admin
            ? DB::table('customer_users')
                ->where('email', self::CREDENTIAL_PREFIX.$this->normalizeId((string) $admin->email))
                ->first(['id', 'password_hash', 'password_salt', 'is_active'])
            : null;

        $row = ($admin && $credential) ? (object) [
            'admin_id' => $admin->id,
            'username' => $admin->email,
            'admin_name' => $admin->name,
            'role' => $admin->role,
            'admin_active' => $admin->is_active,
            'credential_id' => $credential->id,
            'password_hash' => $credential->password_hash,
            'password_salt' => $credential->password_salt,
            'credential_active' => $credential->is_active,
        ] : null;

        $salt = $row?->password_salt ?: str_repeat('0', 32);
        $digest = $this->passwordDigest($password, $salt);

        if (!$row || !(bool) $row->admin_active || !(bool) $row->credential_active
            || !hash_equals((string) $row->password_hash, $digest)) {
            throw new RuntimeException('ID atau password salah.');
        }

        if ($area === 'backoffice' && !in_array($row->role, ['super_admin', 'admin'], true)) {
            throw new RuntimeException('Akun ini bukan akun Super Admin atau Admin.');
        }

        if ($area === 'staff' && $row->role !== 'staff') {
            throw new RuntimeException('Akun ini bukan akun Staff.');
        }

        DB::table('customer_users')->where('id', $row->credential_id)->update([
            'last_login_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->createSession((string) $row->credential_id);

        return [
            'admin' => [
                'id' => (int) $row->admin_id,
                'email' => (string) $row->username,
                'name' => (string) $row->admin_name,
                'role' => (string) $row->role,
            ],
            ...$session,
        ];
    }

    /** @return array<string,mixed>|null */
    public function current(Request $request): ?array
    {
        return $this->fromToken($request->cookie(self::COOKIE));
    }

    /** @return array<string,mixed>|null */
    public function fromToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$payloadEncoded, $signature] = $parts;
        $decoded = $this->base64UrlDecode($payloadEncoded);
        if ($decoded === null) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)
            || empty($payload['u'])
            || !in_array($payload['r'] ?? null, ['super_admin', 'admin', 'staff'], true)
            || !is_numeric($payload['e'] ?? null)
            || (int) $payload['e'] <= (int) floor(microtime(true) * 1000)) {
            return null;
        }

        $username = $this->normalizeId((string) $payload['u']);
        $admin = DB::table('admin_users')
            ->whereRaw('LOWER(email) = ?', [$username])
            ->first(['id', 'email', 'name', 'role', 'is_active']);
        $credential = $admin
            ? DB::table('customer_users')
                ->where('email', self::CREDENTIAL_PREFIX.$this->normalizeId((string) $admin->email))
                ->first(['is_active', 'password_hash'])
            : null;
        $row = ($admin && $credential) ? (object) [
            'admin_id' => $admin->id,
            'username' => $admin->email,
            'admin_name' => $admin->name,
            'role' => $admin->role,
            'admin_active' => $admin->is_active,
            'credential_active' => $credential->is_active,
            'password_hash' => $credential->password_hash,
        ] : null;

        if (!$row || !(bool) $row->admin_active || !(bool) $row->credential_active
            || $row->role !== ($payload['r'] ?? null)) {
            return null;
        }

        $expected = hash_hmac('sha256', $payloadEncoded, (string) $row->password_hash);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return [
            'id' => (int) $row->admin_id,
            'email' => (string) $row->username,
            'name' => (string) $row->admin_name,
            'role' => (string) $row->role,
        ];
    }

    /** @return array{token:string,expires_at:string} */
    private function createSession(string $credentialId): array
    {
        $credential = DB::table('customer_users')
            ->where('id', $credentialId)
            ->first(['email', 'is_active', 'password_hash']);
        $username = $credential && str_starts_with((string) $credential->email, self::CREDENTIAL_PREFIX)
            ? substr((string) $credential->email, strlen(self::CREDENTIAL_PREFIX))
            : '';
        $admin = $username !== ''
            ? DB::table('admin_users')->whereRaw('LOWER(email) = ?', [$this->normalizeId($username)])
                ->first(['email', 'role', 'is_active'])
            : null;
        $row = ($admin && $credential) ? (object) [
            'username' => $admin->email,
            'role' => $admin->role,
            'admin_active' => $admin->is_active,
            'credential_active' => $credential->is_active,
            'password_hash' => $credential->password_hash,
        ] : null;

        if (!$row || !(bool) $row->admin_active || !(bool) $row->credential_active) {
            throw new RuntimeException('Akun panel tidak aktif.');
        }

        $expires = now()->addHours(self::SESSION_HOURS);
        $payload = [
            'u' => $this->normalizeId((string) $row->username),
            'r' => (string) $row->role,
            'e' => $expires->getTimestampMs(),
            'n' => rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '='),
        ];

        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encoded, (string) $row->password_hash);

        return [
            'token' => $encoded.'.'.$signature,
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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padded = $normalized.str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
