<?php

namespace App\Http\Controllers;

use App\Models\Product;
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
            'product_slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'order_number' => ['nullable', 'string', 'max:80'],
            'access_code' => ['nullable', 'string', 'size:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string', 'min:5', 'max:1200'],
            'display_name' => ['nullable', 'string', 'max:100'],
        ]);

        $product = Product::where('slug', $data['product_slug'])->where('is_active', true)->firstOrFail();
        $user = $request->user();

        if ($user) {
            $query = DB::table('orders')
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where('status', 'SUCCESS')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('product_reviews')
                        ->whereColumn('product_reviews.order_id', 'orders.id');
                })
                ->orderByDesc('created_at');

            if (filled($data['order_number'] ?? null)) {
                $query->where('order_number', strtoupper(trim((string) $data['order_number'])));
            }

            $order = $query->first();
            if (! $order) {
                throw ValidationException::withMessages([
                    'order_number' => 'Ulasan tersedia setelah kamu menyelesaikan pembelian produk ini.',
                ]);
            }
        } else {
            $orderNumber = strtoupper(trim((string) ($data['order_number'] ?? '')));
            if ($orderNumber === '') {
                throw ValidationException::withMessages([
                    'order_number' => 'Masukkan nomor invoice untuk memverifikasi pembelian.',
                ]);
            }

            $order = DB::table('orders')
                ->where('order_number', $orderNumber)
                ->where('product_id', $product->id)
                ->where('status', 'SUCCESS')
                ->first();

            if (! $order) {
                throw ValidationException::withMessages([
                    'order_number' => 'Invoice berhasil untuk produk ini tidak ditemukan.',
                ]);
            }

            $authorized = (int) $request->session()->get('guest_order_id') === (int) $order->id;

            if (! $authorized && is_string($data['access_code'] ?? null)) {
                $authorized = $guestAccess->matches((int) $order->id, (string) $data['access_code']);
            }

            if (! $authorized && filled($data['phone'] ?? null) && filled($order->guest_phone)) {
                $authorized = hash_equals(
                    $this->normalizePhone((string) $order->guest_phone),
                    $this->normalizePhone((string) $data['phone'])
                );
            }

            if (! $authorized) {
                throw ValidationException::withMessages([
                    'phone' => 'Nomor WhatsApp tidak cocok dengan invoice.',
                ]);
            }

            if (ProductReview::where('order_id', $order->id)->exists()) {
                throw ValidationException::withMessages([
                    'order_number' => 'Invoice ini sudah pernah digunakan untuk memberikan ulasan.',
                ]);
            }
        }

        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            $name = $user?->name ?: 'Pelanggan LFAMILIA';
        }

        $review = ProductReview::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'display_name' => mb_substr($name, 0, 100),
            'rating' => (int) $data['rating'],
            'title' => filled($data['title'] ?? null) ? trim((string) $data['title']) : null,
            'body' => trim($data['body']),
            'is_active' => true,
            'published_at' => now(),
        ]);

        return response()->json([
            'id' => $review->id,
            'message' => 'Ulasan berhasil dikirim.',
        ], 201);
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }
        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }
}
