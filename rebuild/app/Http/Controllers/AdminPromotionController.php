<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AdminAuditService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPromotionController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'scheduled', 'expired', 'inactive', 'exhausted'])],
            'discount_type' => ['nullable', Rule::in(['FIXED', 'PERCENT'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'popular_q' => ['nullable', 'string', 'max:100'],
            'popular_category' => ['nullable', 'integer', 'exists:categories,id'],
            'popular_status' => ['nullable', Rule::in(['popular', 'not_popular', 'active', 'inactive'])],
            'product_per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $filters = [
            'q' => mb_substr(trim((string) ($filters['q'] ?? '')), 0, 100),
            'status' => (string) ($filters['status'] ?? ''),
            'discount_type' => strtoupper((string) ($filters['discount_type'] ?? '')),
            'per_page' => (int) ($filters['per_page'] ?? 25),
            'popular_q' => mb_substr(trim((string) ($filters['popular_q'] ?? '')), 0, 100),
            'popular_category' => isset($filters['popular_category']) ? (int) $filters['popular_category'] : null,
            'popular_status' => (string) ($filters['popular_status'] ?? ''),
            'product_per_page' => (int) ($filters['product_per_page'] ?? 25),
        ];

        $now = now();
        $redemptions = $this->redemptionCounts($now);

        $vouchers = DB::table('vouchers')
            ->leftJoinSub($redemptions, 'redemptions', 'redemptions.voucher_id', '=', 'vouchers.id')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('vouchers.code', 'like', $like)
                        ->orWhere('vouchers.name', 'like', $like)
                        ->orWhere('vouchers.description', 'like', $like);
                });
            })
            ->when($filters['discount_type'] !== '', fn (Builder $query) => $query->where('vouchers.discount_type', $filters['discount_type']))
            ->when($filters['status'] === 'inactive', fn (Builder $query) => $query->where('vouchers.is_active', false))
            ->when($filters['status'] === 'scheduled', fn (Builder $query) => $query
                ->where('vouchers.is_active', true)
                ->whereNotNull('vouchers.starts_at')
                ->where('vouchers.starts_at', '>', $now))
            ->when($filters['status'] === 'expired', fn (Builder $query) => $query
                ->where('vouchers.is_active', true)
                ->whereNotNull('vouchers.ends_at')
                ->where('vouchers.ends_at', '<', $now))
            ->when($filters['status'] === 'exhausted', fn (Builder $query) => $query
                ->where('vouchers.is_active', true)
                ->whereNotNull('vouchers.total_quota')
                ->whereRaw('COALESCE(redemptions.active_count, 0) >= vouchers.total_quota'))
            ->when($filters['status'] === 'active', fn (Builder $query) => $query
                ->where('vouchers.is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('vouchers.starts_at')->orWhere('vouchers.starts_at', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('vouchers.ends_at')->orWhere('vouchers.ends_at', '>=', $now))
                ->where(fn (Builder $query) => $query->whereNull('vouchers.total_quota')
                    ->orWhereRaw('COALESCE(redemptions.active_count, 0) < vouchers.total_quota')))
            ->orderByDesc('vouchers.id')
            ->select([
                'vouchers.*',
                DB::raw('COALESCE(redemptions.used_count, 0) as used_count'),
                DB::raw('COALESCE(redemptions.reserved_count, 0) as reserved_count'),
                DB::raw('COALESCE(redemptions.active_count, 0) as active_count'),
            ])
            ->paginate($filters['per_page'], ['*'], 'voucher_page')
            ->withQueryString()
            ->through(fn (object $voucher): array => $this->voucherRow($voucher, $now));

        $products = Product::query()
            ->with('category')
            ->withCount(['packages'])
            ->when($filters['popular_q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['popular_q'].'%';
                $query->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->when($filters['popular_category'], fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['popular_status'] === 'popular', fn ($query) => $query->where('popular', true))
            ->when($filters['popular_status'] === 'not_popular', fn ($query) => $query->where('popular', false))
            ->when($filters['popular_status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['popular_status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('popular')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($filters['product_per_page'], ['*'], 'product_page')
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'category_id' => (int) $product->category_id,
                'category_name' => $product->category?->name,
                'packages_count' => (int) $product->packages_count,
                'popular' => (bool) $product->popular,
                'is_active' => (bool) $product->is_active,
                'image_url' => $product->getFirstMediaUrl('image'),
            ]);

        $summaryRows = DB::table('vouchers')
            ->leftJoinSub($this->redemptionCounts($now), 'redemptions', 'redemptions.voucher_id', '=', 'vouchers.id')
            ->get([
                'vouchers.id', 'vouchers.is_active', 'vouchers.starts_at', 'vouchers.ends_at', 'vouchers.total_quota',
                DB::raw('COALESCE(redemptions.used_count, 0) as used_count'),
                DB::raw('COALESCE(redemptions.reserved_count, 0) as reserved_count'),
                DB::raw('COALESCE(redemptions.active_count, 0) as active_count'),
            ]);

        return Inertia::render('Admin/Promotions', [
            'filters' => $filters,
            'vouchers' => $vouchers,
            'popularProducts' => $products,
            'categories' => DB::table('categories')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active']),
            'scopeProducts' => DB::table('products')
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'category_id', 'is_active']),
            'summary' => [
                'total_vouchers' => $summaryRows->count(),
                'active_vouchers' => $summaryRows->filter(fn (object $row): bool => $this->statusFor($row, $now) === 'active')->count(),
                'used_count' => (int) $summaryRows->sum('used_count'),
                'reserved_count' => (int) $summaryRows->sum('reserved_count'),
                'popular_products' => Product::where('popular', true)->count(),
                'active_popular_products' => Product::where('popular', true)->where('is_active', true)->count(),
            ],
        ]);
    }

    public function store(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->voucherData($request);
        [$voucher, $productIds, $categoryIds] = $this->splitVoucherData($data);

        $id = DB::transaction(function () use ($voucher, $productIds, $categoryIds): int {
            $id = DB::table('vouchers')->insertGetId([
                ...$voucher,
                'code' => strtoupper($voucher['code']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->syncScope($id, $productIds, $categoryIds);

            return $id;
        }, 3);

        $audit->record($request, 'voucher.created', 'voucher', $id, null, [
            ...$voucher,
            'product_ids' => $productIds,
            'category_ids' => $categoryIds,
        ]);

        return back()->with('status', 'Voucher berhasil ditambahkan.');
    }

    public function update(Request $request, int $id, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->voucherData($request, $id);
        [$voucher, $productIds, $categoryIds] = $this->splitVoucherData($data);

        [$before, $after] = DB::transaction(function () use ($id, $voucher, $productIds, $categoryIds): array {
            $current = DB::table('vouchers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($current, 404);

            $activeReservations = $this->activeRedemptionCount($id);
            if ($voucher['total_quota'] !== null && (int) $voucher['total_quota'] < $activeReservations) {
                throw ValidationException::withMessages([
                    'total_quota' => 'Total kuota tidak boleh lebih kecil dari pemakaian dan reservasi yang masih aktif.',
                ]);
            }

            $before = [
                ...((array) $current),
                'product_ids' => DB::table('voucher_products')->where('voucher_id', $id)->orderBy('product_id')->pluck('product_id')->all(),
                'category_ids' => DB::table('voucher_categories')->where('voucher_id', $id)->orderBy('category_id')->pluck('category_id')->all(),
            ];

            DB::table('vouchers')->where('id', $id)->update([
                ...$voucher,
                'code' => strtoupper($voucher['code']),
                'updated_at' => now(),
            ]);
            $this->syncScope($id, $productIds, $categoryIds);

            return [$before, [
                ...$voucher,
                'code' => strtoupper($voucher['code']),
                'product_ids' => $productIds,
                'category_ids' => $categoryIds,
            ]];
        }, 3);

        $audit->record($request, 'voucher.updated', 'voucher', $id, $before, $after);

        return back()->with('status', 'Voucher berhasil diperbarui.');
    }

    public function destroy(Request $request, int $id, AdminAuditService $audit): RedirectResponse
    {
        $before = DB::transaction(function () use ($id): array {
            $voucher = DB::table('vouchers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($voucher, 404);

            if (DB::table('orders')->where('voucher_id', $id)->exists()
                || DB::table('voucher_redemptions')->where('voucher_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'voucher' => 'Voucher sudah memiliki riwayat transaksi. Nonaktifkan voucher; riwayat tidak boleh dihapus.',
                ]);
            }

            $before = [
                ...((array) $voucher),
                'product_ids' => DB::table('voucher_products')->where('voucher_id', $id)->pluck('product_id')->all(),
                'category_ids' => DB::table('voucher_categories')->where('voucher_id', $id)->pluck('category_id')->all(),
            ];
            DB::table('vouchers')->where('id', $id)->delete();

            return $before;
        }, 3);

        $audit->record($request, 'voucher.deleted', 'voucher', $id, $before, null);

        return back()->with('status', 'Voucher yang belum pernah dipakai berhasil dihapus.');
    }

    public function updatePopular(Request $request, int $productId, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'popular' => ['required', 'boolean'],
        ]);

        [$before, $after] = DB::transaction(function () use ($productId, $data): array {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            abort_unless($product, 404);

            $before = ['popular' => (bool) $product->popular];
            $after = ['popular' => (bool) $data['popular']];
            DB::table('products')->where('id', $productId)->update([
                'popular' => $after['popular'],
                'updated_at' => now(),
            ]);

            return [$before, $after];
        }, 3);

        $audit->record($request, 'promotion.popular.updated', 'product', $productId, $before, $after);

        return back()->with('status', $after['popular']
            ? 'Produk diprioritaskan di Populer Sekarang.'
            : 'Prioritas Populer Sekarang dilepas.');
    }

    private function voucherData(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['code' => strtoupper($request->input('code', ''))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('vouchers', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:300'],
            'discount_type' => ['required', Rule::in(['FIXED', 'PERCENT'])],
            'discount_value' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'max_discount_idr' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'minimum_total_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'total_quota' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'product_ids' => ['present', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
            'category_ids' => ['present', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($data['discount_type'] === 'PERCENT' && (int) $data['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Diskon persentase maksimal 100%.',
            ]);
        }
        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && Carbon::parse($data['ends_at'])->lessThanOrEqualTo(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages([
                'ends_at' => 'Waktu berakhir harus setelah waktu mulai.',
            ]);
        }

        return $data;
    }

    private function splitVoucherData(array $data): array
    {
        $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $categoryIds = array_values(array_unique(array_map('intval', $data['category_ids'])));
        unset($data['product_ids'], $data['category_ids']);
        $data['name'] = trim($data['name']);
        $data['description'] = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        $data['max_discount_idr'] = $data['max_discount_idr'] ?? null;
        $data['total_quota'] = $data['total_quota'] ?? null;
        $data['per_customer_limit'] = $data['per_customer_limit'] ?? null;
        $data['starts_at'] = filled($data['starts_at'] ?? null) ? $data['starts_at'] : null;
        $data['ends_at'] = filled($data['ends_at'] ?? null) ? $data['ends_at'] : null;

        return [$data, $productIds, $categoryIds];
    }

    private function syncScope(int $voucherId, array $productIds, array $categoryIds): void
    {
        DB::table('voucher_products')->where('voucher_id', $voucherId)->delete();
        DB::table('voucher_categories')->where('voucher_id', $voucherId)->delete();

        foreach ($productIds as $productId) {
            DB::table('voucher_products')->insert([
                'voucher_id' => $voucherId,
                'product_id' => $productId,
            ]);
        }
        foreach ($categoryIds as $categoryId) {
            DB::table('voucher_categories')->insert([
                'voucher_id' => $voucherId,
                'category_id' => $categoryId,
            ]);
        }
    }

    private function redemptionCounts(Carbon $now): Builder
    {
        return DB::table('voucher_redemptions')
            ->select('voucher_id')
            ->selectRaw("SUM(CASE WHEN status = 'REDEEMED' THEN 1 ELSE 0 END) as used_count")
            ->selectRaw("SUM(CASE WHEN status = 'RESERVED' AND reserved_until > ? THEN 1 ELSE 0 END) as reserved_count", [$now])
            ->selectRaw("SUM(CASE WHEN status = 'REDEEMED' OR (status = 'RESERVED' AND reserved_until > ?) THEN 1 ELSE 0 END) as active_count", [$now])
            ->groupBy('voucher_id');
    }

    private function activeRedemptionCount(int $voucherId): int
    {
        return DB::table('voucher_redemptions')
            ->where('voucher_id', $voucherId)
            ->where(function (Builder $query): void {
                $query->where('status', 'REDEEMED')
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', 'RESERVED')->where('reserved_until', '>', now());
                    });
            })->count();
    }

    private function voucherRow(object $voucher, Carbon $now): array
    {
        $productIds = DB::table('voucher_products')->where('voucher_id', $voucher->id)
            ->orderBy('product_id')->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
        $categoryIds = DB::table('voucher_categories')->where('voucher_id', $voucher->id)
            ->orderBy('category_id')->pluck('category_id')->map(fn ($id): int => (int) $id)->all();

        $remaining = $voucher->total_quota === null
            ? null
            : max(0, (int) $voucher->total_quota - (int) $voucher->active_count);

        return [
            'id' => (int) $voucher->id,
            'code' => (string) $voucher->code,
            'name' => filled($voucher->name) ? (string) $voucher->name : (string) $voucher->code,
            'description' => $voucher->description,
            'discount_type' => (string) $voucher->discount_type,
            'discount_value' => (int) $voucher->discount_value,
            'max_discount_idr' => $voucher->max_discount_idr === null ? null : (int) $voucher->max_discount_idr,
            'minimum_total_idr' => (int) $voucher->minimum_total_idr,
            'total_quota' => $voucher->total_quota === null ? null : (int) $voucher->total_quota,
            'per_customer_limit' => $voucher->per_customer_limit === null ? null : (int) $voucher->per_customer_limit,
            'starts_at' => $voucher->starts_at ? Carbon::parse($voucher->starts_at)->format('Y-m-d\\TH:i') : null,
            'ends_at' => $voucher->ends_at ? Carbon::parse($voucher->ends_at)->format('Y-m-d\\TH:i') : null,
            'is_active' => (bool) $voucher->is_active,
            'used_count' => (int) $voucher->used_count,
            'reserved_count' => (int) $voucher->reserved_count,
            'active_count' => (int) $voucher->active_count,
            'remaining_quota' => $remaining,
            'status' => $this->statusFor($voucher, $now),
            'product_ids' => $productIds,
            'category_ids' => $categoryIds,
            'scope_label' => $productIds === [] && $categoryIds === []
                ? 'Semua produk'
                : count($productIds).' produk · '.count($categoryIds).' kategori',
            'created_at' => $voucher->created_at,
            'updated_at' => $voucher->updated_at,
        ];
    }

    private function statusFor(object $voucher, Carbon $now): string
    {
        if (! (bool) $voucher->is_active) {
            return 'inactive';
        }
        if ($voucher->starts_at && Carbon::parse($voucher->starts_at)->gt($now)) {
            return 'scheduled';
        }
        if ($voucher->ends_at && Carbon::parse($voucher->ends_at)->lt($now)) {
            return 'expired';
        }
        if ($voucher->total_quota !== null && (int) $voucher->active_count >= (int) $voucher->total_quota) {
            return 'exhausted';
        }

        return 'active';
    }
}
