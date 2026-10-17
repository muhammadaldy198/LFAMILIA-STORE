<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Database\QueryException;
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
        $userId = $request->user()->id;

        if ($normalized !== null && CreateNewUser::phoneAlreadyUsed($data['phone'], $userId)) {
            return back()->withErrors(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
        }

        if (CreateNewUser::phoneAlreadyUsedByVariant($data['phone'], $userId)) {
            return back()->withErrors(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
        }

        try {
            $request->user()->forceFill([
                'phone' => $data['phone'],
                'phone_normalized' => $normalized,
            ])->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $message = $exception->getMessage();
                if (str_contains($message, 'users_email_unique') || str_contains($message, 'email')) {
                    return back()->withErrors(['email' => 'Email sudah digunakan oleh akun lain.']);
                }

                return back()->withErrors(['phone' => 'Nomor WhatsApp sudah digunakan oleh akun lain.']);
            }

            throw $exception;
        }

        $request->session()->forget([
            'auth.google_phone_onboarding_at',
            'auth.google_phone_onboarding_user_id',
        ]);

        return redirect()->route('account');
    }
}
