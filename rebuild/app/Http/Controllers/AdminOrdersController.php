<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileFulfillmentJob;
use App\Services\AdminAuditService;
use App\Services\AdminManualOrderService;
use App\Services\AdminOrderPresentation;
use App\Services\AdminPermissionService;
use App\Services\MidtransStatusVerification;
use App\Services\Payment\MidtransGateway;
use App\Services\PaymentStateService;
use App\Services\TransactionalEmailService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminOrdersController
{
    public function query(Request $request): Builder
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in([...array_keys(AdminOrderPresentation::STATUSES), 'attention'])],
            'provider' => ['nullable', 'string', 'max:40'],
            'payment' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null]),
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
        ]);
        $latestAttempts = DB::table('fulfillment_attempts')
            ->select('order_id', DB::raw('MAX(id) as max_id'))
            ->groupBy('order_id');

        $query = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages as packages', 'packages.id', '=', 'orders.product_package_id')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('provider_mappings as mappings', 'mappings.id', '=', 'orders.provider_mapping_id')
            ->leftJoin('providers', 'providers.id', '=', 'mappings.provider_id')
            ->leftJoin('payment_channels as channels', 'channels.id', '=', 'orders.payment_channel_id')
            ->leftJoinSub($latestAttempts, 'latest_fa', 'latest_fa.order_id', '=', 'orders.id')
            ->leftJoin('fulfillment_attempts as fa', 'fa.id', '=', 'latest_fa.max_id')
            ->select('orders.*', 'products.name as product_name', 'packages.name as package_name',
                'users.name as buyer_name', 'users.email as buyer_email', 'users.phone as buyer_phone',
                'providers.code as provider_code', 'channels.name as channel_name', 'products.manual_instructions')
            ->selectRaw("fa.status IN ('UNKNOWN','BLOCKED','MANUAL_FAILED') as needs_attention");
        if ($q = trim((string) $request->query('q'))) {
            $query->where(function (Builder $query) use ($q): void {
                foreach (['orders.order_number', 'users.name', 'users.email', 'users.phone', 'orders.guest_email',
                    'orders.guest_phone', 'products.name', 'packages.name', 'orders.customer_input', 'orders.snapshot'] as $column) {
                    $query->orWhere($column, 'like', '%'.$q.'%');
                }
            });
        }
        if ($status = $request->query('status')) {
            if ($status === 'attention') {
                $query->whereIn('fa.status', ['UNKNOWN', 'BLOCKED', 'MANUAL_FAILED']);
            } else {
                $query->where('orders.status', $status);
            }
        }
        if ($provider = $request->query('provider')) {
            $query->where(function (Builder $query) use ($provider): void {
                $query->where('providers.code', $provider)->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.snapshot, '$.provider.code')) = ?", [$provider]);
            });
        }
        if ($payment = $request->query('payment')) {
            $query->where(function (Builder $query) use ($payment): void {
                $query->where('channels.code', $payment)->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(orders.snapshot, '$.payment.channel_code')) = ?", [$payment]);
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($date = $request->query($key)) {
                $boundary = Carbon::parse($date, 'Asia/Jakarta');
                $key === 'from' ? $boundary->startOfDay() : $boundary->endOfDay();
                $query->where('orders.created_at', $operator, $boundary->utc());
            }
        }

        return $query;
    }

    public function index(Request $request, AdminOrderPresentation $presentation)
    {
        $query = $this->query($request);
        $counts = (clone $query)->reorder()->select('orders.status')->selectRaw('COUNT(*) as total')->groupBy('orders.status')->pluck('total', 'orders.status');
        $attention = (clone $query)->whereIn('fa.status', ['UNKNOWN', 'BLOCKED', 'MANUAL_FAILED'])->count();
        $rows = $query->orderByDesc('orders.id')->paginate((int) $request->query('per_page', 25))->withQueryString();
        $rows->through(fn (object $row): array => $presentation->row($row));
        $permissions = app(AdminPermissionService::class);
        $canManage = $permissions->allows($request->user('admin'), 'fulfillment.manage');
        $canPay = $permissions->allows($request->user('admin'), 'payments.manage');

        return Inertia::render('Admin/Orders', [
            'orders' => $rows, 'filters' => $request->only(['q', 'status', 'provider', 'payment', 'from', 'to', 'per_page']),
            'metrics' => [
                ['label' => 'Total pesanan', 'value' => (int) $counts->sum()],
                ['label' => 'Menunggu pembayaran', 'value' => (int) ($counts['PENDING_PAYMENT'] ?? 0)],
                ['label' => 'Sedang diproses', 'value' => (int) ($counts['PROCESSING'] ?? 0) + (int) ($counts['PAID'] ?? 0)],
                ['label' => 'Berhasil', 'value' => (int) ($counts['SUCCESS'] ?? 0)],
                ['label' => 'Gagal', 'value' => (int) ($counts['FAILED'] ?? 0)],
                ['label' => 'Perlu perhatian', 'value' => $attention],
            ],
            'statuses' => collect(AdminOrderPresentation::STATUSES)->only(['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED', 'REFUND'])->all(),
            'providers' => DB::table('providers')->orderBy('code')->pluck('code'),
            'channels' => DB::table('payment_channels')->orderBy('sort_order')->get(['code', 'name']),
            'canCreateManual' => $canManage && $canPay, 'updatedAt' => now()->toIso8601String(),
            'activities' => DB::table('order_events as events')->join('orders', 'orders.id', '=', 'events.order_id')
                ->orderByDesc('events.id')->limit(10)->get(['events.id', 'events.event_type', 'events.created_at', 'orders.id as order_id', 'orders.order_number'])
                ->map(fn (object $event): array => [
                    'id' => $event->id, 'order_id' => $event->order_id, 'order_number' => $event->order_number,
                    'label' => AdminOrderPresentation::EVENTS[$event->event_type] ?? 'Status pesanan diperbarui',
                    'created_at' => $presentation->date($event->created_at),
                ]),
        ]);
    }

    public function export(Request $request, AdminOrderPresentation $presentation)
    {
        $request->validate(['selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer', 'min:1']]);
        $query = $this->query($request);
        if ($request->has('selected')) {
            $query->whereIn('orders.id', $request->input('selected', []));
        }

        return response()->streamDownload(function () use ($query, $presentation): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Nomor pesanan', 'Pelanggan', 'Telepon', 'Email', 'Produk', 'Paket', 'Tujuan', 'Pembayaran', 'Penyedia', 'Total (Rp)', 'Status', 'Dibuat (WIB)'], ',', '"', '');
            $query->orderBy('orders.id')->chunk(200, function ($rows) use ($stream, $presentation): void {
                foreach ($rows as $row) {
                    $item = $presentation->row($row);
                    $cells = [$item['order_number'], $item['buyer_name'], $item['buyer_phone'], $item['buyer_email'],
                        $item['product_name'], $item['package_name'], implode(' · ', array_column($item['destinations'], 'value')),
                        $item['payment_method'], $item['provider'], $item['total_idr'], $item['status_label'],
                        Carbon::parse($item['created_at'])->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s')];
                    fputcsv($stream, array_map(fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value, $cells), ',', '"', '');
                }
            });
            fclose($stream);
        }, 'pesanan-'.now('Asia/Jakarta')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function manual(Request $request, AdminManualOrderService $service)
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:5', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'product_name' => ['required', 'string', 'min:2', 'max:120'],
            'package_name' => ['required', 'string', 'min:2', 'max:120'],
            'destination' => ['required', 'string', 'max:300'],
            'total_idr' => ['required', 'integer', 'min:1', 'max:100000000'],
            'payment_received' => ['required', 'accepted'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['payment_received.accepted' => 'Pastikan pembayaran benar-benar sudah diterima.']);
        $id = $service->create($data, (int) $request->user('admin')->id);
        app(AdminAuditService::class)->record($request, 'order.manual.created', 'order', $id, null, ['order_id' => $id]);

        return redirect()->route('admin.orders.show', $id)->with('success', 'Pesanan manual berhasil dicatat.');
    }

    public function resendDelivery(Request $request, int $id, TransactionalEmailService $emails, AdminOrderPresentation $presentation)
    {
        $order = DB::table('orders')->where('id', $id)->first([
            'id', 'order_number', 'status', 'delivery_payload', 'user_id', 'guest_email',
        ]);
        abort_unless($order, 404);

        if ($order->status !== 'SUCCESS') {
            throw ValidationException::withMessages([
                'delivery' => 'Hasil pesanan hanya dapat dikirim ulang setelah pesanan berhasil.',
            ]);
        }

        $delivery = $presentation->json($order->delivery_payload);
        $code = trim((string) ($delivery['code'] ?? $delivery['serial_number'] ?? ''));
        $note = trim((string) ($delivery['note'] ?? ''));
        if ($code === '' && $note === '') {
            throw ValidationException::withMessages([
                'delivery' => 'Pesanan ini belum memiliki kode atau hasil pengiriman yang dapat dikirim ulang.',
            ]);
        }

        $email = $order->user_id
            ? DB::table('users')->where('id', $order->user_id)->value('email')
            : $order->guest_email;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'delivery' => 'Email pelanggan belum tersedia atau tidak valid.',
            ]);
        }

        $text = 'Pesanan '.$order->order_number.' telah berhasil diproses.';
        if ($code !== '') {
            $text .= "\n\nKode / hasil pengiriman:\n".$code;
        }
        if ($note !== '') {
            $text .= "\n\nCatatan:\n".$note;
        }
        $text .= "\n\nSimpan informasi ini dengan aman.";

        $emails->queue(strtolower($email), 'Hasil pesanan '.$order->order_number, $text);
        app(AdminAuditService::class)->record($request, 'order.delivery.resent', 'order', $id, null, [
            'email' => strtolower($email),
            'has_code' => $code !== '',
            'has_note' => $note !== '',
        ]);

        return back()->with('success', 'Hasil pesanan masuk antrean pengiriman email.');
    }

    public function refreshFulfillment(Request $request, int $id)
    {
        $order = DB::table('orders')->where('id', $id)->first();
        abort_unless($order, 404);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $id)->orderByDesc('id')->first();
        if (! in_array($order->status, ['PAID', 'PROCESSING'], true) || ! $attempt || ! in_array($attempt->status, ['PENDING', 'UNKNOWN', 'SENDING'], true)) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini tidak memerlukan pemeriksaan hasil pengiriman.']);
        }
        ReconcileFulfillmentJob::dispatch((int) $attempt->id);
        app(AdminAuditService::class)->record($request, 'order.fulfillment.checked', 'order', $id, null, ['attempt_id' => $attempt->id]);

        return back()->with('success', 'Pemeriksaan dijadwalkan. Muat ulang detail untuk melihat hasilnya.');
    }

    public function refreshPayment(Request $request, int $id)
    {
        $payment = DB::table('payment_transactions')->where('order_id', $id)->orderByDesc('id')->first();
        abort_unless(DB::table('orders')->where('id', $id)->exists(), 404);
        if (! $payment || $payment->gateway_code !== 'MIDTRANS' || ! in_array($payment->status, ['PENDING', 'CREATING', 'SENDING'], true)) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran ini tidak memerlukan pemeriksaan langsung. Pembayaran otomatis diperbarui setelah konfirmasi penyedia diterima.']);
        }
        try {
            $verified = app(MidtransGateway::class)->status((string) $payment->merchant_reference);
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['payment' => 'Penyedia pembayaran belum dapat dihubungi. Status pesanan tidak diubah. Coba lagi nanti.']);
        }
        $verification = app(MidtransStatusVerification::class);
        if (! hash_equals((string) $payment->merchant_reference, (string) $verified['order_id'])
            || $verification->amount($verified['gross_amount']) !== (int) $payment->amount_idr) {
            throw ValidationException::withMessages(['payment' => 'Hasil pemeriksaan tidak sesuai dengan pesanan. Status pembayaran tidak diubah.']);
        }
        $result = app(PaymentStateService::class)->apply((int) $payment->id, $verification->status($verified), [
            'source' => 'admin_status_check', 'admin_id' => $request->user('admin')->id,
        ]);
        app(AdminAuditService::class)->record($request, 'order.payment.checked', 'order', $id, null, $result);

        return back()->with('success', 'Status pembayaran sudah diperiksa.');
    }
}
