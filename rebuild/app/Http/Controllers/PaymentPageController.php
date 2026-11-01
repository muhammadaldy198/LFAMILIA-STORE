<?php

namespace App\Http\Controllers;

use App\Services\PaymentPageSettingsService;
use App\Services\PaymentPresentationService;
use App\Services\PaymentRoutingService;
use App\Services\PublicOrderStatusService;
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
    public function __construct(private readonly PublicOrderStatusService $statuses) {}

    /**
     * Nama param hasil gateway yang diabaikan saat validasi
     * signature (hasValidSignatureWhileIgnoring): Midtrans
     * menempelkan param ini ke finish redirect-nya SETELAH URL
     * ditandatangani, sehingga signature yang mencakup semua query
     * param jadi tidak valid dan member logout yang kembali dari
     * gateway kena 403.
     *
     * SELALU ADA (docs Midtrans, Window Redirection: finish URL
     * ditempeli merchant_id, order_id, status_code,
     * transaction_status; docs Snap lama: order_id, status_code,
     * transaction_status): merchant_id, order_id, status_code,
     * transaction_status.
     *
     * KADANG ADA: gross_amount — tidak ada di daftar resmi finish
     * redirect, tapi muncul pada payload redirect gaya 3DS dan
     * pada respons status / notifikasi Midtrans
     * (MidtransGateway::status() memvalidasi keempat field
     * order_id, status_code, gross_amount, transaction_status;
     * PaymentWebhookController memakai field yang sama). Disimpan
     * sebagai jaring pengaman — tidak pernah dibaca untuk
     * otorisasi.
     *
     * Param ini TIDAK dipakai untuk otorisasi — otorisasi tetap
     * dari `invoice` (yang ditandatangani) + `expires` — jadi
     * mengabaikannya tidak membuka akses ke order lain, dan
     * expiry tetap ditegakkan (hasValidSignatureWhileIgnoring
     * tetap memeriksa expires). Allowlist EXACT: param lain apa
     * pun — mis. foo=bar, atau signature_key (field notifikasi
     * server-to-server, BUKAN finish redirect) — tetap merusak
     * signature → 403 (fail-closed).
     *
     * DOKU tidak punya entri: TIDAK ADA bukti (kode repo maupun
     * docs DOKU yang ditemukan) DOKU menempel query param ke
     * browser return. Jalur hosted DOKU (/checkout/v1/payment)
     * mengirim callback_url_result + auto_redirect, tapi DOKU
     * tidak menspesifikasikan param tambahan pada redirect-nya;
     * kanal notifikasi DOKU adalah JSON server-to-server
     * (webhook), bukan browser return. Bila DOKU terbukti
     * menempel param, tambahkan ke sini dengan bukti — sampai
     * saat itu fail-closed: return DOKU ber-param → 403, user
     * tetap bisa membuka halaman via link email (tanpa param
     * gateway).
     */
    private const GATEWAY_RESULT_PARAMS = ['merchant_id', 'order_id', 'status_code', 'gross_amount', 'transaction_status'];

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

        // Invalid signed links must not fall back to the checkout-session permission.
        if ($request->query->has('signature') || $request->query->has('expires')) {
            abort_unless($signed, 403);
        }

        if ($order->user_id === null) {
            $originalSession = (int) $request->session()->get('guest_order_id') === (int) $order->id
                && (int) $request->session()->get('payment_signed_guest_order_id') !== (int) $order->id;
            if ($signed && ! $originalSession) {
                // Preserve guest status access but do not turn a signed link into
                // permanent invoice-only payment access after its expiry.
                $request->session()->put('guest_order_id', $order->id);
                $request->session()->put('payment_signed_guest_order_id', $order->id);
            } elseif (! $signed) {
                abort_unless($originalSession, 403);
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
                Auth::guard('web')->logout();
                $request->session()->forget([
                    'security.customer_auth_fingerprint',
                    'auth.password_confirmed_at',
                    'auth.google_phone_onboarding_at',
                    'auth.google_phone_onboarding_user_id',
                ]);
                $request->session()->regenerate(true);

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
                'status' => $this->statuses->value((string) $order->status),
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

        return URL::temporarySignedRoute(
            'payments.orders.create',
            $expires ?? now()->subSecond(),
            ['orderNumber' => $order->order_number],
        );
    }

    /**
     * Tujuan status bertanda tangan untuk pengunjung yang membuka
     * lewat link signed tapi bukan owner yang login. Pola expiry
     * mirror createPaymentUrl: sementara selama order masih bisa
     * dibayar (expires_at di masa depan), jendela 30 menit
     * sesudahnya (keputusan user: SEMUA link bertanda tangan
     * WAJIB berexpiry, tanpa fallback tanpa expiry).
     */
    private function signedStatusUrl(object $order): string
    {
        $expires = $order->expires_at !== null ? Carbon::parse($order->expires_at) : null;

        return URL::temporarySignedRoute(
            'orders.status.signed',
            $expires !== null && $expires->isFuture() ? $expires : now()->addMinutes(30),
            ['orderNumber' => $order->order_number],
        );
    }
}
