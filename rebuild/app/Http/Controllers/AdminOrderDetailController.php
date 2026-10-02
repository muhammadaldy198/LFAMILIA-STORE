<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminOrderDetailController
{
    public function __invoke(int $id): Response
    {
        $order = DB::table('orders')->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.id', $id)->first([
                'orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr', 'orders.created_at',
                'orders.paid_at', 'orders.customer_input', 'orders.guest_email', 'orders.user_id', 'products.name as product_name',
                'product_packages.name as package_name',
            ]);
        abort_unless($order, 404);
        $order->customer_input = json_decode((string) $order->customer_input, true) ?: [];

        return Inertia::render('Admin/OrderDetail', [
            'order' => $order,
            'customer' => $order->user_id ? DB::table('users')->where('id', $order->user_id)->first(['id', 'name', 'email', 'phone']) : null,
            'payments' => DB::table('payment_transactions')->where('order_id', $id)->orderByDesc('id')->get(['id', 'status', 'amount_idr', 'created_at']),
            'attempts' => DB::table('fulfillment_attempts as attempts')->join('providers', 'providers.id', '=', 'attempts.provider_id')
                ->where('attempts.order_id', $id)->orderByDesc('attempts.id')->get(['attempts.id', 'attempts.status', 'attempts.attempt_no', 'attempts.last_error', 'attempts.created_at', 'providers.code']),
            'events' => DB::table('order_events')->where('order_id', $id)->orderByDesc('id')
                ->get(['id', 'event_type', 'from_status', 'to_status', 'created_at']),
        ]);
    }
}
