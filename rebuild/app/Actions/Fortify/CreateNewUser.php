<?php

namespace App\Actions\Fortify;

use App\Models\User;
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
     * Normalize an Indonesian phone number to its canonical form (8xx...).
     * Strips leading 0 or 62 so that 081..., 6281..., and 81... all map to the same value.
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

    /**
     * Build all equivalent variant strings for duplicate detection.
     */
    public static function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/[^0-9]+/', '', $phone) ?: null;
        if ($digits === null) {
            return [$phone];
        }

        $variants = [$phone, $digits];

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

    public function create(array $input): User
    {
        $input['email'] = Str::lower(trim((string) ($input['email'] ?? '')));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'regex:/^\+?[0-9]{10,16}$/'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ])->validate();

        // Check for duplicate phone across all equivalent Indonesian formats.
        $variants = self::phoneVariants((string) $input['phone']);
        $conflict = User::query()
            ->where(function ($query) use ($variants): void {
                $query->whereIn('phone', $variants)
                    ->orWhereIn('phone_normalized', $variants);
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
        }

        return DB::transaction(fn (): User => User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'phone_normalized' => self::normalizePhone((string) $input['phone']),
            'password' => Hash::make($input['password']),
            'membership_tier_code' => 'BASIC',
        ]));
    }
}
