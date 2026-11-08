<?php

namespace App\Http\Controllers;

use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use App\Services\PaymentPageSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mengelola wajah halaman pembayaran dari panel admin: pengaturan
 * pembayaran umum, tampilan halaman bayar, dan gambar header-nya.
 * Channel dan rute diurus controller pembayaran lainnya.
 */
class AdminPaymentPageController
{
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'minimum_topup_idr' => ['required', 'integer', 'min:1', 'max:100000000'],
            'topup_enabled' => ['required', 'boolean'],
        ]);

        $before = [
            'minimum_topup_idr' => $this->minimumTopup(),
            'topup_enabled' => $this->topupEnabled(),
        ];

        DB::transaction(function () use ($request, $data): void {
            foreach ([
                'wallet.minimum_topup_idr' => $data['minimum_topup_idr'],
                'wallet.topup_enabled' => $data['topup_enabled'],
            ] as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    [
                        'value' => json_encode($value),
                        'updated_by_admin_id' => $request->user('admin')->id,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });

        $this->audit($request, 'payment.settings.updated', 'system_setting', 0, $before, $data);

        return back()->with('status', 'Pengaturan top up saldo disimpan.');
    }

    public function pageSettings(
        Request $request,
        PaymentPageSettingsService $settings,
    ): RedirectResponse {
        $data = $request->validate([
            'accentColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'headerImageUrl' => ['nullable', 'string', 'max:500'],
            'eyebrow' => ['required', 'string', 'min:1', 'max:60'],
            'pendingTitle' => ['required', 'string', 'min:2', 'max:100'],
            'paidTitle' => ['required', 'string', 'min:2', 'max:100'],
            'failedTitle' => ['required', 'string', 'min:2', 'max:100'],
            'subtitle' => ['nullable', 'string', 'max:240'],
            'invoiceNoticeTitle' => ['required', 'string', 'min:2', 'max:120'],
            'invoiceNoticeText' => ['nullable', 'string', 'max:500'],
            'pendingStatusText' => ['nullable', 'string', 'max:300'],
            'paidStatusText' => ['nullable', 'string', 'max:300'],
            'failedStatusText' => ['nullable', 'string', 'max:300'],
            'payButtonText' => ['required', 'string', 'min:1', 'max:80'],
            'checkStatusButtonText' => ['required', 'string', 'min:1', 'max:80'],
            'checkInvoiceButtonText' => ['required', 'string', 'min:1', 'max:80'],
            'supportText' => ['nullable', 'string', 'max:120'],
            'supportUrl' => ['nullable', 'string', 'max:500', 'regex:/^(\/(?!\/)|https?:\/\/)/i'],
            'showStoreBrand' => ['required', 'boolean'],
            'showInvoiceNotice' => ['required', 'boolean'],
            'showOrderSummary' => ['required', 'boolean'],
            'showStatusBox' => ['required', 'boolean'],
            'showSupport' => ['required', 'boolean'],
        ]);
        $before = $settings->read();
        $settings->write($data, $request->user('admin')->id);
        $this->audit($request, 'payment.page.updated', 'system_setting', 0, $before, $data);

        return back()->with('status', 'Tampilan halaman pembayaran disimpan.');
    }

    public function uploadPageHeader(
        Request $request,
        PaymentPageSettingsService $settings,
    ): RedirectResponse {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $asset = StoreAsset::where('key', 'payment_header')->first();
        if (! $asset) {
            $id = DB::table('store_assets')->insertGetId([
                'key' => 'payment_header',
                'target_url' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $asset = StoreAsset::findOrFail($id);
        }

        $before = $settings->read();
        $asset->clearMediaCollection('image');
        $asset->addMediaFromRequest('image')
            ->toMediaCollection('image', config('media-library.disk_name', 'public'));
        $asset->forceFill(['is_active' => true])->save();

        $after = [
            ...$before,
            'headerImageUrl' => $asset->getFirstMediaUrl('image'),
        ];
        $settings->write($after, $request->user('admin')->id);
        $this->audit($request, 'payment.page.header.updated', 'store_asset', $asset->id, $before, $after);

        return back()->with('status', 'Banner pembayaran diperbarui.');
    }

    private function minimumTopup(): int
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->value('value');

        return max(1, (int) json_decode((string) $raw, true));
    }

    private function topupEnabled(): bool
    {
        $raw = DB::table('system_settings')->where('key', 'wallet.topup_enabled')->value('value');

        return $raw === null ? true : (bool) json_decode((string) $raw, true);
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
