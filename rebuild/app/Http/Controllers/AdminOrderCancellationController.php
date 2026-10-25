<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\AdminOrderCancellationService;
use App\Services\AdminChoiceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminOrderCancellationController
{
    public function __invoke(
        Request $request,
        int $id,
        AdminOrderCancellationService $cancellation,
        AdminAuditService $audit,
        AdminChoiceSettings $choices,
    ): RedirectResponse {
        $reasons = collect($choices->active('cancellations'))->pluck('label', 'code')->all();
        $data = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(array_keys($reasons))],
            'reason_note' => ['nullable', 'string', 'max:500', 'required_if:reason_code,lainnya'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $admin = $request->user('admin');
        // Alasan cepat satu ketuk; "lainnya" wajib dijelaskan. Teks inilah yang
        // tercatat di riwayat pesanan dan audit.
        $reason = $data['reason_code'] === 'lainnya'
            ? trim((string) $data['reason_note'])
            : $reasons[$data['reason_code']];
        $before = DB::table('orders')->where('id', $id)->firstOrFail();
        $cancellation->cancel($id, (int) $admin->id, $reason);
        $audit->record($request, 'order.cancelled', 'order', $id,
            ['status' => $before->status],
            ['status' => 'CANCELLED', 'reason' => $reason, 'refund' => 'MANUAL_ONLY']
        );

        return back()->with('status', 'Pesanan dibatalkan. Penyelesaian dana tetap manual.');
    }
}
