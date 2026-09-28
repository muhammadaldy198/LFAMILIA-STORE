<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\ProductReviewService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReviewController extends Controller
{
    public function index(
        Request $request,
        CustomerAuthService $auth,
        ProductReviewService $reviews,
    ): JsonResponse {
        if ($request->query('featured') === '1') {
            return response()->json([
                'reviews' => $reviews->listFeatured(6),
            ], 200, [
                'Cache-Control' => 'public, max-age=60, s-maxage=120, stale-while-revalidate=180',
            ]);
        }

        $slug = trim((string) $request->query('product', ''));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return response()->json(['error' => 'Produk tidak valid.'], 400);
        }

        return response()->json([
            'reviews' => $reviews->listProduct($slug),
            'customer' => $auth->current($request),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function create(
        Request $request,
        CustomerAuthService $auth,
        ProductReviewService $reviews,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-review', 10, 3600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak ulasan dikirim. Coba lagi nanti.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        try {
            $input = $request->validate([
                'productSlug' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:80'],
                'rating' => ['required', 'integer', 'min:1', 'max:5'],
                'title' => ['nullable', 'string', 'max:100'],
                'body' => ['required', 'string', 'min:5', 'max:1200'],
                'referenceId' => ['nullable', 'string', 'max:100'],
                'phone' => ['nullable', 'string', 'max:24'],
            ]);

            $customer = $auth->current($request);
            if ($customer) {
                $reviews->saveCustomer(
                    (string) $customer['id'],
                    $input['productSlug'],
                    (int) $input['rating'],
                    isset($input['title']) ? trim((string) $input['title']) ?: null : null,
                    trim($input['body']),
                );
            } else {
                if (empty($input['referenceId']) || empty($input['phone'])) {
                    return response()->json([
                        'error' => 'Masukkan nomor invoice dan nomor kontak yang digunakan saat checkout untuk memverifikasi pembelian.',
                    ], 400, ['Cache-Control' => 'no-store']);
                }

                $reviews->saveGuest(
                    $input['referenceId'],
                    $input['phone'],
                    $input['productSlug'],
                    (int) $input['rating'],
                    isset($input['title']) ? trim((string) $input['title']) ?: null : null,
                    trim($input['body']),
                );
            }

            return response()->json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Ulasan gagal disimpan.',
            ], 400);
        }
    }
}
