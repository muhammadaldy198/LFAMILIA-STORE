<?php

namespace App\Http\Controllers;

use App\Services\PaymentPresentationService;
use App\Services\PaymentPageSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaymentPageController
{
    public function show(Request $request, PaymentPresentationService $payments, PaymentPageSettingsService $settings): Response
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
                'orders.status',
                'orders.cost_idr',
                'orders.margin_idr',
                'orders.discount_idr',
                'orders.fee_idr',
                'orders.total_idr',
                'orders.created_at',
                'products.name as product_name',
                'product_packages.name as package_name',
            )
            ->first();

        abort_unless($order, 404);

        if ($order->user_id === null) {
            abort_unless((int) $request->session()->get('guest_order_id') === (int) $order->id, 403);
            $statusUrl = route('guest.orders.show', $order->order_number);
        } else {
            abort_unless($request->user() && (int) $request->user()->id === (int) $order->user_id, 403);
            $statusUrl = route('account.orders.show', $order->id);
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
            ],
            'payment' => $payments->forOrder((int) $order->id),
            'statusUrl' => $statusUrl,
            'pageSettings' => $settings->read(),
        ]);
    }
}
