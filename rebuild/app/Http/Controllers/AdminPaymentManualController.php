<?php

namespace App\Http\Controllers;

use App\Models\StoreAsset;
use App\Services\AdminAuditService;
use App\Services\PaymentStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengelola pembayaran manual dari panel admin: gambar QRIS manual
 * dan saklarnya, konfirmasi pembayaran manual, dan penutupan tinjauan
 * pengembalian dana topup. Class ini tidak memicu pengembalian dana
 * apa pun; penutupan tinjauan hanya menyelesaikan catatan tinjauan.
 */
class AdminPaymentManualController
{
    public function uploadManualQris(Request $request): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $asset = StoreAsset::where('key', 'manual_qris')->first();
        if (! $asset) {
            $id = DB::table('store_assets')->insertGetId([
                'key' => 'manual_qris',
                'target_url' => null,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $asset = StoreAsset::findOrFail($id);
        }

        $before = ['image_url' => $asset->getFirstMediaUrl('image')];
        $asset->clearMediaCollection('image');
        $asset->addMediaFromRequest('image')
            ->toMediaCollection('image', config('media-library.disk_name', 'public'));
        $after = ['image_url' => $asset->getFirstMediaUrl('image')];
        $this->audit($request, 'payment.manual_qris.image.updated', 'store_asset', $asset->id, $before, $after);

        return back()->with('status', 'Gambar QRIS manual diperbarui.');
    }

    public function toggleManualQris(Request $request): RedirectResponse
    {
        // Alasan belum dipecah: satu urutan saklar di dalam transaksi —
        // aset QRIS, channel, gateway, dan rutenya diubah sebagai satu
        // keadaan menyala atau mati; memecahnya berisiko keadaan
        // setengah menyala bocor antar fungsi.
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $asset = StoreAsset::where('key', 'manual_qris')->firstOrFail();
        $enable = (bool) $data['is_active'];
        if ($enable && ! $asset->getFirstMedia('image')) {
            throw ValidationException::withMessages([
                'manual_qris' => 'Unggah gambar QRIS terlebih dahulu.',
            ]);
        }

        $channel = DB::table('payment_channels')->where('code', 'manual_qris')->first();
        $gateway = DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->first();
        $route = ($channel && $gateway) ? DB::table('payment_routes')
            ->where('payment_channel_id', $channel->id)
            ->where('payment_gateway_id', $gateway->id)->first() : null;
        if (! $channel || ! $gateway || ! $route) {
            throw ValidationException::withMessages([
                'manual_qris' => 'Route QRIS Manual belum lengkap. Pulihkan metode bawaan terlebih dahulu.',
            ]);
        }
        if ($enable && $gateway->is_maintenance) {
            throw ValidationException::withMessages([
                'manual_qris' => 'Gateway QRIS Manual sedang dalam pemeliharaan. Matikan mode pemeliharaan terlebih dahulu.',
            ]);
        }

        $before = [
            'asset_active' => (bool) $asset->is_active,
            'channel_active' => (bool) $channel->is_active,
            'gateway_active' => (bool) $gateway->is_active,
            'route_active' => (bool) $route->is_active,
        ];
        DB::transaction(function () use ($asset, $channel, $gateway, $route, $enable): void {
            $asset->forceFill(['is_active' => $enable])->save();
            DB::table('payment_channels')->where('id', $channel->id)->update([
                'is_active' => $enable,
                'supports_order' => true,
                'supports_wallet_topup' => false,
                'updated_at' => now(),
            ]);
            DB::table('payment_gateways')->where('id', $gateway->id)->update([
                'is_active' => $enable, 'updated_at' => now(),
            ]);
            DB::table('payment_routes')->where('id', $route->id)->update([
                'is_active' => $enable,
                'supports_order' => true,
                'supports_wallet_topup' => false,
                'updated_at' => now(),
            ]);
        });
        $this->audit($request, 'payment.manual_qris.updated', 'store_asset', $asset->id, $before, [
            'asset_active' => $enable, 'channel_active' => $enable,
            'gateway_active' => $enable, 'route_active' => $enable,
        ]);

        return back()->with('status', $enable ? 'QRIS Manual siap digunakan untuk pesanan.' : 'QRIS Manual dinonaktifkan.');
    }

    public function confirmManual(Request $request, int $paymentId, PaymentStateService $states): RedirectResponse
    {
        $payment = DB::table('payment_transactions')->where('id', $paymentId)->firstOrFail();
        if ($payment->gateway_code !== 'MANUAL_QRIS') {
            throw ValidationException::withMessages(['payment' => 'Transaksi bukan pembayaran QRIS manual.']);
        }

        $before = (array) $payment;
        $result = $states->apply($paymentId, 'PAID', [
            'source' => 'manual_confirmation',
            'admin_id' => $request->user('admin')->id,
        ]);
        $this->audit($request, 'payment.manual.confirmed', 'payment_transaction', $paymentId, $before, $result);

        return back()->with('status', 'Pembayaran manual dikonfirmasi.');
    }

    public function closeRefundReview(Request $request, int $topupId, PaymentStateService $states): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $before = DB::table('wallet_topups')->where('id', $topupId)->firstOrFail();
        $result = $states->closeTopupRefundReview($topupId, trim((string) $data['reason']));
        $this->audit($request, 'wallet.topup.refund_review_closed', 'wallet_topup', $topupId, (array) $before, $result);

        return back()->with('status', 'Tinjauan top up ditutup. Saldo pelanggan tidak diubah.');
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
