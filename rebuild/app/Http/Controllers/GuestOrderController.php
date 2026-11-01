<?php

namespace App\Http\Controllers;

use App\Services\GuestOrderAccess;
use App\Services\PaymentPresentationService;
use App\Services\PublicOrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class GuestOrderController
{
    public function __construct(private readonly PublicOrderStatusService $statuses) {}

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
        $request->session()->forget('payment_signed_guest_order_id');

        return redirect()->route('guest.orders.show', $order->order_number);
    }

    public function show(
        Request $request,
        string $orderNumber,
        PaymentPresentationService $payments,
    ): Response {
        $order = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->whereNull('orders.user_id')->where('orders.order_number', $orderNumber)
            ->where('orders.id', $request->session()->get('guest_order_id'))
            ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                'orders.created_at', 'orders.expires_at', 'orders.delivery_payload', 'products.name as product_name', 'products.slug as product_slug')->first();

        abort_unless($order, 404);
        $order->delivery = $order->status === 'SUCCESS' && is_string($order->delivery_payload)
            ? (json_decode($order->delivery_payload, true) ?: null) : null;
        unset($order->delivery_payload);
        $order->status = $this->statuses->value((string) $order->status);

        $signedOnly = (int) $request->session()->get('payment_signed_guest_order_id') === (int) $order->id;
        $paymentUrl = $signedOnly
            ? URL::temporarySignedRoute('payment.show', $order->expires_at
                ? Carbon::parse($order->expires_at) : now()->subSecond(), ['invoice' => $order->order_number])
            : null;

        return Inertia::render('Guest/OrderStatus', [
            'paymentUrl' => $paymentUrl,
            'order' => $order,
            'payment' => $payments->forOrder((int) $order->id),
            'events' => $this->publicEvents((int) $order->id),
            'review' => DB::table('product_reviews')->where('order_id', $order->id)->first(['rating', 'body']),
        ]);
    }

    public function events(
        Request $request,
        string $orderNumber,
        PaymentPresentationService $payments,
    ): JsonResponse {
        $order = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->whereNull('orders.user_id')->where('orders.order_number', $orderNumber)
            ->where('orders.id', $request->session()->get('guest_order_id'))
            ->select('orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                'orders.created_at', 'orders.delivery_payload', 'products.name as product_name', 'products.slug as product_slug')->first();

        abort_unless($order, 404);
        $delivery = is_string($order->delivery_payload)
            ? (json_decode($order->delivery_payload, true) ?: null) : null;

        return response()->json([
            'order' => [
                'order_number' => $order->order_number,
                'status' => $this->statuses->value((string) $order->status),
                'total_idr' => (int) $order->total_idr,
                'created_at' => $order->created_at,
                'product_name' => $order->product_name,
                'product_slug' => $order->product_slug,
                'delivery' => $order->status === 'SUCCESS' ? $delivery : null,
            ],
            'payment' => $payments->forOrder((int) $order->id),
            'events' => $this->publicEvents((int) $order->id),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function publicEvents(int $orderId): array
    {
        return DB::table('order_events')->where('order_id', $orderId)
            ->orderBy('id')->get(['id', 'event_type', 'from_status', 'to_status', 'created_at'])
            ->map(fn ($event): array => [
                'id' => (int) $event->id,
                'event_type' => $event->event_type,
                'from_status' => $event->from_status ? $this->statuses->value((string) $event->from_status) : null,
                'to_status' => $event->to_status ? $this->statuses->value((string) $event->to_status) : null,
                'created_at' => $event->created_at,
            ])->all();
    }
}
