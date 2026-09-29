<?php

namespace App\Http\Controllers;

use App\Services\GuestOrderAccess;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController
{
    public function create(
        Request $request,
        string $orderNumber,
        PaymentService $payments,
        GuestOrderAccess $guestAccess,
    ): JsonResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'access_code' => ['nullable', 'string', 'size:64'],
        ]);

        $order = DB::table('orders')->where('order_number', $orderNumber)->firstOrFail();
        $user = $request->user();

        if ($order->user_id !== null) {
            abort_unless($user && (int) $order->user_id === (int) $user->id, 403);
        } else {
            $sessionMatch = (int) $request->session()->get('guest_order_id') === (int) $order->id;
            $tokenMatch = isset($data['access_code'])
                && $guestAccess->matches((int) $order->id, $data['access_code']);
            abort_unless($sessionMatch || $tokenMatch, 403);
        }

        return response()->json($payments->startOrder(
            $order,
            $data['idempotency_key'],
            $user?->id,
        ));
    }
}
