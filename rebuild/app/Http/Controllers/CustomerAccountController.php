<?php

namespace App\Http\Controllers;

use App\Models\MembershipTier;
use App\Models\Wallet;
use App\Services\CustomerAccountDeletion;
use App\Services\PaymentRoutingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAccountController
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);

        return Inertia::render('Account', [
            'customer' => $user->only('id', 'name', 'email', 'phone', 'membership_tier_code'),
            'balanceIdr' => (int) $wallet->balance_idr,
            'orderCount' => DB::table('orders')->where('user_id', $user->id)->count(),
            'ticketCount' => DB::table('support_tickets')->where('user_id', $user->id)->count(),
        ]);
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('Customer/Profile', [
            'customer' => $request->user()->only('name', 'email', 'phone'),
            'hasPassword' => (bool) $request->user()->password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'regex:/^\+?[0-9]{8,16}$/'],
            'current_password' => ['nullable', 'string'],
        ]);
        $email = $data['email'];

        if ($email !== $user->email && $user->password
            && (! isset($data['current_password'])
                || ! Hash::check($data['current_password'], $user->password))) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }

        $oldEmail = $user->email;
        $user->forceFill([
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'],
            'email_verified_at' => $email === $user->email ? $user->email_verified_at : null,
        ])->save();

        if ($oldEmail !== $email) {
            DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Profil diperbarui.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['nullable', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $user = $request->user();

        if ($user->password && (! isset($data['current_password'])
            || ! Hash::check($data['current_password'], $user->password))) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->session()->regenerate();

        return back()->with('status', 'Kata sandi diperbarui.');
    }

    public function destroy(Request $request, CustomerAccountDeletion $deletion): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'in:HAPUS'],
            'password' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        if ($user->password && (! isset($data['password'])
            || ! Hash::check($data['password'], $user->password))) {
            throw ValidationException::withMessages(['password' => 'Kata sandi salah.']);
        }

        $deletion->delete($user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Akun dihapus.');
    }

    public function wallet(Request $request, PaymentRoutingService $paymentRouting): Response
    {
        $wallet = Wallet::firstOrCreate(['user_id' => $request->user()->id]);
        $minimumRaw = DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->value('value');

        return Inertia::render('Customer/Wallet', [
            'balanceIdr' => (int) $wallet->balance_idr,
            'minimumTopupIdr' => max(1, (int) json_decode((string) $minimumRaw, true)),
            'paymentChannels' => $paymentRouting->publicTopupChannels(),
            'entries' => DB::table('wallet_ledger')->where('wallet_id', $wallet->id)
                ->orderByDesc('id')->select('id', 'amount_idr', 'balance_after_idr', 'source', 'created_at')
                ->paginate(10),
        ]);
    }

    public function membership(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Customer/Membership', [
            'currentCode' => $user->membership_tier_code,
            'tiers' => MembershipTier::query()->where('is_active', true)->orderBy('rank')
                ->get(['code', 'rank', 'requirements', 'benefits']),
        ]);
    }
}
