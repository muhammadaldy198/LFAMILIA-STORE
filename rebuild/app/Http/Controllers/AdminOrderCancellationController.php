<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\AdminOrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderCancellationController
{
    public function __invoke(
        Request $request,
        int $id,
        AdminOrderCancellationService $cancellation,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $admin = $request->user('admin');
        // Alasan tidak diminta ke admin; jejak audit tetap ada lewat teks otomatis.
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            $who = $admin ? trim($admin->name.' <'.$admin->email.'>') : '';
            $reason = 'Dibatalkan admin'.($who === '' ? '' : ': '.$who);
        }
        $before = DB::table('orders')->where('id', $id)->firstOrFail();
        $cancellation->cancel($id, (int) $admin->id, $reason);
        $audit->record($request, 'order.cancelled', 'order', $id,
            ['status' => $before->status],
            ['status' => 'CANCELLED', 'reason' => $reason, 'refund' => 'MANUAL_ONLY']
        );

        return back()->with('status', 'Pesanan dibatalkan. Penyelesaian dana tetap manual.');
    }
}
