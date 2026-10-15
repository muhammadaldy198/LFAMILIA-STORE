<?php

namespace App\Http\Controllers;

use App\Services\PaymentPageSettingsService;
use App\Services\PaymentPresentationService;
use App\Services\PaymentRoutingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PaymentPageController
{
    /**
     * Nama param hasil gateway yang diabaikan saat validasi
     * signature (hasValidSignatureWhileIgnoring): Midtrans
     * menempelkan param ini ke finish redirect-nya SETELAH URL
     * ditandatangani, sehingga signature yang mencakup semua query
     * param jadi tidak valid dan member logout yang kembali dari
     * gateway kena 403. Nama-nama ini dibuktikan dari kode client
     * Midtrans repo — MidtransGateway::status() memvalidasi
     * order_id, status_code, gross_amount, transaction_status pada
     * respons status, dan PaymentWebhookController memakai field
     * yang sama pada payload notifikasi. Param ini TIDAK dipakai
     * untuk otorisasi — otorisasi tetap dari `invoice` (yang
     * ditandatangani) + `expires` — jadi mengabaikannya tidak
     * membuka akses ke order lain, dan expiry tetap ditegakkan
     * (hasValidSignatureWhileIgnoring tetap memeriksa expires).
     * Allowlist EXACT: param lain apa pun (mis. foo=bar) tetap
     * merusak signature → 403.
     *
     * DOKU tidak punya entri: integrasi DOKU repo ini adalah
     * Direct VA (DokuDirectGateway, tanpa halaman hosted /
     * redirect browser) — callback_url_result dikirim ke DOKU
     * tapi tidak pernah memicu browser return dengan param
     * tambahan di alur Direct. Bila alur hosted DOKU ditambah,
     * param return-nya wajib ditambahkan ke sini dengan bukti
     * dari kode client-nya.
     */
    private const GATEWAY_RESULT_PARAMS = ['order_id', 'status_code', 'gross_amount', 'transaction_status'];

    public function show(Request $request, PaymentPresentationService $payments, PaymentPageSettingsService $settings, PaymentRoutingService $routing): Response|RedirectResponse
    {
        $invoice = strtoupper(trim((string) $request->query('invoice', '')));
        abort_if($invoice === '', 404);

        $order = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.order_number', $invoice)
            ->select(
                'orders.id',
                'orders.order_number',
                'orders.user_id',
                'orders.payment_route_id',
                'orders.status',
                'orders.cost_idr',
                'orders.margin_idr',
                'orders.discount_idr',
                'orders.fee_idr',
                'orders.total_idr',
                'orders.created_at',
                'orders.expires_at',
                'products.name as product_name',
                'product_packages.name as package_name',
            )
            ->first();

        abort_unless($order, 404);

        // Link bayar dari email adalah signed URL (lihat
        // TransactionalEmailService::payUrl): signature valid = bukti
        // pengunjung memegang link dari email order itu, jadi halaman
        // boleh dibuka lintas sesi — tanpa session checkout (guest) atau
        // tanpa login dulu (member). Tanpa signature, jalur lama tetap
        // berlaku: session guest_order_id untuk guest, owner login
        // untuk member.
        //
        // Gateway menempelkan param HASIL (Midtrans: order_id,
        // status_code, transaction_status, ...) ke return URL
        // SETELAH URL ditandatangani — validasi signature yang
        // mencakup semua query param akan menolaknya. Abaikan
        // param hasil gateway yang exact (GATEWAY_RESULT_PARAMS)
        // saat validasi; invoice + expires tetap ditandatangani.
        $signed = $request->hasValidSignature()
            || $request->hasValidSignatureWhileIgnoring(self::GATEWAY_RESULT_PARAMS);

        if ($order->user_id === null) {
            if ($signed) {
                $request->session()->put('guest_order_id', $order->id);
            } else {
                abort_unless((int) $request->session()->get('guest_order_id') === (int) $order->id, 403);
            }
            $statusUrl = route('guest.orders.show', $order->order_number);
        } else {
            $isOwner = $request->user() && (int) $request->user()->id === (int) $order->user_id;
            if (! $signed) {
                abort_unless($isOwner, 403);
            }
            // CTA status: pengunjung yang membuka lewat link signed
            // tapi bukan owner yang login (logout, atau login akun
            // lain) TIDAK BISA membuka /account/orders/* (auth:web)
            // — mengarahkannya ke sana hanya melempar ke login.
            // Arahkan ke tujuan status bertanda tangan yang bisa
            // dibuka dengan otorisasi yang sama (signature link
            // email), bukan ke route auth. Owner yang login tetap
            // ke halaman akun. Guest tidak berubah (di cabang atas).
            $statusUrl = ($signed && ! $isOwner)
                ? $this->signedStatusUrl($order)
                : route('account.orders.show', $order->id);
        }

        // Wallet: saldo milik akun owner — signature saja tidak cukup
        // untuk membayar. Halaman wallet yang dibuka lewat link signed
        // saat logout dialihkan ke login dengan intended = URL halaman
        // ini (redirect()->guest), jadi setelah login sebagai owner
        // pengguna kembali ke halaman ini dan tombol "Siapkan
        // pembayaran" bisa jalan. Tanpa ini tombol itu hanya
        // menghasilkan 422 sesudah payment dibuat-lalu-ditolak
        // (lihat PaymentController::create).
        //
        // Bila pengunjung SUDAH login sebagai akun yang salah, sesi
        // akun salah itu diakhiri DULU sebelum redirect ke login:
        // route login Fortify itu guest-only — redirect langsung ke
        // sana membuat middleware guest melempar balik ke /account
        // (Fortify HOME), form login pemilik tidak pernah tampil, dan
        // logout dari /account kemudian menghapus intended (session
        // invalidate), jadi order wallet tidak bisa dilanjutkan.
        // Akhiri sesi dulu, lalu redirect()->guest() menaruh intended
        // di sesi baru dan form login pemilik benar-benar tampil.
        // Pesan status menjelaskan pergantian ini agar logout tidak
        // terasa diam-diam (Login.vue menampilkan page.props.status).
        if ($order->user_id !== null
            && $routing->gatewayCodeForRoute((int) $order->payment_route_id) === 'WALLET'
            && (! $request->user() || (int) $request->user()->id !== (int) $order->user_id)) {
            if ($request->user()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->guest(route('login'))->with(
                    'status',
                    'Sesi akun sebelumnya diakhiri karena link pembayaran ini milik akun lain. Masuk sebagai pemilik pesanan untuk melanjutkan.'
                );
            }

            return redirect()->guest(route('login'));
        }

        return Inertia::render('Payment/Show', [
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'product_name' => $order->product_name,
                'package_name' => $order->package_name,
                'product_total_idr' => max(0, (int) $order->cost_idr + (int) $order->margin_idr - (int) $order->discount_idr),
                'fee_idr' => (int) $order->fee_idr,
                'total_idr' => (int) $order->total_idr,
                'created_at' => $order->created_at,
                'expires_at' => $order->expires_at ? Carbon::parse($order->expires_at)->toIso8601String() : null,
            ],
            'payment' => $payments->forOrder((int) $order->id),
            'statusUrl' => $statusUrl,
            // Bila halaman dibuka lewat link signed, teruskan otorisasi
            // yang sama ke POST pembuatan pembayaran (lihat
            // PaymentController::create): tanpa URL signed ini, tombol
            // "Siapkan pembayaran" mengembalikan 403 untuk member yang
            // belum login, padahal halaman GET-nya sudah terbuka lewat
            // signature. Kedaluwarsa mengikuti aturan yang sama dengan
            // link bayar di email (expires_at order).
            'createPaymentUrl' => $signed ? $this->createPaymentUrl($order) : null,
            'serverNow' => now()->toIso8601String(),
            'pageSettings' => $settings->read(),
        ]);
    }

    private function createPaymentUrl(object $order): string
    {
        $expires = $order->expires_at !== null ? Carbon::parse($order->expires_at) : null;

        return $expires !== null && $expires->isFuture()
            ? URL::temporarySignedRoute('payments.orders.create', $expires, ['orderNumber' => $order->order_number])
            : URL::signedRoute('payments.orders.create', ['orderNumber' => $order->order_number]);
    }

    /**
     * Tujuan status bertanda tangan untuk pengunjung yang membuka
     * lewat link signed tapi bukan owner yang login. Pola expiry
     * mirror createPaymentUrl: sementara selama order masih bisa
     * dibayar (expires_at di masa depan), tanpa expiry sesudahnya
     * — sama seperti link email itu sendiri (pemegang link email
     * sudah diotorisasi melihat info order ini; halaman status
     * tidak membuka apa pun yang tidak dibuka halaman payment).
     */
    private function signedStatusUrl(object $order): string
    {
        $expires = $order->expires_at !== null ? Carbon::parse($order->expires_at) : null;

        return $expires !== null && $expires->isFuture()
            ? URL::temporarySignedRoute('orders.status.signed', $expires, ['orderNumber' => $order->order_number])
            : URL::signedRoute('orders.status.signed', ['orderNumber' => $order->order_number]);
    }
}
