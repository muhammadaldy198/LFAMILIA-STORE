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
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $before = DB::table('orders')->where('id', $id)->firstOrFail();
        $cancellation->cancel($id, (int) $request->user('admin')->id, trim($data['reason']));
        $audit->record($request, 'order.cancelled', 'order', $id,
            ['status' => $before->status],
            ['status' => 'CANCELLED', 'reason' => $data['reason'], 'refund' => 'MANUAL_ONLY']
        );

        return back()->with('status', 'Pesanan dibatalkan. Penyelesaian dana tetap manual.');
    }
}
