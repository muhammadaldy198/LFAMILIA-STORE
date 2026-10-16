<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPhoneController
{
    public function edit(): Response
    {
        return Inertia::render('Auth/CompletePhone');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,16}$/'],
        ]);

        $normalized = CreateNewUser::normalizePhone($data['phone']);

        if ($normalized !== null) {
            $variants = CreateNewUser::phoneVariants($data['phone']);

            $conflict = User::query()
                ->where('id', '!=', $request->user()->id)
                ->where(function ($query) use ($variants): void {
                    $query->whereIn('phone', $variants)
                        ->orWhereIn('phone_normalized', $variants);
                })
                ->exists();

            if ($conflict) {
                return back()->withErrors(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
            }
        }

        $request->user()->forceFill([
            'phone' => $data['phone'],
            'phone_normalized' => $normalized,
        ])->save();

        $request->session()->forget([
            'auth.google_phone_onboarding_at',
            'auth.google_phone_onboarding_user_id',
        ]);

        return redirect()->route('account');
    }
}
