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
