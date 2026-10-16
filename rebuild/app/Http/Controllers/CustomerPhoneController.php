<?php

namespace App\Http\Controllers;

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
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9]{10,16}$/'],
        ]);

        $digits = preg_replace('/[^0-9]+/', '', $data['phone']) ?: null;

        if ($digits !== null) {
            $variants = [$data['phone'], $digits];
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
            $uniqueVariants = array_values(array_unique($variants));

            $conflict = User::query()
                ->where('id', '!=', $request->user()->id)
                ->where(function ($query) use ($uniqueVariants): void {
                    $query->whereIn('phone', $uniqueVariants)
                        ->orWhereIn('phone_normalized', $uniqueVariants);
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
