<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Bentuk kanonik nomor telepon dalam format digit internasional (E.164 tanpa +).
     *
     * Nomor Indonesia (0812…, 62812…, 812…) dipetakan ke bentuk kanonik 62812…,
     * sedangkan nomor internasional lain tetap membawa kode negaranya lengkap
     * (contoh +1 5551234567 -> 15551234567, +81 23456789 -> 8123456789).
     * Dengan demikian, namespace telepon antar-negara tidak bertabrakan di database.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]+/', '', $phone) ?: null;
        if ($digits === null) {
            return null;
        }

        // Jika input diawali tanda + eksplisit, nomor sudah membawa kode negara internasional
        // sehingga jangan perlakukan sebagai awalan lokal Indonesia 8...
        if (str_starts_with(trim($phone), '+')) {
            return $digits;
        }

        // Variasi lokal Indonesia (tanpa tanda +):
        // 0812... -> 62812...
        // 812...  -> 62812...
        if (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8') && strlen($digits) >= 8 && strlen($digits) <= 13) {
            $digits = '62'.$digits;
        }

        return $digits;
    }

    public static function phoneVariants(string $phone): array
    {
        $raw = trim($phone);
        $digits = preg_replace('/[^0-9]+/', '', $raw) ?: null;
        if ($digits === null) {
            return [$phone];
        }

        $variants = [$phone, $digits, '+'.$digits];

        // Jika nomor diawali '+' eksplisit internasional:
        // Cek apakah benar nomor internasional Indonesia (+62) atau negara lain (+81, +1, dsb).
        // Jangan pernah meng-expand nomor internasional non-ID ke variasi lokal Indonesia!
        if (str_starts_with($raw, '+')) {
            if (str_starts_with($digits, '62') && strlen($digits) > 2) {
                $local = substr($digits, 2);
                $variants[] = '0'.$local;
                $variants[] = $local;
            }

            return array_values(array_unique($variants));
        }

        // Variasi lokal Indonesia (tanpa tanda '+'):
        // 62812..., 0812..., atau 812...
        if (str_starts_with($digits, '62') && strlen($digits) > 2) {
            $local = substr($digits, 2);
            $variants[] = '0'.$local;
            $variants[] = $local;
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $local = substr($digits, 1);
            $variants[] = '62'.$local;
            $variants[] = $local;
        } elseif (str_starts_with($digits, '8') && strlen($digits) <= 13) {
            $variants[] = '0'.$digits;
            $variants[] = '62'.$digits;
        }

        return array_values(array_unique($variants));
    }

    public static function phoneAlreadyUsed(string $phone, ?int $exceptUserId = null): bool
    {
        $normalized = self::normalizePhone($phone);
        $query = User::query()->where(function ($query) use ($normalized, $phone): void {
            $query->where('phone_normalized', $normalized)
                ->orWhereIn('phone', self::phoneVariants($phone));
        });

        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->exists();
    }

    public static function phoneAlreadyUsedByVariant(string $phone, ?int $exceptUserId = null): bool
    {
        $variants = self::phoneVariants($phone);
        $query = User::query()->where(function ($query) use ($variants): void {
            $query->whereIn('phone', $variants)
                ->orWhereIn('phone_normalized', $variants);
        });
        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        return $query->exists();
    }

    public function create(array $input): User
    {
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'regex:/^\+?[0-9]{10,16}$/'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ])->validate();

        if (self::phoneAlreadyUsed((string) $input['phone'])) {
            throw ValidationException::withMessages(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
        }

        $input['phone_normalized'] = self::normalizePhone((string) $input['phone']);
        try {
            return DB::transaction(fn (): User => User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'phone' => $input['phone'],
                'phone_normalized' => $input['phone_normalized'],
                'password' => Hash::make($input['password']),
                'membership_tier_code' => 'BASIC',
            ]));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $message = $exception->getMessage();
                // Periksa spesifik index/constraint yang gagal, jangan hanya mencocokkan substring
                // dari query INSERT (yang selalu memuat nama kolom `email` dan `phone`).
                if (preg_match('/key [\'`"]?[^\'`"]*users_email_unique/i', $message) || preg_match('/for key [\'`"]?email/i', $message)) {
                    throw ValidationException::withMessages(['email' => 'Email sudah digunakan oleh akun lain.']);
                }

                throw ValidationException::withMessages(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
            }

            throw $exception;
        }
    }
}
