<?php

namespace App\Http\Controllers;

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

class CustomerOrderController
{
    public function __construct(private readonly PublicOrderStatusService $statuses) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Customer/Orders', [
            'orders' => DB::table('orders')
                ->join('products', 'products.id', '=', 'orders.product_id')
                ->where('orders.user_id', $request->user()->id)
                ->orderByDesc('orders.id')
                ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                    'orders.created_at', 'products.name as product_name')
                ->paginate(10)
                ->through(function (object $order): object {
                    $order->status = $this->statuses->value((string) $order->status);

                    return $order;
                }),
        ]);
    }

    public function show(
        Request $request,
        int $order,
        PaymentPresentationService $payments,
    ): Response {
        $record = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.user_id', $request->user()->id)->where('orders.id', $order)
            ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                'orders.created_at', 'orders.paid_at', 'orders.expires_at', 'orders.delivery_payload',
                'products.name as product_name', 'product_packages.name as package_name')->first();

        abort_unless($record, 404);

        return $this->detail($record, $payments);
    }

    /**
     * Halaman status order bertanda tangan untuk pemegang link
     * email (PaymentPageController::signedStatusUrl): member yang
     * membuka lewat link signed saat logout tidak bisa membuka
     * /account/orders/* (auth:web), jadi statusnya dibuka lewat
     * sini dengan otorisasi yang sama — signature atas invoice
     * ini + expiry. Tanpa signature → 403; signature dipalsu /
     * invoice ditukar → 403 (signature terikat ke invoice ini).
     * BUKAN pembuka status tanpa otorisasi: pola yang sama dengan
     * akses signed halaman payment.
     *
     * Wallet TIDAK ikut pola ini: saldo milik akun owner —
     * signature saja tidak cukup (sama seperti halaman payment).
     * Order WALLET yang dibuka lewat status signed saat bukan
     * owner dialihkan ke login (dengan intended + logout akun
     * salah dulu, mirror PaymentPageController), jadi destination
     * baru tidak nge-bypass proteksi wallet.
     */
    public function showSigned(
        Request $request,
        string $orderNumber,
        PaymentPresentationService $payments,
        PaymentRoutingService $routing,
    ): Response|RedirectResponse {
        abort_unless($request->hasValidSignature(), 403);

        $record = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.order_number', strtoupper(trim($orderNumber)))
            ->select('orders.id', 'orders.order_number', 'orders.user_id', 'orders.payment_route_id',
                'orders.status', 'orders.total_idr',
                'orders.created_at', 'orders.paid_at', 'orders.expires_at', 'orders.delivery_payload',
                'products.name as product_name', 'product_packages.name as package_name')->first();

        abort_unless($record, 404);

        if ($record->user_id !== null
            && $routing->gatewayCodeForRoute((int) $record->payment_route_id) === 'WALLET'
            && (! $request->user() || (int) $request->user()->id !== (int) $record->user_id)) {
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
                    'Sesi akun sebelumnya diakhiri karena link status ini milik akun lain. Masuk sebagai pemilik pesanan untuk melanjutkan.'
                );
            }

            return redirect()->guest(route('login'));
        }

        // A valid signed status link grants a guest order's detail/payment
        // page access without requiring the original checkout session.
        // Never grant guest session access for member-owned orders.
        if ($record->user_id === null) {
            $originalSession = (int) $request->session()->get('guest_order_id') === (int) $record->id
                && (int) $request->session()->get('payment_signed_guest_order_id') !== (int) $record->id;
            if (! $originalSession) {
                $request->session()->put('guest_order_id', (int) $record->id);
                $request->session()->put('payment_signed_guest_order_id', (int) $record->id);
            }
        }

        $paymentUrl = URL::temporarySignedRoute(
            'payment.show',
            $record->expires_at ? Carbon::parse($record->expires_at) : now()->subSecond(),
            ['invoice' => $record->order_number],
        );

        return $this->detail($record, $payments, $paymentUrl);
    }

    private function detail(object $record, PaymentPresentationService $payments, ?string $paymentUrl = null): Response
    {
        $record->delivery = $record->status === 'SUCCESS' && is_string($record->delivery_payload)
            ? (json_decode($record->delivery_payload, true) ?: null) : null;
        unset($record->delivery_payload);
        $record->status = $this->statuses->value((string) $record->status);

        return Inertia::render('Customer/OrderDetail', [
            'paymentUrl' => $paymentUrl,
            'order' => $record,
            'payment' => $payments->forOrder((int) $record->id),
            'review' => DB::table('product_reviews')->where('order_id', $record->id)->first(['rating', 'body']),
        ]);
    }
}
