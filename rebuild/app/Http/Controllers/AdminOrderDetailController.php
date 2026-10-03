<?php

namespace App\Http\Controllers;

use App\Services\AdminOrderPresentation;
use App\Services\AdminPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminOrderDetailController
{
    public function __invoke(Request $request, int $id, AdminOrdersController $orders, AdminOrderPresentation $presentation): Response
    {
        // Do not apply list filters to a direct detail link.
        $clean = Request::create('/admin/orders');
        $row = $orders->query($clean)->where('orders.id', $id)->first();
        abort_unless($row, 404);
        $permissions = app(AdminPermissionService::class);
        $canFulfill = $permissions->allows($request->user('admin'), 'fulfillment.manage');
        $canPay = $permissions->allows($request->user('admin'), 'payments.manage');
        $attempts = DB::table('fulfillment_attempts as attempts')
            ->leftJoin('providers', 'providers.id', '=', 'attempts.provider_id')
            ->where('attempts.order_id', $id)->orderByDesc('attempts.id')
            ->get(['attempts.id', 'attempts.status', 'attempts.attempt_no', 'attempts.serial_number',
                'attempts.last_error', 'attempts.response_payload', 'attempts.external_reference', 'attempts.provider_rc', 'attempts.created_at', 'attempts.last_checked_at', 'attempts.safe_to_failover', 'providers.code'])
            ->map(fn (object $attempt): array => [
                'id' => $attempt->id, 'attempt_no' => $attempt->attempt_no, 'status' => $attempt->status,
                'status_label' => $presentation->status($attempt->status),
                'provider' => $attempt->code === 'MANUAL' ? 'Penanganan manual' : ($attempt->code ? ucfirst(strtolower($attempt->code)) : 'Belum ditentukan'),
                'serial_number' => $attempt->serial_number,
                'note' => $presentation->note($attempt->last_error ?: data_get($presentation->json($attempt->response_payload), 'message')),
                'reference' => $attempt->external_reference, 'result_code' => $attempt->provider_rc,
                'created_at' => $presentation->date($attempt->created_at), 'checked_at' => $presentation->date($attempt->last_checked_at),
                'can_manual' => $canFulfill && $attempt->status === 'MANUAL_PENDING' && in_array($row->status, ['PAID', 'PROCESSING'], true),
                'can_retry' => $canFulfill && ! in_array($row->status, ['SUCCESS', 'REFUND'], true)
                    && ($attempt->status === 'BLOCKED' || ($attempt->status === 'FAILED_CONFIRMED' && $attempt->safe_to_failover)),
            ]);
        // Only the newest attempt can be acted upon.
        $attempts = $attempts->map(fn (array $attempt, int $index): array => [...$attempt,
            'can_manual' => $index === 0 && $attempt['can_manual'], 'can_retry' => $index === 0 && $attempt['can_retry']]);
        $order = $presentation->row($row);
        $deliveryCode = trim((string) ($order['delivery']['code'] ?? $order['delivery']['serial_number'] ?? ''));
        $deliveryNote = trim((string) ($order['delivery']['note'] ?? ''));
        $canResendDelivery = $canFulfill
            && $row->status === 'SUCCESS'
            && filter_var($order['buyer_email'] ?? null, FILTER_VALIDATE_EMAIL)
            && ($deliveryCode !== '' || $deliveryNote !== '');

        return Inertia::render('Admin/OrderDetail', [
            'order' => $order, 'attempts' => $attempts,
            'canViewCustomer' => $permissions->allows($request->user('admin'), 'customers.view'),
            'canResendDelivery' => $canResendDelivery,
            'canCheckFulfillment' => $canFulfill && in_array($row->status, ['PAID', 'PROCESSING'], true)
                && in_array($attempts->first()['status'] ?? '', ['PENDING', 'UNKNOWN', 'SENDING'], true),
            'payments' => DB::table('payment_transactions')->where('order_id', $id)->orderByDesc('id')
                ->get(['id', 'status', 'amount_idr', 'gateway_code', 'channel_code', 'created_at', 'verified_at'])
                ->map(fn (object $payment): array => [
                    'id' => $payment->id, 'status_label' => $presentation->status($payment->status),
                    'amount_idr' => $payment->amount_idr, 'method' => $payment->gateway_code === 'MANUAL_QRIS' ? 'QRIS manual' : ucfirst(strtolower($payment->gateway_code)),
                    'created_at' => $presentation->date($payment->created_at), 'verified_at' => $presentation->date($payment->verified_at),
                    'can_confirm' => $canPay && $payment->gateway_code === 'MANUAL_QRIS' && in_array($payment->status, ['CREATING', 'SENDING', 'PENDING'], true),
                    'can_check' => $canPay && $payment->gateway_code === 'MIDTRANS' && in_array($payment->status, ['PENDING', 'CREATING'], true),
                ]),
            'events' => DB::table('order_events')->where('order_id', $id)->orderByDesc('id')->get(['id', 'event_type', 'from_status', 'to_status', 'created_at'])
                ->map(fn (object $event): array => [
                    'id' => $event->id, 'label' => AdminOrderPresentation::EVENTS[$event->event_type] ?? 'Status pesanan diperbarui',
                    'from' => $event->from_status ? $presentation->status($event->from_status) : 'Pesanan baru',
                    'to' => $presentation->status($event->to_status), 'created_at' => $presentation->date($event->created_at),
                ]),
        ]);
    }
}
