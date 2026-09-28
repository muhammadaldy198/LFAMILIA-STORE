<?php

namespace App\Http\Controllers;

use App\Exceptions\NicknameServiceException;
use App\Exceptions\NicknameValidationException;
use App\Services\NicknameService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class NicknameController extends Controller
{
    public function verify(Request $request, NicknameService $service, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'nickname-lookup', 30, 600);

        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak pengecekan nickname. Coba lagi beberapa menit.'],
                429,
                ['Cache-Control' => 'no-store', 'Retry-After' => (string) $rate['retry_after']],
            );
        }

        try {
            $input = $request->validate([
                'game' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[a-z0-9-]+$/'],
                'userId' => ['required', 'string', 'min:2', 'max:80'],
                'server' => ['nullable', 'string', 'min:1', 'max:40'],
            ]);

            $result = $service->verifyForCheckout(
                $input['game'],
                $input['userId'],
                $input['server'] ?? null,
            );

            return response()->json($result, 200, ['Cache-Control' => 'no-store']);
        } catch (ValidationException $error) {
            return response()->json([
                'error' => $error->validator->errors()->first() ?: 'Permintaan pengecekan tidak valid.',
            ], 400, ['Cache-Control' => 'no-store']);
        } catch (NicknameValidationException $error) {
            return response()->json(['error' => $error->getMessage()], 404, ['Cache-Control' => 'no-store']);
        } catch (NicknameServiceException $error) {
            return response()->json(['error' => $error->getMessage()], 503, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return response()->json(['error' => 'Layanan verifikasi akun sedang bermasalah.'], 502, [
                'Cache-Control' => 'no-store',
            ]);
        }
    }
}
