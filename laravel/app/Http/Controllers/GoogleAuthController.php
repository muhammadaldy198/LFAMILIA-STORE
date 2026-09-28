<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\GoogleIdentityService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GoogleAuthController extends Controller
{
    public function status(GoogleIdentityService $google): JsonResponse
    {
        return response()->json($google->publicStatus(), 200, ['Cache-Control' => 'no-store']);
    }

    public function login(
        Request $request,
        GoogleIdentityService $google,
        CustomerAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-google-identity', 30, 3600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak percobaan login Google. Coba lagi nanti.'],
                429,
                ['Retry-After' => (string) $rate['retry_after'], 'Cache-Control' => 'no-store'],
            );
        }

        try {
            $input = $request->validate([
                'credential' => ['required', 'string', 'min:100', 'max:12000'],
                'phone' => ['nullable', 'string', 'max:20'],
            ]);

            $identity = $google->verify($input['credential']);
            $session = $auth->loginOrRegisterGoogle($identity, $input['phone'] ?? null);

            return response()->json([
                'ok' => true,
                'customer' => $session['customer'],
            ], 200, ['Cache-Control' => 'no-store'])
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
            return response()->json([
                'error' => $error->validator->errors()->first() ?: 'Credential Google tidak valid.',
            ], 400, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'PHONE_REQUIRED') {
                return response()->json([
                    'error' => 'Nomor kontak wajib dilengkapi untuk menggunakan akun LFAMILIA.',
                    'code' => 'PHONE_REQUIRED',
                ], 400, ['Cache-Control' => 'no-store']);
            }

            return response()->json(['error' => $error->getMessage()], 400, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return response()->json(['error' => 'Login Google gagal.'], 400, ['Cache-Control' => 'no-store']);
        }
    }
}
