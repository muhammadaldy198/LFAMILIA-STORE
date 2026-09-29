<?php

namespace App\Http\Controllers;

use App\Services\GuestOrderAccess;
use App\Services\PaymentPresentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GuestOrderController
{
    public function lookup(): Response
    {
        return Inertia::render('Guest/Lookup');
    }

    public function verify(Request $request, GuestOrderAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:80'],
            'access_code' => ['required', 'string', 'size:64'],
        ]);
        $order = DB::table('orders')->where('order_number', $data['order_number'])
            ->whereNull('user_id')->first(['id', 'order_number']);

        if (! $order || ! $access->matches($order->id, $data['access_code'])) {
            return back()->withErrors(['order_number' => 'Nomor pesanan atau kode akses tidak cocok.']);
        }

        $request->session()->regenerate();
        $request->session()->put('guest_order_id', $order->id);

        return redirect()->route('guest.orders.show', $order->order_number);
    }

    public function show(
        Request $request,
        string $orderNumber,
        PaymentPresentationService $payments,
    ): Response
    {
        $order = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->whereNull('orders.user_id')->where('orders.order_number', $orderNumber)
            ->where('orders.id', $request->session()->get('guest_order_id'))
            ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                'orders.created_at', 'products.name as product_name')->first();

        abort_unless($order, 404);

        return Inertia::render('Guest/OrderStatus', [
            'order' => $order,
            'payment' => $payments->forOrder((int) $order->id),
        ]);
    }
}
