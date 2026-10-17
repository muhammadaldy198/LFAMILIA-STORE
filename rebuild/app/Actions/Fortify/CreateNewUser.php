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
     * Bentuk kanonik nomor telepon: digit saja.
     *
     * Nomor Indonesia (0812…, 62812…, 812…) dipetakan ke bentuk lokal 8…,
     * sedangkan nomor negara lain dipertahankan lengkap bersama kode
     * negaranya (contoh +1 5551234567 -> 15551234567) supaya pemilik nomor
     * dari semua negara tetap bisa didaftarkan dan tetap unik.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]+/', '', $phone) ?: null;
        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '62') && strlen($digits) > 2) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) > 1) {
            return substr($digits, 1);
        }

        return $digits;
    }

    public static function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/[^0-9]+/', '', $phone) ?: null;
        if ($digits === null) {
            return [$phone];
        }

        $variants = [$phone, $digits, '+'.$digits];
        if (str_starts_with($digits, '62') && strlen($digits) > 2) {
            $local = substr($digits, 2);
            $variants[] = '0'.$local;
            $variants[] = $local;
        } elseif (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $local = substr($digits, 1);
            $variants[] = '62'.$local;
            $variants[] = $local;
        } elseif (str_starts_with($digits, '8')) {
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
                throw ValidationException::withMessages(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
            }

            throw $exception;
        }
    }
}
