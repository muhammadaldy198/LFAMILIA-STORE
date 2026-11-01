<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileFulfillmentJob;
use App\Services\AdminAuditService;
use App\Services\AdminOrderPresentation;
use App\Services\FulfillmentService;
use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use App\Services\Fulfillment\FulfillmentTargetBuilder;
use App\Services\OperationalSettings;
use App\Support\ServiceHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminFulfillmentController
{
    private const STATUSES = [
        'CREATED', 'SENDING', 'PENDING', 'UNKNOWN', 'SUCCESS',
        'FAILED_CONFIRMED', 'BLOCKED', 'MANUAL_PENDING', 'MANUAL_FAILED', 'CANCELLED',
    ];

    public function index(Request $request, AdminOrderPresentation $presentation): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'scope' => ['nullable', Rule::in(['action', 'manual', 'all'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $filters = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'scope' => $filters['scope'] ?? 'action',
            'status' => $filters['status'] ?? '',
            'per_page' => (int) ($filters['per_page'] ?? 25),
        ];

        $latestIds = DB::table('fulfillment_attempts')
            ->selectRaw('MAX(id) AS id')
            ->groupBy('order_id');

        $base = DB::table('fulfillment_attempts as attempts')
            ->joinSub($latestIds, 'latest_attempts', fn ($join) => $join->on('latest_attempts.id', '=', 'attempts.id'))
            ->join('orders', 'orders.id', '=', 'attempts.order_id')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->leftJoin('providers', 'providers.id', '=', 'attempts.provider_id')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($nested) use ($like): void {
                    $nested->where('orders.order_number', 'like', $like)
                        ->orWhere('products.name', 'like', $like)
                        ->orWhere('product_packages.name', 'like', $like)
                        ->orWhere('users.name', 'like', $like)
                        ->orWhere('users.email', 'like', $like)
                        ->orWhere('orders.guest_email', 'like', $like)
                        ->orWhere('orders.guest_phone', 'like', $like)
                        ->orWhereRaw('CAST(orders.customer_input AS CHAR) LIKE ?', [$like]);
                });
            });

        // Metrik antrean tidak menghitung pesanan yang sudah dibatalkan.
        $open = (clone $base)->where('orders.status', '<>', 'CANCELLED');
        $metrics = [
            ['label' => 'Menunggu manual', 'value' => (clone $open)->where('attempts.status', 'MANUAL_PENDING')->count()],
            ['label' => 'Perlu perhatian', 'value' => (clone $open)->whereIn('attempts.status', ['MANUAL_FAILED', 'BLOCKED', 'UNKNOWN'])->count()],
            ['label' => 'Menunggu kepastian', 'value' => (clone $open)->whereIn('attempts.status', ['SENDING', 'PENDING'])->count()],
            ['label' => 'Berhasil', 'value' => (clone $base)->where('attempts.status', 'SUCCESS')->count()],
        ];

        $query = clone $base;
        if ($filters['scope'] === 'action') {
            $query->where('orders.status', '<>', 'CANCELLED')
                ->whereIn('attempts.status', ['MANUAL_PENDING', 'MANUAL_FAILED', 'BLOCKED', 'UNKNOWN', 'PENDING', 'SENDING']);
        } elseif ($filters['scope'] === 'manual') {
            $query->where('providers.code', 'MANUAL');
        }
        if ($filters['status'] !== '') {
            $query->where('attempts.status', $filters['status']);
        }

        $attempts = $query
            ->orderByDesc('attempts.id')
            ->select([
                'attempts.id', 'attempts.order_id', 'attempts.attempt_no', 'attempts.external_reference',
                'attempts.status', 'attempts.provider_status', 'attempts.provider_rc',
                'attempts.serial_number', 'attempts.price_idr', 'attempts.safe_to_failover',
                'attempts.last_error', 'attempts.last_checked_at', 'attempts.created_at', 'attempts.completed_at',
                'orders.order_number', 'orders.status as order_status', 'orders.paid_at as order_paid_at', 'orders.customer_input',
                'orders.snapshot', 'orders.delivery_payload', 'orders.guest_email', 'orders.guest_phone',
                'orders.user_id', 'products.id as product_id', 'products.slug as product_slug', 'products.name as product_name',
                'products.manual_instructions', 'products.manual_open_time', 'products.manual_close_time',
                'products.manual_timezone', 'product_packages.name as package_name',
                'providers.code as provider_code', 'users.name as user_name',
                'users.email as user_email', 'users.phone as user_phone',
            ])
            ->paginate($filters['per_page'])
            ->withQueryString();

        $productIds = collect($attempts->items())->pluck('product_id')->unique()->values();
        $fieldLabels = $productIds->isEmpty()
            ? collect()
            : DB::table('product_input_fields')->whereIn('product_id', $productIds)
                ->get(['product_id', 'field_key', 'label'])
                ->groupBy('product_id')
                ->map(fn ($rows) => $rows->pluck('label', 'field_key')->all());

        $knownLabels = [
            'destination' => 'Tujuan / ID akun', 'user_id' => 'ID pengguna', 'userId' => 'ID pengguna',
            'server_id' => 'ID server', 'serverId' => 'ID server', 'zone_id' => 'ID zona',
            'phone' => 'Nomor telepon', 'customer_no' => 'Nomor pelanggan', 'nickname' => 'Nama akun',
            'account_id' => 'ID akun', 'meter_number' => 'Nomor meter', 'email' => 'Email',
        ];

        $manualOverdueMinutes = app(OperationalSettings::class)->value('manual_overdue_minutes');
        $isSuperAdmin = $request->user('admin')?->role === 'SUPER_ADMIN';
        $attempts->through(function (object $attempt) use ($presentation, $fieldLabels, $knownLabels, $manualOverdueMinutes, $isSuperAdmin): array {
            $input = $presentation->json($attempt->customer_input);
            $snapshot = $presentation->json($attempt->snapshot);
            $delivery = $presentation->json($attempt->delivery_payload);
            $labels = $fieldLabels->get($attempt->product_id, []);
            $destinations = [];
            foreach ($input as $key => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $destinations[] = [
                        'label' => $labels[$key] ?? $knownLabels[$key] ?? 'Data tujuan',
                        'value' => (string) $value,
                    ];
                }
            }

            $source = match ($attempt->provider_code) {
                'MANUAL' => 'Penanganan manual',
                'VOUCHER_STOCK' => 'Stok kode digital',
                'DIGIFLAZZ' => 'Digiflazz',
                default => $attempt->provider_code ?: 'Belum ditentukan',
            };
            $terminal = ! in_array($attempt->order_status, ['PAID', 'PROCESSING'], true);
            $quarantined = $attempt->provider_code === 'DIGIFLAZZ'
                && $attempt->status === 'BLOCKED'
                && str_starts_with((string) ($attempt->last_error ?? ''), 'LEGACY_ENV_QUARANTINED:');
            $canResolveQuarantine = $quarantined && $isSuperAdmin
                && $attempt->order_status === 'BLOCKED' && $attempt->order_paid_at !== null
                && data_get($snapshot, 'product.fulfillment_mode') === 'AUTO_PROVIDER';
            $resolutionDestination = null;
            if ($canResolveQuarantine) {
                try {
                    $template = app(DigiflazzDestinationTemplate::class)->resolve(
                        (string) data_get($snapshot, 'product.slug', $attempt->product_slug),
                        data_get($snapshot, 'provider.customer_no_template'),
                        array_keys($input)
                    );
                    $resolutionDestination = app(FulfillmentTargetBuilder::class)
                        ->customerNo($input, ['customer_no_template' => $template]);
                } catch (ValidationException $exception) {
                    $canResolveQuarantine = false;
                }
            }
            $manualAge = $attempt->status === 'MANUAL_PENDING'
                ? ServiceHours::elapsedMinutes(
                    $attempt->created_at, now(),
                    $attempt->manual_open_time, $attempt->manual_close_time, $attempt->manual_timezone
                )
                : 0;

            return [
                'id' => (int) $attempt->id,
                'order_id' => (int) $attempt->order_id,
                'order_number' => $attempt->order_number,
                'order_status' => $attempt->order_status,
                'order_status_label' => $presentation->status($attempt->order_status),
                'attempt_no' => (int) $attempt->attempt_no,
                'status' => $attempt->status,
                'status_label' => $presentation->status($attempt->status),
                'source' => $source,
                'provider_status' => $attempt->provider_status,
                'response_code' => $attempt->provider_rc,
                'price_idr' => $attempt->price_idr !== null ? (int) $attempt->price_idr : null,
                'safe_to_failover' => (bool) $attempt->safe_to_failover,
                'last_error' => $presentation->note($attempt->last_error),
                'last_checked_at' => $presentation->date($attempt->last_checked_at),
                'created_at' => $presentation->date($attempt->created_at),
                'completed_at' => $presentation->date($attempt->completed_at),
                'process_reference' => $attempt->external_reference,
                'product_name' => data_get($snapshot, 'product.name') ?: $attempt->product_name,
                'package_name' => data_get($snapshot, 'package.name') ?: $attempt->package_name,
                'buyer_name' => $snapshot['buyer_name'] ?? ($attempt->user_name ?: 'Pelanggan tamu'),
                'buyer_email' => $attempt->user_email ?: $attempt->guest_email,
                'buyer_phone' => $attempt->user_phone ?: $attempt->guest_phone,
                'destinations' => $destinations,
                'manual_instructions' => $attempt->manual_instructions,
                'delivery' => $delivery,
                'manual_age_minutes' => $manualAge,
                'is_overdue' => $attempt->status === 'MANUAL_PENDING'
                    && $manualAge > $manualOverdueMinutes,
                'can_resolve_quarantine' => $canResolveQuarantine,
                'resolution_destination' => $resolutionDestination,
                'can_complete' => ! $terminal && $attempt->status === 'MANUAL_PENDING'
                    && in_array($attempt->order_status, ['PAID', 'PROCESSING'], true),
                'can_fail' => ! $terminal && $attempt->status === 'MANUAL_PENDING',
                'can_reconcile' => ! $terminal && in_array($attempt->status, ['SENDING', 'PENDING', 'UNKNOWN'], true),
                'can_retry' => ($terminal || $quarantined)
                    ? false
                    : ($attempt->status === 'BLOCKED'
                        && $attempt->last_error !== 'Semua SKU yang diizinkan telah gagal. Hubungi superadmin.'),
                'retry_label' => $attempt->status === 'FAILED_CONFIRMED'
                    ? 'Coba sumber berikutnya' : 'Coba proses lagi',
            ];
        });

        return Inertia::render('Admin/Fulfillment', [
            'attempts' => $attempts,
            'filters' => $filters,
            'metrics' => $metrics,
            'manualOverdueMinutes' => $manualOverdueMinutes,
            'statuses' => collect(self::STATUSES)->mapWithKeys(
                fn (string $status): array => [$status => $presentation->status($status)]
            )->all(),
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    public function completeManual(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $data = $request->validate([
            'delivery_code' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);
        $admin = $request->user('admin');

        // Sukses manual wajib punya bukti serah-terima: kode atau catatan.
        if (trim((string) ($data['delivery_code'] ?? '')) === '' && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'delivery_code' => 'Isi minimal kode pengiriman atau catatan sebelum menandai sukses.',
            ]);
        }

        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $fulfillment->completeManual(
            $attemptId,
            $data['delivery_code'] ?? null,
            $data['note'] ?? null,
            (int) $admin->id
        );
        $this->audit($request, 'fulfillment.manual.completed', $attemptId, (array) $before);

        return back()->with('status', 'Pesanan manual ditandai berhasil.');
    }

    public function failManual(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:4000'],
        ]);
        $admin = $request->user('admin');

        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $fulfillment->failManual($attemptId, $data['reason'], (int) $admin->id);
        $this->audit($request, 'fulfillment.manual.failed', $attemptId, (array) $before);

        return back()->with('status', 'Pesanan manual ditandai gagal.');
    }

    public function reconcile(Request $request, int $attemptId): RedirectResponse
    {
        $attempt = $this->latestAttempt($attemptId, ['SENDING', 'PENDING', 'UNKNOWN']);
        ReconcileFulfillmentJob::dispatch($attemptId, true);
        $this->audit($request, 'fulfillment.reconcile.requested', $attemptId, (array) $attempt);

        return back()->with('status', 'Pemeriksaan status dimasukkan ke antrean.');
    }

    public function retry(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $fulfillment->retrySafeAttempt($attemptId);
        $this->audit($request, 'fulfillment.retry.requested', $attemptId, (array) $before);

        return back()->with('status', 'Proses ulang dimasukkan ke antrean dengan pengaman transaksi ganda.');
    }

    public function resolveQuarantine(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $data = $request->validate([
            'provider_ref_id' => ['required', 'string', 'max:120'],
            'provider_status' => ['required', Rule::in(['Gagal'])],
            'provider_rc' => ['required', 'string', 'max:2'],
            'evidence_reference' => ['required', 'string', 'max:500'],
            'note' => ['required', 'string', 'max:2000'],
            'confirmed_customer_no' => ['required', 'string', 'max:100'],
        ]);
        $admin = $request->user('admin');
        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $newAttemptId = $fulfillment->resolveLegacyQuarantine(
            $attemptId,
            (int) $admin->id,
            trim($data['provider_ref_id']),
            trim($data['provider_rc']),
            trim($data['evidence_reference']),
            trim($data['note']),
            $data['confirmed_customer_no']
        );
        app(AdminAuditService::class)->record(
            $request,
            'fulfillment.quarantine.resolved',
            'fulfillment_attempt',
            $newAttemptId,
            ['legacy_attempt_id' => $attemptId, 'legacy_status' => $before->status],
            ['new_attempt_id' => $newAttemptId, 'provider_rc' => $data['provider_rc'], 'provider_status' => 'Gagal']
        );

        return back()->with('status', 'Attempt Production baru dibuat; attempt karantina tetap diblokir.');
    }

    private function latestAttempt(int $attemptId, array $allowedStatuses): object
    {
        $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $latestId = DB::table('fulfillment_attempts')->where('order_id', $attempt->order_id)->max('id');
        if ((int) $latestId !== (int) $attempt->id) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Proses ini sudah memiliki pembaruan yang lebih baru. Muat ulang halaman.',
            ]);
        }
        if (! in_array($attempt->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Status proses ini tidak dapat menjalankan tindakan tersebut.',
            ]);
        }

        $orderStatus = DB::table('orders')->where('id', $attempt->order_id)->value('status');
        if (! in_array($orderStatus, ['PAID', 'PROCESSING'], true)) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Pesanan ini sudah selesai dan tidak dapat diproses ulang.',
            ]);
        }

        return $attempt;
    }

    private function audit(Request $request, string $action, int $attemptId, array $before): void
    {
        $after = DB::table('fulfillment_attempts')->where('id', $attemptId)->first();
        app(AdminAuditService::class)->record(
            $request,
            $action,
            'fulfillment_attempt',
            $attemptId,
            $before,
            $after ? (array) $after : null
        );
    }
}
