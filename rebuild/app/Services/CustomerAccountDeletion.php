<?php

namespace App\Services;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAccountDeletion
{
    public function delete(User $user, ?DateTimeInterface $inactiveBefore = null): void
    {
        DB::transaction(function () use ($user, $inactiveBefore): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($inactiveBefore && ($user->last_active_at ?? $user->created_at) > $inactiveBefore) {
                return;
            }

            $wallet = DB::table('wallets')->where('user_id', $user->id)->lockForUpdate()->first();

            if (($wallet && ((int) $wallet->balance_idr !== 0
                || DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->exists()))
                || DB::table('wallet_topups')->where('user_id', $user->id)->exists()
                || DB::table('orders')->where('user_id', $user->id)->exists()
                || DB::table('support_tickets')->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'account' => 'Akun memiliki saldo atau riwayat/kewajiban transaksi dan tidak dapat dihapus otomatis.',
                ]);
            }

            $user->tokens()->delete();
            DB::table('saved_game_accounts')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->forceFill([
                'name' => 'Akun dihapus',
                'email' => null,
                'email_verified_at' => null,
                'phone' => null,
                'google_sub' => null,
                'password' => null,
                'remember_token' => null,
            ])->save();
            $user->delete();
        });
    }
}
