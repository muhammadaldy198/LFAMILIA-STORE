<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\SecurityGuard;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderSearchController extends Controller
{
    public function recent(): JsonResponse
    {
        try {
            $rows = DB::table('orders')
                ->orderByDesc('created_at')
                ->limit(30)
                ->get(['reference_id', 'product_name', 'package_label', 'total', 'payment_status', 'fulfillment_status', 'created_at']);

            return response()->json([
                'transactions' => $rows->map(fn ($row) => $this->mapSummary($row, false))->values(),
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return response()->json(['error' => 'Riwayat transaksi belum dapat dimuat.'], 503, [
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    public function search(
        Request $request,
        CustomerAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'order-phone-search', 5, 600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak pencarian transaksi. Coba lagi beberapa menit.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        try {
            $input = $request->validate([
                'phone' => ['required', 'string', 'min:8', 'max:20'],
            ]);

            $variants = PhoneNormalizer::searchVariants($input['phone']);
            if ($variants === []) {
                return response()->json(['error' => 'Format nomor kontak tidak valid.'], 400);
            }

            $reveal = false;
            $session = $auth->current($request);
            if ($session && ($session['phone'] ?? null)) {
                try {
                    $reveal = PhoneNormalizer::whatsapp($input['phone'])
                        === PhoneNormalizer::whatsapp((string) $session['phone']);
                } catch (Throwable) {
                    $reveal = false;
                }
            }

            $rows = DB::table('orders')
                ->whereIn('buyer_phone', $variants)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['reference_id', 'product_name', 'package_label', 'total', 'payment_status', 'fulfillment_status', 'created_at']);

            return response()->json([
                'orders' => $rows->map(fn ($row) => $this->mapSummary($row, $reveal))->values(),
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (ValidationException) {
            return response()->json(['error' => 'Format nomor kontak tidak valid.'], 400);
        } catch (Throwable) {
            return response()->json(['error' => 'Pesanan untuk nomor kontak tersebut belum dapat dimuat.'], 503);
        }
    }

    /** @return array<string,mixed> */
    private function mapSummary(object $row, bool $reveal): array
    {
        $reference = $reveal ? (string) $row->reference_id : null;

        return [
            'referenceId' => $reference,
            'maskedReferenceId' => $reference ?: $this->maskInvoice((string) $row->reference_id),
            'productName' => (string) $row->product_name,
            'packageLabel' => (string) $row->package_label,
            'total' => (int) $row->total,
            'status' => $this->publicStatus((string) $row->payment_status, (string) $row->fulfillment_status),
            'createdAt' => $row->created_at,
        ];
    }

    private function publicStatus(string $payment, string $fulfillment): string
    {
        if (in_array($payment, ['failed', 'expired'], true)) {
            return $payment;
        }
        if ($fulfillment === 'success') {
            return 'success';
        }
        if (in_array($fulfillment, ['failed', 'error', 'needs_review'], true)) {
            return $fulfillment;
        }
        if ($payment === 'paid') {
            return $fulfillment ?: 'processing';
        }

        return $payment ?: 'pending';
    }

    private function maskInvoice(string $value): string
    {
        $value = strtoupper(trim($value));
        $length = strlen($value);
        if ($length <= 8) {
            return substr($value, 0, 2).'***'.substr($value, -2);
        }

        return substr($value, 0, 4).str_repeat('*', min($length - 7, 10)).substr($value, -3);
    }
}
