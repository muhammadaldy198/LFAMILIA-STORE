<?php

namespace App\Http\Controllers;

use App\Services\GuestOrderAccess;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController
{
    public function create(
        Request $request,
        string $orderNumber,
        PaymentService $payments,
        GuestOrderAccess $guestAccess,
    ): JsonResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'access_code' => ['nullable', 'string', 'size:64'],
        ]);

        $order = DB::table('orders')->where('order_number', $orderNumber)->firstOrFail();
        $user = $request->user();

        // Signature valid = pengunjung memegang link bayar signed dari
        // email order ini (lihat PaymentPageController::show, yang
        // meneruskan otorisasi yang sama sebagai createPaymentUrl).
        // Signature ini mengotorisasi POST pembuatan pembayaran untuk
        // order itu saja, sama seperti ia mengotorisasi GET halamannya
        // — tanpa memperluas batas kepercayaan: yang bisa melihat
        // halaman (signed / owner / sesi guest) bisa menyiapkan
        // pembayaran, yang tidak bisa melihat tetap ditolak.
        $signed = $request->hasValidSignature();

        if ($order->user_id !== null) {
            abort_unless($signed || ($user && (int) $order->user_id === (int) $user->id), 403);
        } else {
            $sessionMatch = (int) $request->session()->get('guest_order_id') === (int) $order->id;
            $tokenMatch = isset($data['access_code'])
                && $guestAccess->matches((int) $order->id, $data['access_code']);
            abort_unless($sessionMatch || $tokenMatch || $signed, 403);
        }

        return response()->json($payments->startOrder(
            $order,
            $data['idempotency_key'],
            $user?->id,
        ));
    }
}
