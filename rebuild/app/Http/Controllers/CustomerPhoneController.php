<?php

namespace App\Http\Controllers;

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
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9]{10,16}$/'],
        ]);

        $digits = preg_replace('/[^0-9]+/', '', $data['phone']) ?: null;

        if ($digits !== null) {
            $conflict = User::query()
                ->where('id', '!=', $request->user()->id)
                ->where(function ($query) use ($data, $digits): void {
                    $query->where('phone', $data['phone'])
                        ->orWhere('phone_normalized', $digits);
                })
                ->exists();

            if ($conflict) {
                return back()->withErrors(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
            }
        }

        $request->user()->forceFill([
            'phone' => $data['phone'],
            'phone_normalized' => $digits,
        ])->save();

        $request->session()->forget([
            'auth.google_phone_onboarding_at',
            'auth.google_phone_onboarding_user_id',
        ]);

        return redirect()->route('account');
    }
}
