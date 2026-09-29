<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetService;
use App\Services\SecurityGuard;
use App\Services\TurnstileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetController extends Controller
{
    private const GENERIC_MESSAGE = 'Jika email terdaftar, link reset password akan dikirim ke email tersebut.';

    public function request(
        Request $request,
        PasswordResetService $passwords,
        SecurityGuard $security,
        TurnstileService $turnstile,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-forgot-password', 5, 3600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak permintaan. Coba lagi nanti.'],
                429,
                ['Retry-After' => (string) $rate['retry_after'], 'Cache-Control' => 'no-store'],
            );
        }

        try {
            $input = $request->validate([
                'email' => ['required', 'email', 'max:150'],
                'turnstileToken' => ['nullable', 'string', 'max:2048'],
            ]);

            if (!$turnstile->verify($request, $input['turnstileToken'] ?? null)) {
                return response()->json(['error' => 'Verifikasi keamanan gagal. Coba lagi.'], 403, [
                    'Cache-Control' => 'no-store',
                ]);
            }

            $passwords->request($input['email']);

            return response()->json(['message' => self::GENERIC_MESSAGE], 200, [
                'Cache-Control' => 'no-store',
            ]);
        } catch (ValidationException) {
            return response()->json(['error' => 'Email tidak valid.'], 400, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return response()->json(['message' => self::GENERIC_MESSAGE], 200, [
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    public function reset(
        Request $request,
        PasswordResetService $passwords,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-reset-password', 8, 3600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak percobaan. Coba lagi nanti.'],
                429,
                ['Retry-After' => (string) $rate['retry_after'], 'Cache-Control' => 'no-store'],
            );
        }

        try {
            $input = $request->validate([
                'token' => ['required', 'string', 'min:32', 'max:256'],
                'password' => ['required', 'string', 'min:8', 'max:72'],
            ]);

            $passwords->reset($input['token'], $input['password']);

            return response()->json([
                'message' => 'Password berhasil diperbarui. Silakan masuk kembali.',
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (ValidationException $error) {
            return response()->json([
                'error' => $error->validator->errors()->first() ?: 'Data reset password tidak valid.',
            ], 400, ['Cache-Control' => 'no-store']);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Reset password gagal.',
            ], 400, ['Cache-Control' => 'no-store']);
        }
    }
}
