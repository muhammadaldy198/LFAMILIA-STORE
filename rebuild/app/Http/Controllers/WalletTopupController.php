<?php

namespace App\Http\Controllers;

use App\Services\WalletTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletTopupController
{
    public function quote(Request $request, WalletTopupService $topups): JsonResponse
    {
        $data = $request->validate([
            'amount_idr' => ['required', 'integer', 'min:1', 'max:100000000'],
            'payment_channel_code' => ['required', 'string', 'max:60'],
        ]);

        return response()->json($topups->quote(
            (int) $data['amount_idr'],
            $data['payment_channel_code'],
        ));
    }

    public function store(Request $request, WalletTopupService $topups): JsonResponse
    {
        $data = $request->validate([
            'amount_idr' => ['required', 'integer', 'min:1', 'max:100000000'],
            'payment_channel_code' => ['required', 'string', 'max:60'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ]);

        return response()->json($topups->create(
            $request->user(),
            (int) $data['amount_idr'],
            $data['payment_channel_code'],
            $data['idempotency_key'],
        ), 201);
    }
}
