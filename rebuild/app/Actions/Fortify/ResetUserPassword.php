<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\StorefrontContentService;
use App\Services\TransactionalEmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'remember_token' => Str::random(60),
            ])->save();

            // Lost devices and Android tokens must not retain access after reset.
            $user->tokens()->delete();
        });

        if (is_string($user->email)) {
            $supportEmail = (string) ((app(StorefrontContentService::class)->settings()['store.email'] ?? '') ?: 'support@lfamilia.com');
            app(TransactionalEmailService::class)->queue(
                $user->email,
                'Kata sandi LFAMILIA STORE berhasil diatur ulang',
                implode("\n", [
                    'Halo,',
                    '',
                    'Kata sandi akun LFAMILIA STORE berhasil diatur ulang.',
                    '',
                    'Jika kamu tidak melakukan ini, segera hubungi CS: '.$supportEmail,
                    '',
                    'Jangan bagikan kata sandi atau kode keamanan kepada siapa pun.',
                ])
            );
        }
    }
}
