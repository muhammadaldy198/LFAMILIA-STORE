<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PasswordResetService
{
    private const TTL_MINUTES = 15;
    private const PASSWORD_ITERATIONS = 100000;

    public function __construct(private readonly ResendService $resend)
    {
    }

    public function request(string $emailInput): void
    {
        $email = strtolower(trim($emailInput));
        $customer = DB::table('customer_users')
            ->where('email', $email)
            ->first(['id', 'email', 'name', 'is_active']);

        if (!$customer || !(bool) $customer->is_active) {
            return;
        }

        DB::table('customer_password_reset_tokens')
            ->where('customer_id', $customer->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $id = (string) Str::uuid();

        DB::table('customer_password_reset_tokens')->insert([
            'id' => $id,
            'customer_id' => $customer->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);

        $baseUrl = rtrim(trim((string) config('lfamilia.public_base_url')), '/');
        if (!preg_match('#^https://[^/]+#i', $baseUrl)) {
            DB::table('customer_password_reset_tokens')->where('id', $id)->delete();
            throw new RuntimeException('PUBLIC_BASE_URL belum dikonfigurasi.');
        }

        $resetUrl = $baseUrl.'/reset-password?token='.rawurlencode($token);
        $name = htmlspecialchars((string) $customer->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515">'
            .'<h1>LFAMILIA STORE</h1>'
            .'<p>Halo '.$name.',</p>'
            .'<p>Kami menerima permintaan untuk mengganti password akun Anda.</p>'
            .'<p><a href="'.$escapedUrl.'" style="display:inline-block;padding:12px 18px;background:#151515;color:#fff;text-decoration:none;border-radius:8px">Buat password baru</a></p>'
            .'<p>Link ini berlaku selama '.self::TTL_MINUTES.' menit dan hanya dapat digunakan satu kali.</p>'
            .'<p>Jika Anda tidak meminta reset password, abaikan email ini.</p></div>';

        try {
            $this->resend->send(
                (string) $customer->email,
                'Reset password LFAMILIA STORE',
                $html,
                'lfamilia-password-reset-'.$id,
            );
        } catch (Throwable $error) {
            DB::table('customer_password_reset_tokens')->where('id', $id)->delete();
            throw $error;
        }
    }

    public function reset(string $token, string $password): void
    {
        DB::transaction(function () use ($token, $password): void {
            $row = DB::table('customer_password_reset_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$row) {
                throw new RuntimeException('Link reset password tidak valid atau sudah kedaluwarsa.');
            }

            $salt = bin2hex(random_bytes(16));
            $saltBinary = hex2bin($salt);
            $hash = hash_pbkdf2(
                'sha256',
                $password,
                $saltBinary === false ? str_repeat("\0", 16) : $saltBinary,
                self::PASSWORD_ITERATIONS,
                64,
                false,
            );

            DB::table('customer_password_reset_tokens')->where('id', $row->id)->update([
                'used_at' => now(),
            ]);
            DB::table('customer_users')->where('id', $row->customer_id)->update([
                'password_hash' => $hash,
                'password_salt' => $salt,
                'updated_at' => now(),
            ]);
            DB::table('customer_password_reset_tokens')
                ->where('customer_id', $row->customer_id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            DB::table('customer_sessions')->where('customer_id', $row->customer_id)->delete();
        }, 3);
    }
}
