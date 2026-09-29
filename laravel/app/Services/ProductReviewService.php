<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductReviewService
{
    /** @return array<int,array<string,mixed>> */
    public function listProduct(string $productSlug, int $limit = 100): array
    {
        return $this->query()
            ->where('r.product_slug', $productSlug)
            ->where('r.is_visible', 1)
            ->orderByDesc('r.created_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn ($row) => $this->publicRow($row))
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function listFeatured(int $limit = 6): array
    {
        return $this->query()
            ->where('r.is_visible', 1)
            ->orderByDesc('r.created_at')
            ->limit(max(1, min(12, $limit)))
            ->get()
            ->map(fn ($row) => $this->publicRow($row))
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function listAll(): array
    {
        return $this->query()
            ->orderByDesc('r.created_at')
            ->limit(300)
            ->get()
            ->map(fn ($row) => $this->publicRow($row))
            ->all();
    }

    public function saveCustomer(
        string $customerId,
        string $productSlug,
        int $rating,
        ?string $title,
        string $body,
    ): void {
        if (!DB::table('products')->where('slug', $productSlug)->where('is_active', 1)->exists()) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }

        $customer = DB::table('customer_users')
            ->where('id', $customerId)
            ->where('is_active', 1)
            ->first(['id', 'name']);
        if (!$customer) {
            throw new RuntimeException('Akun pelanggan tidak ditemukan.');
        }

        $existing = DB::table('product_reviews')
            ->where('customer_id', $customerId)
            ->where('product_slug', $productSlug)
            ->first(['id']);

        if ($existing) {
            DB::table('product_reviews')->where('id', $existing->id)->update([
                'rating' => $rating,
                'title' => $title,
                'body' => $body,
                'reviewer_name' => trim((string) $customer->name) ?: 'Pelanggan',
                'is_verified_purchase' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
            ]);

            return;
        }

        $purchase = DB::table('orders as o')
            ->where('o.customer_id', $customerId)
            ->where('o.product_slug', $productSlug)
            ->where('o.payment_status', 'paid')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('product_reviews as r')
                    ->whereColumn('r.order_id', 'o.id');
            })
            ->orderByDesc('o.created_at')
            ->first(['o.id']);

        if (!$purchase) {
            throw new RuntimeException('Ulasan tersedia setelah kamu menyelesaikan pembelian produk ini.');
        }

        DB::table('product_reviews')->insert([
            'customer_id' => $customerId,
            'order_id' => $purchase->id,
            'reviewer_name' => trim((string) $customer->name) ?: 'Pelanggan',
            'product_slug' => $productSlug,
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'is_verified_purchase' => 1,
            'is_visible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function saveGuest(
        string $referenceId,
        string $phone,
        string $productSlug,
        int $rating,
        ?string $title,
        string $body,
    ): void {
        $reference = strtoupper(trim($referenceId));
        $exact = preg_match('/^LF-\d{8}-[A-F0-9]{8,12}$/', $reference)
            || preg_match('/^LF\d{6}(?:[A-F0-9]{12}|[A-F0-9]{14}|[A-F0-9]{32})$/', $reference);
        $legacyAlias = preg_match('/^LF[A-F0-9]{8,12}$/', $reference)
            ? substr($reference, 2)
            : null;

        if (!$exact && !$legacyAlias) {
            throw new RuntimeException('Nomor invoice tidak valid.');
        }

        $orderQuery = DB::table('orders')
            ->where('product_slug', $productSlug)
            ->where('payment_status', 'paid');

        if ($legacyAlias) {
            $orderQuery->whereRaw('UPPER(reference_id) LIKE ?', ['%-'.$legacyAlias])
                ->orderByDesc('created_at');
        } else {
            $orderQuery->whereRaw('UPPER(reference_id) = ?', [$reference]);
        }

        $order = $orderQuery->first([
            'id', 'reference_id', 'product_slug', 'buyer_name', 'buyer_phone',
        ]);

        if (!$order) {
            throw new RuntimeException('Invoice lunas untuk produk ini tidak ditemukan.');
        }

        try {
            $storedPhone = PhoneNormalizer::whatsapp((string) $order->buyer_phone);
            $suppliedPhone = PhoneNormalizer::whatsapp($phone);
        } catch (\InvalidArgumentException $error) {
            throw new RuntimeException($error->getMessage(), 0, $error);
        }

        if (!hash_equals($storedPhone, $suppliedPhone)) {
            throw new RuntimeException('Nomor kontak tidak cocok dengan invoice.');
        }

        if (DB::table('product_reviews')->where('order_id', $order->id)->exists()) {
            throw new RuntimeException('Invoice ini sudah pernah digunakan untuk memberikan ulasan.');
        }

        DB::table('product_reviews')->insert([
            'customer_id' => null,
            'order_id' => $order->id,
            'reviewer_name' => trim((string) $order->buyer_name) ?: 'Pelanggan',
            'product_slug' => $productSlug,
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'is_verified_purchase' => 1,
            'is_visible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function moderate(int $id, bool $visible): void
    {
        $changed = DB::table('product_reviews')->where('id', $id)->update([
            'is_visible' => $visible ? 1 : 0,
            'updated_at' => now(),
        ]);

        if ($changed !== 1 && !DB::table('product_reviews')->where('id', $id)->exists()) {
            throw new RuntimeException('Ulasan tidak ditemukan.');
        }
    }

    private function query()
    {
        return DB::table('product_reviews as r')
            ->leftJoin('customer_users as u', 'u.id', '=', 'r.customer_id')
            ->select([
                'r.id', 'r.product_slug', 'r.rating', 'r.title', 'r.body',
                'r.reviewer_name', 'u.name as customer_account_name',
                'r.is_verified_purchase', 'r.is_visible', 'r.created_at', 'r.updated_at',
            ]);
    }

    /** @return array<string,mixed> */
    private function publicRow(object $row): array
    {
        $name = trim((string) $row->reviewer_name)
            ?: trim((string) ($row->customer_account_name ?? ''))
            ?: 'Pelanggan';

        return [
            'id' => (int) $row->id,
            'productSlug' => (string) $row->product_slug,
            'rating' => (int) $row->rating,
            'title' => $row->title,
            'body' => (string) $row->body,
            'customerName' => $this->maskName($name),
            'isVerifiedPurchase' => (bool) $row->is_verified_purchase,
            'isVisible' => (bool) $row->is_visible,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    private function maskName(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $words = array_values(array_filter($words, fn ($word) => $word !== ''));
        $first = $words[0] ?? 'Pelanggan';
        $masked = mb_strlen($first) <= 2
            ? mb_substr($first, 0, 1).'**'
            : mb_substr($first, 0, min(3, mb_strlen($first))).'***';

        if (count($words) < 2) {
            return $masked;
        }

        return $masked.' '.mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)).'.';
    }
}
