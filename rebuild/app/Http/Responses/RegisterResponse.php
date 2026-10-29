<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        Auth::guard(config('fortify.guard'))->logout();
        $request->session()->forget('security.customer_auth_fingerprint');
        $request->session()->regenerate(true);

        $message = 'Pendaftaran berhasil. Periksa email untuk memverifikasi akun sebelum masuk.';

        return $request->wantsJson()
            ? response()->json(['message' => $message], 201)
            : redirect()->route('login')->with('status', $message);
    }
}
