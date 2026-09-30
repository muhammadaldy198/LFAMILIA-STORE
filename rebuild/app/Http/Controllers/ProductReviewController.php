<?php

namespace App\Http\Controllers;

use App\Models\ProductReview;
use App\Services\GuestOrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductReviewController
{
    public function store(Request $request, GuestOrderAccess $guestAccess): JsonResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:80'],
            'access_code' => ['nullable', 'string', 'size:64'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            'display_name' => ['nullable', 'string', 'max:100'],
        ]);

        $order = DB::table('orders')->where('order_number', strtoupper($data['order_number']))->first();
        if (! $order || $order->status !== 'SUCCESS') {
            throw ValidationException::withMessages(['order_number' => 'Ulasan hanya untuk pesanan yang sudah berhasil.']);
        }

        $user = $request->user();
        $authorized = $user && (int) $order->user_id === (int) $user->id;
        if (! $authorized && $order->user_id === null
            && (int) $request->session()->get('guest_order_id') === (int) $order->id) {
            $authorized = true;
        }
        if (! $authorized && $order->user_id === null && is_string($data['access_code'] ?? null)) {
            $authorized = $guestAccess->matches((int) $order->id, (string) $data['access_code']);
        }
        if (! $authorized) {
            throw ValidationException::withMessages(['access_code' => 'Akses pesanan tidak cocok.']);
        }
        if (ProductReview::where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['order_number' => 'Pesanan ini sudah pernah memberi ulasan.']);
        }

        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            $name = $user?->name ?: 'Pelanggan LFAMILIA';
        }

        $review = ProductReview::create([
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'user_id' => $user?->id,
            'display_name' => mb_substr($name, 0, 100),
            'rating' => (int) $data['rating'],
            'body' => trim($data['body']),
            'is_active' => true,
            'published_at' => now(),
        ]);

        return response()->json(['id' => $review->id, 'message' => 'Ulasan berhasil dikirim.'], 201);
    }
}
