<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\ProductReviewService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminReviewController extends Controller
{
    public function index(
        Request $request,
        AdminAuthService $auth,
        ProductReviewService $reviews,
    ): JsonResponse {
        try {
            $auth->require($request, 'staff');

            return response()->json([
                'reviews' => $reviews->listAll(),
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function update(
        Request $request,
        AdminAuthService $auth,
        ProductReviewService $reviews,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $auth->require($request, 'staff');
            $input = $request->validate([
                'id' => ['required', 'integer', 'min:1'],
                'isVisible' => ['required', 'boolean'],
            ]);

            $reviews->moderate((int) $input['id'], (bool) $input['isVisible']);

            return response()->json(['ok' => true]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'Sesi panel')
                || str_contains($error->getMessage(), 'Akses panel')) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable) {
            return response()->json(['error' => 'Ulasan gagal dimoderasi.'], 400);
        }
    }

    private function accessError(RuntimeException $error): JsonResponse
    {
        return response()->json(
            ['error' => $error->getMessage()],
            str_contains($error->getMessage(), 'Sesi panel') ? 401 : 403,
            ['Cache-Control' => 'no-store'],
        );
    }
}
