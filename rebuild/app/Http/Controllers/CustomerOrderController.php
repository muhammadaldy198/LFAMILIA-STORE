<?php

namespace App\Http\Controllers;

use App\Services\PaymentPresentationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerOrderController
{
    public function index(Request $request): Response
    {
        return Inertia::render('Customer/Orders', [
            'orders' => DB::table('orders')
                ->join('products', 'products.id', '=', 'orders.product_id')
                ->where('orders.user_id', $request->user()->id)
                ->orderByDesc('orders.id')
                ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                    'orders.created_at', 'products.name as product_name')
                ->paginate(10),
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
                'orders.created_at', 'orders.paid_at', 'orders.delivery_payload',
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
     */
    public function showSigned(
        Request $request,
        string $orderNumber,
        PaymentPresentationService $payments,
    ): Response {
        abort_unless($request->hasValidSignature(), 403);

        $record = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.order_number', strtoupper(trim($orderNumber)))
            ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                'orders.created_at', 'orders.paid_at', 'orders.delivery_payload',
                'products.name as product_name', 'product_packages.name as package_name')->first();

        abort_unless($record, 404);

        return $this->detail($record, $payments);
    }

    private function detail(object $record, PaymentPresentationService $payments): Response
    {
        $record->delivery = $record->status === 'SUCCESS' && is_string($record->delivery_payload)
            ? (json_decode($record->delivery_payload, true) ?: null) : null;
        unset($record->delivery_payload);

        return Inertia::render('Customer/OrderDetail', [
            'order' => $record,
            'payment' => $payments->forOrder((int) $record->id),
            'review' => DB::table('product_reviews')->where('order_id', $record->id)->first(['rating', 'body']),
        ]);
    }
}
