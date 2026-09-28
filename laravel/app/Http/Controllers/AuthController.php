<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request, CustomerAuthService $auth, SecurityGuard $security, TurnstileService $turnstile): JsonResponse
    {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-login', 8);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak percobaan. Coba lagi beberapa menit.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        try {
            $input = $request->validate([
                'email' => ['required', 'email', 'max:150'],
                'password' => ['required', 'string', 'min:8', 'max:72'],
                'turnstileToken' => ['nullable', 'string', 'max:2048'],
            ]);

            if (!$turnstile->verify($request, $input['turnstileToken'] ?? null)) {
                return response()->json(['error' => 'Verifikasi keamanan gagal. Coba lagi.'], 403);
            }

            $session = $auth->login($input['email'], $input['password']);

            return response()->json(['customer' => $session['customer']])
                ->withCookie(cookie(
                    CustomerAuthService::COOKIE,
                    $session['token'],
                    60 * 24 * 30,
                    '/',
                    null,
                    true,
                    true,
                    false,
                    'lax',
                ));
        } catch (ValidationException) {
            return response()->json(['error' => 'Email atau password tidak valid.'], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Login gagal.'], 400);
        }
    }

    public function register(Request $request, CustomerAuthService $auth, SecurityGuard $security, TurnstileService $turnstile): JsonResponse
    {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-register', 5, 3600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak pendaftaran dari jaringan ini. Coba lagi nanti.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        try {
            $input = $request->validate([
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'email' => ['required', 'email', 'max:150'],
                'phone' => ['required', 'string', 'max:20'],
                'password' => ['required', 'string', 'min:8', 'max:72'],
                'turnstileToken' => ['nullable', 'string', 'max:2048'],
            ]);

            if (!$turnstile->verify($request, $input['turnstileToken'] ?? null)) {
                return response()->json(['error' => 'Verifikasi keamanan gagal. Coba lagi.'], 403);
            }

            $session = $auth->register($input);

            return response()->json(['customer' => $session['customer']], 201)
                ->withCookie(cookie(
                    CustomerAuthService::COOKIE,
                    $session['token'],
                    60 * 24 * 30,
                    '/',
                    null,
                    true,
                    true,
                    false,
                    'lax',
                ));
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Pendaftaran gagal.'], 400);
        }
    }

    public function logout(Request $request, CustomerAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        $auth->logout($request);

        return response()->json(['ok' => true])
            ->withCookie(Cookie::forget(CustomerAuthService::COOKIE, '/'));
    }
}
