<?php

namespace App\Http\Controllers;

use App\Models\PaymentCategory;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mengelola kategori channel pembayaran dari panel admin: membuat,
 * mengubah, dan menghapus kategori. Channel yang masuk kategori itu
 * diurus AdminPaymentChannelController.
 */
class AdminPaymentCategoryController
{
    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->categoryData($request, true);
        $category = PaymentCategory::create($data);
        $this->audit($request, 'payment.category.created', 'payment_category', $category->code, null, $category->toArray());

        return back()->with('status', 'Kategori pembayaran ditambahkan.');
    }

    public function updateCategory(Request $request, string $code): RedirectResponse
    {
        $category = PaymentCategory::query()->findOrFail($code);
        $data = $this->categoryData($request, false, $code);
        unset($data['code']); // Stable technical grouping identifier.
        $before = $category->toArray();
        $category->update($data);
        $this->audit($request, 'payment.category.updated', 'payment_category', $category->code, $before, $category->toArray());

        return back()->with('status', 'Kategori pembayaran disimpan.');
    }

    public function destroyCategory(Request $request, string $code): RedirectResponse
    {
        $category = PaymentCategory::query()->findOrFail($code);
        if (in_array((string) $category->code, PaymentCategory::SYSTEM_CODES, true) || $category->channels()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori bawaan tidak dapat dihapus karena digunakan proses sinkronisasi. Kategori tambahan yang masih memiliki metode juga tidak dapat dihapus.',
            ]);
        }
        $before = $category->toArray();
        $category->delete();
        $this->audit($request, 'payment.category.deleted', 'payment_category', $category->code, $before, []);

        return back()->with('status', 'Kategori pembayaran dihapus.');
    }

    private function categoryData(Request $request, bool $new, ?string $currentCode = null): array
    {
        return $request->validate([
            'code' => $new
                ? ['required', 'string', 'min:2', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('payment_categories', 'code')]
                : ['sometimes', 'required', Rule::in([$currentCode])],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:160'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function audit(
        Request $request,
        string $action,
        string $type,
        string|int $id,
        ?array $before,
        array $after,
    ): void {
        app(AdminAuditService::class)->record($request, $action, $type, $id, $before, $after);
    }
}
