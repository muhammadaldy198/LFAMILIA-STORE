<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LogoutResponse;

class CustomerLogoutController
{
    public function __invoke(Request $request): LogoutResponse
    {
        // Fortify's default logout invalidates the whole shared browser session,
        // including the independent admin guard. End only the customer login.
        Auth::guard('web')->logout();
        $request->session()->forget('security.customer_auth_fingerprint');

        // Destroy the old session ID and rotate CSRF without flushing admin auth.
        $request->session()->regenerate(true);

        return app(LogoutResponse::class);
    }
}
