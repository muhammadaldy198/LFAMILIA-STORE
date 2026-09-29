<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CheckoutInputValidator;
use App\Services\CheckoutService;
use App\Services\NicknameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController
{
    public function nickname(
        Request $request,
        CheckoutInputValidator $validator,
        NicknameService $nickname,
    ): JsonResponse {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'customer_input' => ['required', 'array', 'max:20'],
            'customer_input.*' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::with(['category', 'fields'])
            ->where('id', $data['product_id'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();
        $input = $validator->validate($product, $data['customer_input']);

        return response()->json($nickname->check($product, $input));
    }

    public function quote(Request $request, CheckoutService $checkout): JsonResponse
    {
        $guest = ! $request->user();
        $data = $request->validate([
            'package_id' => ['required', 'integer'],
            'payment_channel_code' => ['required', 'string', 'max:60'],
            'voucher_code' => ['nullable', 'string', 'max:100'],
            'guest_email' => $guest ? ['required', 'email:rfc', 'max:255'] : ['prohibited'],
            'guest_phone' => $guest
                ? ['required', 'string', 'max:32', 'regex:/^[0-9+().\-\s]{6,32}$/']
                : ['prohibited'],
            'total_idr' => ['prohibited'],
            'cost_idr' => ['prohibited'],
            'margin_idr' => ['prohibited'],
            'discount_idr' => ['prohibited'],
            'fee_idr' => ['prohibited'],
            'provider_mapping_id' => ['prohibited'],
            'provider_code' => ['prohibited'],
            'external_sku' => ['prohibited'],
            'buyer_sku_code' => ['prohibited'],
        ]);

        return response()->json($checkout->quote(
            (int) $data['package_id'],
            $data['payment_channel_code'],
            $data['voucher_code'] ?? null,
            $request->user(),
            $data['guest_email'] ?? null,
            $data['guest_phone'] ?? null,
        ));
    }

    public function store(Request $request, CheckoutService $checkout): JsonResponse
    {
        $guest = ! $request->user();
        $data = $request->validate([
            'package_id' => ['required', 'integer'],
            'payment_channel_code' => ['required', 'string', 'max:60'],
            'customer_input' => ['required', 'array', 'max:20'],
            'customer_input.*' => ['nullable', 'string', 'max:255'],
            'voucher_code' => ['nullable', 'string', 'max:100'],
            'guest_email' => $guest ? ['required', 'email:rfc', 'max:255'] : ['prohibited'],
            'guest_phone' => $guest
                ? ['required', 'string', 'max:32', 'regex:/^[0-9+().\-\s]{6,32}$/']
                : ['prohibited'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'total_idr' => ['prohibited'],
            'cost_idr' => ['prohibited'],
            'margin_idr' => ['prohibited'],
            'discount_idr' => ['prohibited'],
            'fee_idr' => ['prohibited'],
            'provider_mapping_id' => ['prohibited'],
            'provider_code' => ['prohibited'],
            'external_sku' => ['prohibited'],
            'buyer_sku_code' => ['prohibited'],
        ]);
        $data['_correlation_id'] = (string) $request->attributes->get('correlation_id');

        $result = $checkout->create($data, $request->user());
        $order = $result['order'];

        if ($order->user_id === null) {
            $request->session()->put('guest_order_id', $order->id);
            $statusUrl = route('guest.orders.show', $order->order_number);
        } else {
            $statusUrl = route('account.orders.show', $order->id);
        }

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total_idr' => (int) $order->total_idr,
            'access_code' => $result['access_code'],
            'status_url' => $statusUrl,
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }
}
