<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\TransactionalEmailService;
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

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'remember_token' => Str::random(60),
        ])->save();

        if (is_string($user->email)) {
            app(TransactionalEmailService::class)->queue(
                $user->email,
                'Password LFAMILIA STORE diperbarui',
                'Password akun LFAMILIA STORE Anda baru saja direset.'
            );
        }
    }
}
