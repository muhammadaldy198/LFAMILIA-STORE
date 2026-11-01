<?php

namespace App\Services;

use App\Jobs\SendFulfillmentJob;
use App\Services\Fulfillment\DigiflazzClient;
use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use App\Services\Fulfillment\FulfillmentTargetBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FulfillmentService
{
    public function __construct(
        private readonly DigiflazzClient $digiflazz,
        private readonly DigiflazzCatalogService $digiflazzCatalog,
        private readonly FulfillmentTargetBuilder $targetBuilder,
        private readonly VoucherStockService $voucherStock,
        private readonly AdminNotificationService $notifications,
        private readonly TransactionalEmailService $emails,
    ) {}

    public function startOrder(int $orderId): void
    {
        $attemptId = DB::transaction(function () use ($orderId): ?int {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                return null;
            }

            $existing = DB::table('fulfillment_attempts')->where('order_id', $orderId)
                ->orderByDesc('attempt_no')->lockForUpdate()->first();
            if ($existing) {
                return $existing->status === 'CREATED' ? (int) $existing->id : null;
            }

            $snapshot = $this->json($order->snapshot);
            $mappingId = (int) ($order->provider_mapping_id ?: data_get($snapshot, 'provider.mapping_id'));
            $mapping = $this->mapping($mappingId);
            if (! $mapping) {
                return null;
            }

            $mode = (string) data_get($snapshot, 'product.fulfillment_mode', '');
            if ($mode === 'MANUAL') {
                $attempt = $this->createAttempt($order, $mapping, 'MANUAL_PENDING');
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'PROCESSING',
                    'updated_at' => now(),
                ]);
                $this->event((int) $order->id, 'MANUAL_FULFILLMENT_QUEUED', $order->status, 'PROCESSING', [
                    'attempt_id' => $attempt->id,
                ]);
                $this->notifications->record(
                    'fulfillment.manual.pending',
                    'Pesanan menunggu diproses manual',
                    'Pesanan '.$order->order_number.' menunggu diproses manual oleh admin.',
                    'WARNING',
                    'fulfillment_attempt',
                    $attempt->id,
                    ['order_id' => (int) $order->id]
                );

                return null;
            }

            if ($mode !== 'AUTO_PROVIDER') {
                $this->blockedWithoutSend($order, $mapping, 'Mode fulfillment order tidak didukung.');

                return null;
            }

            $blockReason = $this->mappingBlockReason($mapping, $snapshot, (int) $order->id);
            if ($blockReason !== null) {
                // The order is locked to its selected SKU. No automatic
                // switch is allowed, even before the first provider request.
                $this->blockedWithoutSend($order, $mapping, $blockReason);

                return null;
            }

            $attempt = $this->createAttempt($order, $mapping, 'CREATED');
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'PROCESSING',
                'updated_at' => now(),
            ]);
            $this->event((int) $order->id, 'FULFILLMENT_QUEUED', $order->status, 'PROCESSING', [
                'attempt_id' => $attempt->id,
            ]);

            return (int) $attempt->id;
        }, 3);

        if ($attemptId !== null) {
            SendFulfillmentJob::dispatch($attemptId)->afterCommit();
        }
    }

    public function sendAttempt(int $attemptId): void
    {
        try {
            $context = DB::transaction(function () use ($attemptId): ?array {
                $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                    ->lockForUpdate()->first();
                if (! $attempt || $attempt->status !== 'CREATED') {
                    return null;
                }

                $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
                if (! $order || ! in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                    return null;
                }

                $snapshot = $this->json($order->snapshot);
                $mapping = $this->mapping((int) $attempt->provider_mapping_id);
                if (! $mapping) {
                    throw ValidationException::withMessages(['fulfillment' => 'Mapping provider tidak ditemukan.']);
                }

                $blockReason = $this->mappingBlockReason($mapping, $snapshot, (int) $order->id);
                if ($blockReason !== null) {
                    throw ValidationException::withMessages(['fulfillment' => $blockReason]);
                }

                $config = $this->json($mapping->fulfillment_config);
                if ($mapping->provider_code === 'DIGIFLAZZ') {
                    $product = DB::table('product_packages')
                        ->join('products', 'products.id', '=', 'product_packages.product_id')
                        ->where('product_packages.id', $order->product_package_id)
                        ->select('products.customer_no_template', 'products.id', 'products.slug')
                        ->first();
                    if ($product) {
                        if (array_key_exists('customer_no_template', $snapshot['provider'] ?? [])) {
                            $config['customer_no_template'] = $snapshot['provider']['customer_no_template'];
                        } else {
                            $config['customer_no_template'] = $product->customer_no_template
                                ?: ($config['customer_no_template'] ?? null);
                        }
                    }
                    $snapshotTemplate = (string) ($config['customer_no_template'] ?? '');
                    // A checkout snapshot is immutable. Empty optional values
                    // may be omitted from customer_input; that must NOT make
                    // fulfillment rewrite a multi-field destination format.
                    preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $snapshotTemplate, $templateMatches);
                    $keys = $templateMatches[1] ?: array_keys($this->json($order->customer_input));
                    $config['customer_no_template'] = app(DigiflazzDestinationTemplate::class)->resolve(
                        (string) data_get($snapshot, 'product.slug', $product?->slug ?? ''),
                        $snapshotTemplate !== '' ? $snapshotTemplate : null,
                        $keys
                    );
                }
                if ($mapping->provider_code === 'VOUCHER_STOCK') {
                    $stockKey = trim((string) data_get($config, 'stock_key', ''));
                    $request = [
                        'stock_key' => $stockKey,
                        'ref_id' => (string) $attempt->external_reference,
                    ];
                    DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                        'status' => 'SENDING',
                        'request_payload' => json_encode($request, JSON_THROW_ON_ERROR),
                        'last_error' => null,
                        'last_checked_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return [
                        'attempt_id' => (int) $attempt->id,
                        'provider_code' => 'VOUCHER_STOCK',
                        'stock_key' => $stockKey,
                        'request' => $request,
                    ];
                }

                $customerInput = $this->json($order->customer_input);
                $customerNo = $this->targetBuilder->customerNo($customerInput, $config);
                $request = [
                    'buyer_sku_code' => (string) $mapping->external_sku,
                    'customer_no' => $customerNo,
                    'ref_id' => (string) $attempt->external_reference,
                ];

                DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                    'status' => 'SENDING',
                    'request_payload' => json_encode($request, JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'last_checked_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'attempt_id' => (int) $attempt->id,
                    'provider_code' => 'DIGIFLAZZ',
                    'request' => $request,
                ];
            }, 3);
        } catch (ValidationException $exception) {
            $this->markBlocked($attemptId, $exception->getMessage());

            return;
        }

        if ($context === null) {
            return;
        }

        if ($context['provider_code'] === 'VOUCHER_STOCK') {
            try {
                $this->fulfillVoucherStock($context['attempt_id'], $context['stock_key']);
            } catch (ValidationException $exception) {
                $this->markBlocked($attemptId, $exception->getMessage());
            }

            return;
        }

        try {
            $response = $this->digiflazz->transact($context['request'], $attemptId);
        } catch (ValidationException $exception) {
            $this->markBlocked($attemptId, $exception->getMessage());

            return;
        } catch (RuntimeException $exception) {
            $this->markUnknown($attemptId, 'Provider request tidak dapat dipastikan.');

            return;
        }

        try {
            $this->applyProviderResult($attemptId, $response, 'provider_response');
        } catch (ValidationException $exception) {
            Log::warning('Digiflazz initial response rejected', [
                'fulfillment_attempt_id' => $attemptId,
                'reason' => $exception->getMessage(),
            ]);
            $this->markUnknown($attemptId, 'Response provider tidak dapat diverifikasi.');
        }
    }

    public function reconcileAttempt(int $attemptId, bool $manual = false): void
    {
        // Atomic claim prevents the scheduler and manual checks from calling Digiflazz concurrently.
        $attempt = DB::transaction(function () use ($attemptId, $manual): ?object {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if (! $attempt || ! in_array($attempt->status, ['PENDING', 'UNKNOWN', 'SENDING'], true)) {
                return null;
            }
            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                return null;
            }
            $newerAttemptExists = DB::table('fulfillment_attempts')
                ->where('order_id', $attempt->order_id)
                ->where('id', '>', $attempt->id)->exists();
            if ($newerAttemptExists) {
                return null;
            }
            if ($attempt->reconcile_claimed_at !== null) {
                $leaseIsActive = Carbon::parse($attempt->reconcile_claimed_at)->gt(now()->subMinutes(5));
                if ($leaseIsActive) {
                    return null;
                }
            }

            if (! $manual && Carbon::parse($attempt->created_at)->lte(now()->subDay())) {
                $this->escalateStalledReconciliation($attempt, $order);

                return null;
            }

            DB::table('fulfillment_attempts')->where('id', $attemptId)->update([
                'reconcile_checks' => DB::raw('reconcile_checks + 1'),
                'reconcile_claimed_at' => now(),
                'updated_at' => now(),
            ]);

            return $attempt;
        }, 3);

        if (! $attempt) {
            return;
        }

        try {
            $mapping = $this->mapping((int) $attempt->provider_mapping_id);
            if ($mapping?->provider_code === 'VOUCHER_STOCK') {
                $config = $this->json($mapping->fulfillment_config);
                $stockKey = trim((string) data_get($config, 'stock_key', ''));
                try {
                    $this->fulfillVoucherStock($attemptId, $stockKey);
                } catch (ValidationException $exception) {
                    $this->markBlocked($attemptId, $exception->getMessage());
                }

                return;
            }

            $request = $this->json($attempt->request_payload);
            if ($request === []) {
                $this->markUnknown($attemptId, 'Payload reconciliation tidak tersedia.');

                return;
            }

            try {
                $response = $this->digiflazz->transact($request, $attemptId);
            } catch (ValidationException $exception) {
                $this->recordReconciliationError($attemptId, 'Integrasi provider belum siap untuk reconciliation.');

                return;
            } catch (RuntimeException $exception) {
                $this->markUnknown($attemptId, 'Reconciliation provider belum dapat dipastikan.');

                return;
            }
            DB::table('fulfillment_attempts')->where('id', $attemptId)->update([
                'reconciled_at' => now(),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $this->applyProviderResult($attemptId, $response, 'reconciliation');
            } catch (ValidationException $exception) {
                Log::warning('Digiflazz reconciliation response rejected', [
                    'fulfillment_attempt_id' => $attemptId,
                    'reason' => $exception->getMessage(),
                ]);
                $this->markUnknown($attemptId, 'Hasil reconciliation tidak dapat diverifikasi.');
            }
        } finally {
            DB::table('fulfillment_attempts')->where('id', $attemptId)->update([
                'reconcile_claimed_at' => null,
            ]);

            // Notify once after the third unresolved check, but keep
            // reconciling using the same ref_id until status is final or
            // the 24-hour upper limit is reached. A manual check does not alert.
            if (! $manual) {
                $this->escalateIfStalled($attemptId);
            }
        }
    }

    /**
     * Alert admin about unresolved attempts without terminating scheduled checks.
     * Deduped inside escalateStalledReconciliation.
     */
    private function escalateIfStalled(int $attemptId): void
    {
        $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)->first();
        if (! $attempt
            || ! in_array($attempt->status, ['PENDING', 'UNKNOWN', 'SENDING'], true)
            || (int) $attempt->reconcile_checks < 3) {
            return;
        }

        $order = DB::table('orders')->where('id', $attempt->order_id)->first();
        if (! $order) {
            return;
        }

        $this->escalateStalledReconciliation($attempt, $order);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function applyProviderResult(int $attemptId, array $response, string $source): void
    {
        $failover = DB::transaction(function () use ($attemptId, $response, $source): bool {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt) {
                throw ValidationException::withMessages(['fulfillment' => 'Fulfillment attempt tidak ditemukan.']);
            }

            // A pre-cutover response must never undo a quarantined attempt,
            // including an authenticated webhook arriving after migration.
            if ($this->isLegacyQuarantined($attempt)) {
                return false;
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['fulfillment' => 'Order fulfillment tidak ditemukan.']);
            }

            $request = $this->json($attempt->request_payload);
            $this->validateProviderResponse($request, $response);

            // Cancellation is terminal even when a valid provider result arrives late.
            // Keep both the cancelled order and its attempt unchanged.
            if ($attempt->status === 'CANCELLED' || $order->status === 'CANCELLED') {
                return false;
            }

            $incoming = $this->providerStatus((string) $response['status']);
            $responseCode = isset($response['rc']) && is_scalar($response['rc'])
                ? trim((string) $response['rc']) : '';
            // Digiflazz buyer API uses rc=00 only for a successful delivery.
            // Timeout (01/70) and router-pending (99/03) can represent an
            // already-created transaction: never confirm a failure or issue a
            // replacement order while the original reference is uncertain.
            if (($incoming === 'SUCCESS' && $responseCode !== '00')
                || ($incoming === 'FAILED_CONFIRMED'
                    && in_array($responseCode, ['00', '01', '03', '70', '99'], true))
                || ($incoming === 'PENDING' && $responseCode === '00')) {
                $incoming = 'UNKNOWN';
            }
            if ($attempt->status === 'SUCCESS') {
                return false;
            }
            if ($attempt->status === 'FAILED_CONFIRMED') {
                if ($incoming !== 'FAILED_CONFIRMED') {
                    $this->event((int) $order->id, 'FULFILLMENT_CONFLICT_IGNORED', $order->status, $order->status, [
                        'attempt_id' => $attempt->id,
                        'incoming_status' => $incoming,
                        'source' => $source,
                    ]);
                }

                return false;
            }

            $price = isset($response['price']) && is_numeric($response['price'])
                ? max(0, (int) $response['price']) : null;
            $serial = isset($response['sn']) && is_scalar($response['sn'])
                ? trim((string) $response['sn']) : null;
            $providerRc = isset($response['rc']) && is_scalar($response['rc'])
                ? substr((string) $response['rc'], 0, 40) : null;

            $common = [
                'provider_status' => substr((string) $response['status'], 0, 40),
                'provider_rc' => $providerRc,
                'serial_number' => $serial !== '' ? $serial : null,
                'price_idr' => $price,
                'response_payload' => json_encode($this->sanitizeProviderResponse($response), JSON_THROW_ON_ERROR),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ];

            if ($incoming === 'SUCCESS') {
                DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                    ...$common,
                    'status' => 'SUCCESS',
                    'safe_to_failover' => false,
                    'last_error' => null,
                    'completed_at' => now(),
                ]);

                $delivery = array_filter([
                    'serial_number' => $serial !== '' ? $serial : null,
                ], fn (mixed $value): bool => $value !== null && $value !== '');

                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'SUCCESS',
                    'delivery_payload' => $delivery === []
                        ? null : json_encode($delivery, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $this->event((int) $order->id, 'FULFILLMENT_SUCCEEDED', $order->status, 'SUCCESS', [
                    'attempt_id' => $attempt->id,
                    'source' => $source,
                ]);
                $this->notifications->record(
                    'fulfillment.success',
                    'Pesanan berhasil',
                    'Pesanan '.$order->order_number.' selesai diproses.',
                    'INFO',
                    'order',
                    $order->id
                );
                $this->emails->queueOrderCompleted((int) $order->id, 'digiflazz');

                return false;
            }

            if ($incoming === 'FAILED_CONFIRMED') {
                DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                    ...$common,
                    'status' => 'FAILED_CONFIRMED',
                    'safe_to_failover' => false,
                    'last_error' => isset($response['message']) ? substr((string) $response['message'], 0, 1000) : null,
                    'completed_at' => now(),
                ]);
                $this->event((int) $order->id, 'FULFILLMENT_FAILED_CONFIRMED', $order->status, $order->status, [
                    'attempt_id' => $attempt->id,
                    'source' => $source,
                ]);
                $this->notifications->record(
                    'fulfillment.failed',
                    'Provider mengonfirmasi gagal',
                    'Pesanan '.$order->order_number.' gagal diproses oleh penyedia. Perlu penanganan manual.',
                    'ERROR',
                    'fulfillment_attempt',
                    $attempt->id,
                    ['order_id' => (int) $order->id]
                );

                // Per PRD §47: Single SKU per nominal, no auto-switch to another SKU.
                // Keep the attempt terminal state as FAILED_CONFIRMED, but mark the order as BLOCKED.
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'BLOCKED',
                    'updated_at' => now(),
                ]);
                $this->event((int) $order->id, 'FULFILLMENT_EXHAUSTED', $order->status, 'BLOCKED', [
                    'attempt_id' => $attempt->id,
                    'automatic_retries_stopped' => true,
                ]);
                $this->notifications->record(
                    'fulfillment.exhausted',
                    'Pengiriman tertahan - perlu penanganan manual',
                    'Order '.$order->order_number.' gagal pada provider. Hubungi pelanggan; refund tidak otomatis.',
                    'ERROR',
                    'order',
                    $order->id
                );
                $this->emails->queueOrderFailed((int) $order->id);

                return false;
            }

            $previousAttemptStatus = $attempt->status;
            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                ...$common,
                'status' => $incoming,
                'safe_to_failover' => false,
                'last_error' => null,
            ]);
            if ($order->status === 'PAID') {
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'PROCESSING',
                    'updated_at' => now(),
                ]);
            }
            $this->event((int) $order->id, 'FULFILLMENT_'.$incoming, $order->status, 'PROCESSING', [
                'attempt_id' => $attempt->id,
                'source' => $source,
            ]);
            if ($previousAttemptStatus !== $incoming && in_array($incoming, ['PENDING', 'UNKNOWN'], true)) {
                $this->notifications->record(
                    'fulfillment.'.strtolower($incoming),
                    'Pengiriman '.($incoming === 'PENDING' ? 'masih menunggu hasil penyedia' : 'belum pasti hasilnya'),
                    'Pesanan '.$order->order_number.' perlu dicek ulang memakai nomor referensi yang sama.',
                    'WARNING',
                    'fulfillment_attempt',
                    $attempt->id,
                    ['order_id' => (int) $order->id]
                );
            }

            return false;
        }, 3);

        // Automatic failover is permanently disabled per PRD §47 (no auto-switch).
        // Orders with FAILED_CONFIRMED remain BLOCKED for manual resolution.
    }

    /**
     * Open a NEW production attempt after a SUPER_ADMIN verifies a quarantined
     * historical reference failed. The historical attempt is never reactivated.
     */
    public function resolveLegacyQuarantine(
        int $attemptId,
        int $adminId,
        string $providerRefId,
        string $providerRc,
        string $evidenceReference,
        string $note,
        string $confirmedCustomerNo,
    ): int {
        // Codes 01/70 are uncertain even when Digiflazz labels them "Gagal".
        // Only documented conclusive buyer failures may authorize a NEW send.
        $definitiveFailureCodes = [
            '02', '40', '41', '42', '43', '44', '45', '47', '49',
            '50', '51', '52', '53', '54', '55', '56', '57', '58',
            '59', '60', '61', '62', '63', '64', '65', '66', '67',
            '68', '69', '71', '72', '73', '74', '80', '81', '82',
        ];
        if (! in_array($providerRc, $definitiveFailureCodes, true)) {
            throw ValidationException::withMessages([
                'provider_rc' => 'Kode respons bukan kegagalan final yang aman untuk pengiriman baru.',
            ]);
        }

        $newAttemptId = DB::transaction(function () use (
            $attemptId, $adminId, $providerRefId, $providerRc,
            $evidenceReference, $note, $confirmedCustomerNo
        ): int {
            $admin = DB::table('admin_users')->where('id', $adminId)->first();
            if (! $admin || ! $admin->is_active || $admin->role !== 'SUPER_ADMIN') {
                throw ValidationException::withMessages(['fulfillment' => 'Hanya superadmin aktif yang dapat menyelesaikan karantina.']);
            }

            $legacy = DB::table('fulfillment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if (! $legacy || ! $this->isLegacyQuarantined($legacy)) {
                throw ValidationException::withMessages(['fulfillment' => 'Attempt bukan karantina aktif yang bisa diselesaikan.']);
            }
            $order = DB::table('orders')->where('id', $legacy->order_id)->lockForUpdate()->first();
            if (! $order || $order->status !== 'BLOCKED' || $order->paid_at === null) {
                throw ValidationException::withMessages(['fulfillment' => 'Pesanan karantina harus telah dibayar dan masih BLOCKED.']);
            }
            $latestId = DB::table('fulfillment_attempts')->where('order_id', $order->id)->max('id');
            if ((int) $latestId !== (int) $legacy->id) {
                throw ValidationException::withMessages(['fulfillment' => 'Sudah terdapat attempt yang lebih baru. Muat ulang halaman.']);
            }
            if (! hash_equals((string) $legacy->external_reference, $providerRefId)) {
                throw ValidationException::withMessages(['provider_ref_id' => 'Referensi bukti harus cocok persis dengan attempt warisan.']);
            }

            $snapshot = $this->json($order->snapshot);
            if (data_get($snapshot, 'product.fulfillment_mode') !== 'AUTO_PROVIDER') {
                throw ValidationException::withMessages(['fulfillment' => 'Hanya pesanan pengiriman Digiflazz otomatis yang dapat dipulihkan.']);
            }
            $mapping = $this->mapping((int) $legacy->provider_mapping_id);
            if (! $mapping || $mapping->provider_code !== 'DIGIFLAZZ'
                || (int) $mapping->product_package_id !== (int) $order->product_package_id) {
                throw ValidationException::withMessages(['fulfillment' => 'Mapping Digiflazz warisan tidak tersedia.']);
            }
            $snapshotSku = (string) data_get($snapshot, 'provider.sku', '');
            if ($snapshotSku === '' || strcasecmp($snapshotSku, (string) $mapping->external_sku) !== 0) {
                throw ValidationException::withMessages(['fulfillment' => 'SKU pesanan berbeda dari mapping sekarang; tidak boleh mengganti SKU diam-diam.']);
            }
            $blockReason = $this->mappingBlockReason($mapping, $snapshot, (int) $order->id);
            if ($blockReason !== null) {
                throw ValidationException::withMessages(['fulfillment' => $blockReason]);
            }

            $input = $this->json($order->customer_input);
            $productSlug = (string) (data_get($snapshot, 'product.slug')
                ?: DB::table('products')->where('id', $order->product_id)->value('slug'));
            $template = app(DigiflazzDestinationTemplate::class)->resolve(
                $productSlug,
                data_get($snapshot, 'provider.customer_no_template'),
                array_keys($input)
            );
            $target = $this->targetBuilder->customerNo($input, ['customer_no_template' => $template]);
            if (! hash_equals($target, $confirmedCustomerNo)) {
                throw ValidationException::withMessages(['confirmed_customer_no' => 'Nomor tujuan tidak cocok dengan data pesanan.']);
            }

            $newAttempt = $this->createAttempt($order, $mapping, 'CREATED');
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'PROCESSING',
                'updated_at' => now(),
            ]);
            $this->event((int) $order->id, 'FULFILLMENT_QUARANTINE_MANUALLY_RESOLVED', 'BLOCKED', 'PROCESSING', [
                'legacy_attempt_id' => (int) $legacy->id,
                'new_attempt_id' => (int) $newAttempt->id,
                'admin_id' => $adminId,
                'provider_ref_id' => $providerRefId,
                'provider_status' => 'Gagal',
                'provider_rc' => $providerRc,
                'evidence_reference' => $evidenceReference,
                'evidence_note' => $note,
                'destination_confirmed' => true,
                'legacy_attempt_unchanged' => true,
            ]);

            return (int) $newAttempt->id;
        }, 3);

        // A NEW ref_id is used; neither the quarantined ref_id nor its request
        // payload is ever submitted to the Production API.
        SendFulfillmentJob::dispatch($newAttemptId)->afterCommit();

        return $newAttemptId;
    }

    public function retrySafeAttempt(int $attemptId): void
    {
        $sendAttemptId = DB::transaction(function () use ($attemptId): ?int {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt) {
                return null;
            }
            $this->ensureLatestAttempt($attempt);

            if ($this->isLegacyQuarantined($attempt)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Attempt dari sebelum peralihan Production tidak boleh dikirim ulang.',
                ]);
            }

            if ($attempt->status === 'FAILED_CONFIRMED' && $attempt->safe_to_failover) {
                return null;
            }
            if ($attempt->status !== 'BLOCKED' || $attempt->last_error === 'Semua SKU yang diizinkan telah gagal. Hubungi superadmin.') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Hanya proses tertahan yang belum terkirim yang dapat dicoba ulang.',
                ]);
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                return null;
            }

            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'CREATED',
                'last_error' => null,
                'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'PROCESSING',
                'updated_at' => now(),
            ]);
            $this->event((int) $order->id, 'FULFILLMENT_RETRY_QUEUED', $order->status, 'PROCESSING', [
                'attempt_id' => $attempt->id,
            ]);

            return (int) $attempt->id;
        }, 3);

        $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)->first();

        if ($sendAttemptId !== null) {
            SendFulfillmentJob::dispatch($sendAttemptId)->afterCommit();
        }
    }

    private function ensureLatestAttempt(object $attempt): void
    {
        $newerExists = DB::table('fulfillment_attempts')
            ->where('order_id', $attempt->order_id)
            ->where('id', '>', $attempt->id)
            ->exists();

        if ($newerExists) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Proses ini sudah memiliki pembaruan yang lebih baru. Muat ulang halaman sebelum bertindak.',
            ]);
        }
    }

    private function fulfillVoucherStock(int $attemptId, string $stockKey): void
    {
        DB::transaction(function () use ($attemptId, $stockKey): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || $attempt->status === 'SUCCESS') {
                return;
            }
            if (! in_array($attempt->status, ['SENDING', 'PENDING', 'UNKNOWN'], true)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Status pemenuhan stok kode tidak dapat diproses.',
                ]);
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Status order tidak dapat menerima stok kode.',
                ]);
            }

            $claim = $this->voucherStock->claim((int) $order->id, $stockKey);
            $delivery = ['code' => $claim['code']];

            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'SUCCESS',
                'provider_status' => 'success',
                'serial_number' => 'STOCK-'.$claim['id'],
                'response_payload' => json_encode([
                    'stock_code_id' => $claim['id'],
                    'stock_key' => $stockKey,
                ], JSON_THROW_ON_ERROR),
                'safe_to_failover' => false,
                'last_error' => null,
                'completed_at' => now(),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'SUCCESS',
                'delivery_payload' => json_encode($delivery, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->voucherStock->markDelivered($claim['id'], (int) $order->id);
            $this->event((int) $order->id, 'VOUCHER_STOCK_DELIVERED', $order->status, 'SUCCESS', [
                'attempt_id' => $attempt->id,
                'stock_code_id' => $claim['id'],
            ]);
            $this->notifications->record(
                'fulfillment.stock.success',
                'Kode digital terkirim',
                'Order '.$order->order_number.' selesai dari stok kode.',
                'INFO',
                'order',
                $order->id
            );
            $this->emails->queueOrderCompleted((int) $order->id, 'stock');
        }, 3);
    }

    public function completeManual(int $attemptId, ?string $deliveryCode, ?string $note, int $adminId): void
    {
        // Tidak boleh ada pesanan "sukses" tanpa bukti serah-terima.
        if (trim((string) $deliveryCode) === '' && trim((string) $note) === '') {
            throw ValidationException::withMessages([
                'delivery_code' => 'Isi minimal kode pengiriman atau catatan sebelum menandai sukses.',
            ]);
        }

        DB::transaction(function () use ($attemptId, $deliveryCode, $note, $adminId): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== 'MANUAL_PENDING') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Penanganan manual tidak lagi dalam status menunggu.',
                ]);
            }
            $this->ensureLatestAttempt($attempt);

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                throw ValidationException::withMessages(['fulfillment' => 'Status pesanan tidak dapat diselesaikan secara manual.']);
            }

            $delivery = array_filter([
                'code' => $deliveryCode !== null ? trim($deliveryCode) : null,
                'note' => $note !== null ? trim($note) : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'SUCCESS',
                'response_payload' => $delivery === [] ? null : json_encode($delivery, JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'SUCCESS',
                'delivery_payload' => $delivery === [] ? null : json_encode($delivery, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->event((int) $order->id, 'MANUAL_FULFILLMENT_SUCCEEDED', $order->status, 'SUCCESS', [
                'attempt_id' => $attempt->id,
                'admin_id' => $adminId,
            ]);
            $this->notifications->record(
                'fulfillment.manual.success',
                'Pesanan manual berhasil',
                'Pesanan '.$order->order_number.' telah diselesaikan secara manual.',
                'INFO',
                'order',
                $order->id
            );
            $this->emails->queueOrderCompleted((int) $order->id, 'manual');
        }, 3);
    }

    public function failManual(int $attemptId, string $reason, int $adminId): void
    {
        DB::transaction(function () use ($attemptId, $reason, $adminId): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== 'MANUAL_PENDING') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Penanganan manual tidak lagi dalam status menunggu.',
                ]);
            }
            $this->ensureLatestAttempt($attempt);

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['fulfillment' => 'Pesanan tidak ditemukan.']);
            }

            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'MANUAL_FAILED',
                'last_error' => $reason,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'FAILED',
                'updated_at' => now(),
            ]);
            $this->event((int) $order->id, 'MANUAL_FULFILLMENT_FAILED', $order->status, 'FAILED', [
                'attempt_id' => $attempt->id,
                'admin_id' => $adminId,
            ]);
            $this->notifications->record(
                'fulfillment.manual.failed',
                'Pesanan manual gagal',
                'Pesanan '.$order->order_number.' ditandai gagal oleh Admin.',
                'ERROR',
                'order',
                $order->id
            );
            $this->emails->queueOrderFailed((int) $order->id);
        }, 3);
    }

    private function markBlocked(int $attemptId, string $reason): void
    {
        DB::transaction(function () use ($attemptId, $reason): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || in_array($attempt->status, ['SUCCESS', 'FAILED_CONFIRMED', 'CANCELLED'], true)) {
                return;
            }
            if ($this->isLegacyQuarantined($attempt)) {
                return;
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if ($order?->status === 'CANCELLED') {
                return;
            }

            $wasBlocked = $attempt->status === 'BLOCKED';
            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'BLOCKED',
                'safe_to_failover' => false,
                'last_error' => substr($reason, 0, 4000),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
            $orderWasProcessing = $order && $order->status === 'PROCESSING';
            if ($orderWasProcessing) {
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'PAID',
                    'updated_at' => now(),
                ]);
                $this->event((int) $order->id, 'FULFILLMENT_BLOCKED', 'PROCESSING', 'PAID', [
                    'attempt_id' => $attempt->id,
                ]);
            }
            if ($order && ! $wasBlocked) {
                // A block that did not move the order to PAID still needs a trace.
                if (! $orderWasProcessing) {
                    $this->event((int) $order->id, 'FULFILLMENT_BLOCKED', $order->status, $order->status, [
                        'attempt_id' => $attempt->id,
                    ]);
                }
                $this->notifications->record(
                    'fulfillment.blocked',
                    'Pengiriman tertahan - perlu penanganan manual',
                    'Pesanan '.$order->order_number.' tertahan dan perlu penanganan manual. Alasan: '.substr($reason, 0, 500).($orderWasProcessing ? ' Pesanan kembali ke status sudah dibayar.' : ''),
                    'ERROR',
                    'order',
                    $order->id,
                    ['attempt_id' => $attempt->id]
                );
            }
        }, 3);
    }

    private function markUnknown(int $attemptId, string $reason): void
    {
        DB::transaction(function () use ($attemptId, $reason): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || in_array($attempt->status, ['SUCCESS', 'FAILED_CONFIRMED', 'CANCELLED'], true)) {
                return;
            }
            if ($this->isLegacyQuarantined($attempt)) {
                return;
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if ($order?->status === 'CANCELLED') {
                return;
            }

            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'UNKNOWN',
                'safe_to_failover' => false,
                'last_error' => substr($reason, 0, 4000),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
            if ($order) {
                if ($order->status === 'PAID') {
                    DB::table('orders')->where('id', $order->id)->update([
                        'status' => 'PROCESSING',
                        'updated_at' => now(),
                    ]);
                }
                $this->event((int) $order->id, 'FULFILLMENT_UNKNOWN', $order->status, 'PROCESSING', [
                    'attempt_id' => $attempt->id,
                ]);
            }
        }, 3);
    }

    /**
     * Persisted cutover quarantine must survive late queue responses and
     * callbacks without ever marking an unverified delivery as successful.
     */
    private function isLegacyQuarantined(object $attempt): bool
    {
        return $attempt->status === 'BLOCKED'
            && str_starts_with((string) ($attempt->last_error ?? ''), 'LEGACY_ENV_QUARANTINED:');
    }

    private function recordReconciliationError(int $attemptId, string $reason): void
    {
        DB::table('fulfillment_attempts')->where('id', $attemptId)
            ->where('status', '<>', 'BLOCKED')->update([
                'last_error' => substr($reason, 0, 4000),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Alert admin after 3 inconclusive checks, without interrupting retries.
     * The scheduler keeps probing within the 24-hour limit.
     */
    private function escalateStalledReconciliation(object $attempt, object $order): void
    {
        $alreadyEscalated = DB::table('admin_notifications')
            ->where('event_type', 'fulfillment.reconcile.stalled')
            ->where('target_type', 'fulfillment_attempt')
            ->where('target_id', (string) $attempt->id)
            ->exists();
        if ($alreadyEscalated) {
            return;
        }

        $this->event((int) $order->id, 'FULFILLMENT_RECONCILE_STALLED', $order->status, $order->status, [
            'attempt_id' => $attempt->id,
            'reconcile_checks' => (int) $attempt->reconcile_checks,
        ]);
        $this->notifications->record(
            'fulfillment.reconcile.stalled',
            'Hasil pengiriman belum pasti - perlu perhatian',
            'Pesanan '.$order->order_number.' sudah dicek otomatis berulang tanpa hasil final. Pengecekan otomatis tetap berjalan maksimal 24 jam; periksa dari menu Pemenuhan.',
            'ERROR',
            'fulfillment_attempt',
            $attempt->id,
            ['order_id' => (int) $order->id]
        );
    }

    private function blockedWithoutSend(object $order, object $mapping, string $reason): object
    {
        $attempt = $this->createAttempt($order, $mapping, 'BLOCKED', $reason);
        $this->event((int) $order->id, 'FULFILLMENT_BLOCKED', $order->status, $order->status, [
            'attempt_id' => $attempt->id,
        ]);

        // A voucher code reserved at checkout is not delivered when its
        // selected stock source is blocked. Release that reservation now,
        // without switching providers or erasing the blocked attempt.
        if ($mapping->provider_code === 'VOUCHER_STOCK') {
            $released = $this->voucherStock->release((int) $order->id);
            if ($released > 0) {
                $this->event((int) $order->id, 'VOUCHER_STOCK_RELEASED', $order->status, $order->status, [
                    'attempt_id' => $attempt->id,
                    'codes' => $released,
                ]);
            }
        }

        return $attempt;
    }

    private function createAttempt(object $order, object $mapping, string $status, ?string $error = null): object
    {
        $attemptNo = (int) DB::table('fulfillment_attempts')
            ->where('order_id', $order->id)->max('attempt_no') + 1;
        $externalReference = substr('FUL-'.$order->order_number.'-'.$attemptNo, 0, 120);
        $id = DB::table('fulfillment_attempts')->insertGetId([
            'order_id' => $order->id,
            'provider_mapping_id' => $mapping->id,
            'provider_id' => $mapping->provider_id,
            'attempt_no' => $attemptNo,
            'external_reference' => $externalReference,
            'status' => $status,
            'correlation_id' => $this->correlationId((int) $order->id),
            'safe_to_failover' => false,
            'last_error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('fulfillment_attempts')->where('id', $id)->first();
    }

    private function mapping(int $mappingId): ?object
    {
        return DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('mappings.id', $mappingId)
            ->select(
                'mappings.*',
                'providers.code as provider_code',
                'providers.fulfillment_mode as provider_mode',
                'providers.is_active as provider_active'
            )->first();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function mappingBlockReason(object $mapping, array $snapshot, ?int $orderId = null): ?string
    {
        if (! $mapping->is_active || ! $mapping->provider_active) {
            return 'Provider atau mapping sedang nonaktif.';
        }
        if (! in_array($mapping->provider_code, ['DIGIFLAZZ', 'VOUCHER_STOCK'], true)) {
            return 'Adapter penyedia belum tersedia untuk mapping ini.';
        }
        if (! is_string($mapping->external_sku) || trim($mapping->external_sku) === '') {
            return 'SKU penyedia belum tersedia.';
        }
        if ($mapping->provider_code === 'DIGIFLAZZ') {
            $item = DB::table('digiflazz_catalog_items')
                ->where('buyer_sku_code', $mapping->external_sku)
                ->first();
            if (! $item) {
                return 'SKU Digiflazz tidak lagi ditemukan pada katalog supplier.';
            }
            if (! $this->digiflazzCatalog->available($item)) {
                return 'SKU Digiflazz sedang nonaktif, stok habis, atau cut-off.';
            }
            if (! $this->digiflazzCatalog->verifiedIdentityMatches($item, $mapping->fulfillment_config)) {
                return 'Identitas produk SKU Digiflazz berubah. Periksa ulang mapping di panel admin.';
            }
            // The current seller price must never exceed the immutable
            // order snapshot cap. Ignore legacy mapping cost/lock fields:
            // checkout prices Digiflazz from the verified seller catalog.
            // Older orders retain their own historic cap in the snapshot.
            if ((int) $item->price_idr <= 0) {
                return 'Harga supplier Digiflazz belum tersedia.';
            }

            return null;
        }
        if ($mapping->provider_code === 'VOUCHER_STOCK') {
            $config = $this->json($mapping->fulfillment_config);
            $stockKey = trim((string) data_get($config, 'stock_key', ''));
            if ($stockKey === '') {
                return 'Kunci stok kode belum diatur.';
            }
            if ($orderId !== null) {
                // Kode yang sudah dipesan order ini tetap sah dipakai.
                if (! $this->voucherStock->availableFor($orderId, $stockKey)) {
                    return 'Stok kode untuk nominal ini habis.';
                }
            } elseif (! $this->voucherStock->available($stockKey)) {
                return 'Stok kode untuk nominal ini habis.';
            }
        }
        if ($mapping->cost_idr === null || (int) $mapping->cost_idr <= 0) {
            return 'Harga provider tidak valid.';
        }
        if ((int) $mapping->cost_idr > $this->maxPrice($snapshot)) {
            return 'Harga provider melewati batas max_price order.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function maxPrice(array $snapshot): int
    {
        $snapshotMax = (int) data_get($snapshot, 'provider.max_price_idr', 0);
        $snapshotCost = (int) data_get($snapshot, 'provider.cost_idr', 0);
        $maxPrice = $snapshotMax > 0 ? $snapshotMax : $snapshotCost;

        if ($maxPrice <= 0) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Batas max_price order tidak valid.',
            ]);
        }

        return $maxPrice;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    private function validateProviderResponse(array $request, array $response): void
    {
        foreach (['ref_id', 'buyer_sku_code', 'customer_no', 'status'] as $key) {
            if (! isset($response[$key]) || ! is_scalar($response[$key])) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Response provider tidak lengkap: '.$key.'.',
                ]);
            }
        }

        // Digiflazz may lowercase a SKU when checking status, but the other
        // transaction identities must remain byte-for-byte identical.
        $skuMatches = hash_equals(
            strtolower((string) $request['buyer_sku_code']),
            strtolower((string) $response['buyer_sku_code'])
        );
        if (! hash_equals((string) $request['ref_id'], (string) $response['ref_id'])
            || ! $skuMatches
            || ! hash_equals((string) $request['customer_no'], (string) $response['customer_no'])) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Identitas transaksi provider tidak cocok: '.(string) (
                    ! hash_equals((string) $request['ref_id'], (string) $response['ref_id']) ? 'ref_id'
                    : (! $skuMatches ? 'buyer_sku_code' : 'customer_no')
                ).'.',
            ]);
        }
    }

    private function providerStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'sukses', 'success' => 'SUCCESS',
            'gagal', 'failed', 'failure' => 'FAILED_CONFIRMED',
            'pending' => 'PENDING',
            default => 'UNKNOWN',
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function sanitizeProviderResponse(array $response): array
    {
        return collect($response)->only([
            'ref_id', 'customer_no', 'buyer_sku_code', 'message', 'status',
            'rc', 'sn', 'buyer_last_saldo', 'price',
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function correlationId(int $orderId): string
    {
        $root = DB::table('order_events')->where('order_id', $orderId)
            ->whereNotNull('correlation_id')->orderBy('id')->value('correlation_id');

        return is_string($root) && $root !== '' ? $root : (string) Str::uuid();
    }

    private function event(int $orderId, string $type, ?string $from, ?string $to, array $metadata): void
    {
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'correlation_id' => (string) Str::uuid(),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
