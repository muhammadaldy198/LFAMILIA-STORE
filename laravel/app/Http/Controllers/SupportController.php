<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupportController extends Controller
{
    public function index(Request $request, CustomerAuthService $auth): JsonResponse
    {
        $customer = $auth->current($request);
        if (!$customer) {
            return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401);
        }

        try {
            $rows = DB::table('customer_support_requests')
                ->where('customer_id', $customer['id'])
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get([
                    'id', 'kind', 'order_reference', 'subject', 'message',
                    'status', 'staff_reply', 'created_at', 'updated_at',
                ]);

            return response()->json(['requests' => $rows], 200, [
                'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Bantuan gagal dimuat.',
            ], 503);
        }
    }

    public function create(
        Request $request,
        CustomerAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'customer-support', 10, 3600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak permintaan bantuan. Coba lagi nanti.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        $customer = $auth->current($request);
        if (!$customer) {
            return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401);
        }

        try {
            $input = $request->validate([
                'kind' => ['required', 'in:support,refund'],
                'orderReference' => ['nullable', 'string', 'max:100'],
                'subject' => ['required', 'string', 'min:4', 'max:140'],
                'message' => ['required', 'string', 'min:10', 'max:2000'],
            ]);

            $reference = trim((string) ($input['orderReference'] ?? '')) ?: null;
            if ($input['kind'] === 'refund' && $reference) {
                $owned = DB::table('orders')
                    ->where('reference_id', $reference)
                    ->where('customer_id', $customer['id'])
                    ->exists();

                if (!$owned) {
                    return response()->json([
                        'error' => 'Pesanan refund tidak ditemukan untuk akun ini.',
                    ], 403);
                }
            }

            $id = (string) Str::uuid();
            DB::table('customer_support_requests')->insert([
                'id' => $id,
                'customer_id' => $customer['id'],
                'kind' => $input['kind'],
                'order_reference' => $reference,
                'subject' => trim($input['subject']),
                'message' => trim($input['message']),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['id' => $id], 201);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Permintaan gagal dikirim.',
            ], 400);
        }
    }
}
