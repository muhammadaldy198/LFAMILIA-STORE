<?php

namespace App\Http\Controllers;

use App\Services\GuestOrderAccess;
use App\Services\PaymentRoutingService;
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
        PaymentRoutingService $routing,
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

        // Wallet = uang milik akun owner: signature saja TIDAK cukup.
        // Tanpa cek ini, pembukaan link signed saat logout / akun lain
        // lolos otorisasi di atas, lalu startOrder() membuat payment
        // CREATING yang langsung REJECTED + 422 ("Saldo hanya tersedia
        // untuk akun customer" / "tidak dapat dibayar dengan saldo")
        // — order tidak bisa dilanjutkan lintas sesi dan pengguna
        // tidak diberi jalan kembali ke login. Kembalikan 401 dengan
        // login_url agar frontend mengarahkan ke login; return
        // destination-nya disetel halaman pembayaran
        // (PaymentPageController menaruh URL halaman ini sebagai
        // intended lewat redirect()->guest()).
        if ($order->user_id !== null
            && $routing->gatewayCodeForRoute((int) $order->payment_route_id) === 'WALLET'
            && (! $user || (int) $user->id !== (int) $order->user_id)) {
            return response()->json([
                'message' => 'Masuk sebagai pemilik pesanan untuk membayar dengan saldo.',
                'login_url' => route('login'),
            ], 401);
        }

        return response()->json($payments->startOrder(
            $order,
            $data['idempotency_key'],
            $user?->id,
        ));
    }
}
