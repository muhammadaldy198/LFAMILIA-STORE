<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductNotice;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mengelola pengumuman (notice) yang menempel pada sebuah produk dari
 * panel admin: membuat, mengubah, dan menghapusnya.
 */
class AdminProductNoticeController
{
    public function storeNotice(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $this->noticeData($request);
        $notice = $product->notices()->create($data);
        $audit->record($request, 'catalog.notice.created', 'product_notice', $notice->id, null, $notice->toArray());

        return back();
    }

    public function updateNotice(Request $request, ProductNotice $notice, CatalogAudit $audit): RedirectResponse
    {
        $data = $this->noticeData($request);
        $before = $notice->toArray();
        $notice->update($data);
        $audit->record($request, 'catalog.notice.updated', 'product_notice', $notice->id, $before, $notice->toArray());

        return back();
    }

    public function destroyNotice(Request $request, ProductNotice $notice, CatalogAudit $audit): RedirectResponse
    {
        $before = $notice->toArray();
        $id = $notice->id;
        $notice->delete();
        $audit->record($request, 'catalog.notice.deleted', 'product_notice', $id, $before, null);

        return back();
    }

    private function noticeData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
