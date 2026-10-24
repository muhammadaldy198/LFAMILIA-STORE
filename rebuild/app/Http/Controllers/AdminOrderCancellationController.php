<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\AdminOrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminOrderCancellationController
{
    /** Alasan pembatalan yang boleh dipilih admin. */
    private const REASONS = [
        'stok_habis' => 'Stok habis',
        'salah_harga' => 'Salah harga',
        'permintaan_pelanggan' => 'Permintaan pelanggan',
        'lainnya' => 'Lainnya',
    ];

    public function __invoke(
        Request $request,
        int $id,
        AdminOrderCancellationService $cancellation,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(array_keys(self::REASONS))],
            'reason_note' => ['nullable', 'string', 'max:500', 'required_if:reason_code,lainnya'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $admin = $request->user('admin');
        // Alasan cepat satu ketuk; "lainnya" wajib dijelaskan. Teks inilah yang
        // tercatat di riwayat pesanan dan audit.
        $reason = $data['reason_code'] === 'lainnya'
            ? trim((string) $data['reason_note'])
            : self::REASONS[$data['reason_code']];
        $before = DB::table('orders')->where('id', $id)->firstOrFail();
        $cancellation->cancel($id, (int) $admin->id, $reason);
        $audit->record($request, 'order.cancelled', 'order', $id,
            ['status' => $before->status],
            ['status' => 'CANCELLED', 'reason' => $reason, 'refund' => 'MANUAL_ONLY']
        );

        return back()->with('status', 'Pesanan dibatalkan. Penyelesaian dana tetap manual.');
    }
}
