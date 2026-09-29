<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSessionController extends Controller
{
    public function session(Request $request, AdminAuthService $auth): JsonResponse
    {
        $session = $auth->current($request);
        if (!$session) {
            return response()->json(['error' => 'Sesi panel tidak ditemukan atau sudah berakhir.'], 401, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->json(['session' => $session], 200, ['Cache-Control' => 'no-store']);
    }

    public function loginBackoffice(Request $request, AdminAuthService $auth, SecurityGuard $security)
    {
        return $this->login($request, $auth, $security, 'backoffice', '/admin/panel', '/admin/panel/login');
    }

    public function loginStaff(Request $request, AdminAuthService $auth, SecurityGuard $security)
    {
        return $this->login($request, $auth, $security, 'staff', '/staff/panel', '/staff/panel/login');
    }

    public function logoutBackoffice(Request $request, SecurityGuard $security): RedirectResponse
    {
        $security->assertSameOrigin($request);
        return redirect('/admin/panel/login', 303)->withCookie(Cookie::forget(AdminAuthService::COOKIE, '/'));
    }

    public function logoutStaff(Request $request, SecurityGuard $security): RedirectResponse
    {
        $security->assertSameOrigin($request);
        return redirect('/staff/panel/login', 303)->withCookie(Cookie::forget(AdminAuthService::COOKIE, '/'));
    }

    private function login(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        string $area,
        string $target,
        string $login,
    ) {
        $security->assertSameOrigin($request);
        $scope = $area === 'staff' ? 'staff-login' : 'backoffice-login';
        $rate = $security->rateLimit($request, $scope, 5, 900);

        if (!$rate['allowed']) {
            return redirect($login.'?error='.urlencode('Terlalu banyak percobaan masuk. Coba lagi 15 menit.'), 303);
        }

        try {
            $input = $request->validate([
                'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9._-]+$/'],
                'password' => ['required', 'string', 'min:10', 'max:72'],
            ]);

            $session = $auth->login($input['username'], $input['password'], $area);

            return redirect($target, 303)->withCookie(cookie(
                AdminAuthService::COOKIE,
                $session['token'],
                60 * 12,
                '/',
                null,
                true,
                true,
                false,
                'lax',
            ));
        } catch (ValidationException) {
            $message = $area === 'staff'
                ? 'ID Staff atau password tidak valid.'
                : 'ID Admin atau password tidak valid.';
            return redirect($login.'?error='.urlencode($message), 303);
        } catch (Throwable $error) {
            return redirect($login.'?error='.urlencode($error->getMessage() ?: 'Login panel gagal.'), 303);
        }
    }
}
