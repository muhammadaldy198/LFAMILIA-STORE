<?php

namespace App\Services;

use App\Jobs\SendFulfillmentJob;
use App\Services\Fulfillment\DigiflazzClient;
use App\Services\Fulfillment\FulfillmentTargetBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FulfillmentService
{
    public function __construct(
        private readonly DigiflazzClient $digiflazz,
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
                    'Fulfillment manual menunggu',
                    'Order '.$order->order_number.' menunggu proses manual.',
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

            $blockReason = $this->mappingBlockReason($mapping, $snapshot);
            if ($blockReason !== null) {
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

                $blockReason = $this->mappingBlockReason($mapping, $snapshot);
                if ($blockReason !== null) {
                    throw ValidationException::withMessages(['fulfillment' => $blockReason]);
                }

                $config = $this->json($mapping->fulfillment_config);
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
                $maxPrice = $this->maxPrice($snapshot);

                $request = [
                    'buyer_sku_code' => (string) $mapping->external_sku,
                    'customer_no' => $customerNo,
                    'ref_id' => (string) $attempt->external_reference,
                    'max_price' => $maxPrice,
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
            $response = $this->digiflazz->transact($context['request']);
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
            $this->markUnknown($attemptId, 'Response provider tidak dapat diverifikasi.');
        }
    }

    public function reconcileAttempt(int $attemptId): void
    {
        $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)->first();
        if (! $attempt || ! in_array($attempt->status, ['PENDING', 'UNKNOWN', 'SENDING'], true)) {
            return;
        }
        if (DB::table('fulfillment_attempts')
            ->where('order_id', $attempt->order_id)
            ->where('id', '>', $attempt->id)
            ->exists()) {
            return;
        }

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
            $response = $this->digiflazz->transact($request);
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
            $this->markUnknown($attemptId, 'Hasil reconciliation tidak dapat diverifikasi.');
        }
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

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['fulfillment' => 'Order fulfillment tidak ditemukan.']);
            }

            $request = $this->json($attempt->request_payload);
            $this->validateProviderResponse($request, $response);

            $incoming = $this->providerStatus((string) $response['status']);
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
                    'Order berhasil',
                    'Order '.$order->order_number.' selesai diproses.',
                    'INFO',
                    'order',
                    $order->id
                );
                $this->emails->queueForOrder(
                    (int) $order->id,
                    'Pesanan LFAMILIA berhasil',
                    'Order '.$order->order_number.' telah berhasil diproses.'
                );

                if ($price !== null && $price > (int) ($request['max_price'] ?? PHP_INT_MAX)) {
                    $this->event((int) $order->id, 'FULFILLMENT_PRICE_GUARD_BREACH', 'SUCCESS', 'SUCCESS', [
                        'attempt_id' => $attempt->id,
                    ]);
                }

                return false;
            }

            if ($incoming === 'FAILED_CONFIRMED') {
                DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                    ...$common,
                    'status' => 'FAILED_CONFIRMED',
                    'safe_to_failover' => true,
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
                    'Fulfillment order '.$order->order_number.' gagal dan baru boleh failover dari state ini.',
                    'ERROR',
                    'fulfillment_attempt',
                    $attempt->id,
                    ['order_id' => (int) $order->id]
                );

                return true;
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
                    'Fulfillment '.$incoming,
                    'Order '.$order->order_number.' memerlukan reconciliation dengan reference yang sama.',
                    'WARNING',
                    'fulfillment_attempt',
                    $attempt->id,
                    ['order_id' => (int) $order->id]
                );
            }

            return false;
        }, 3);

        if ($failover) {
            $this->failoverIfSafe($attemptId);
        }
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

            if ($attempt->status === 'FAILED_CONFIRMED' && $attempt->safe_to_failover) {
                return null;
            }
            if ($attempt->status !== 'BLOCKED') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Hanya proses tertahan yang belum terkirim yang dapat dicoba ulang.',
                ]);
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order || in_array($order->status, ['SUCCESS', 'REFUND'], true)) {
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
        if ($attempt?->status === 'FAILED_CONFIRMED' && $attempt->safe_to_failover) {
            $this->failoverIfSafe($attemptId);

            return;
        }

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
            $this->emails->queueForOrder(
                (int) $order->id,
                'Pesanan LFAMILIA berhasil',
                'Order '.$order->order_number.' telah berhasil diproses.'
            );
        }, 3);
    }

    public function completeManual(int $attemptId, ?string $deliveryCode, ?string $note, int $adminId): void
    {
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
            $this->emails->queueForOrder(
                (int) $order->id,
                'Pesanan LFAMILIA berhasil',
                'Order '.$order->order_number.' telah berhasil diproses.'
            );
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
        }, 3);
    }

    private function failoverIfSafe(int $failedAttemptId): void
    {
        $nextAttemptId = DB::transaction(function () use ($failedAttemptId): ?int {
            $failed = DB::table('fulfillment_attempts')->where('id', $failedAttemptId)
                ->lockForUpdate()->first();
            if (! $failed || $failed->status !== 'FAILED_CONFIRMED' || ! $failed->safe_to_failover) {
                return null;
            }

            $order = DB::table('orders')->where('id', $failed->order_id)->lockForUpdate()->first();
            if (! $order || in_array($order->status, ['SUCCESS', 'REFUND'], true)) {
                return null;
            }

            $newerAttempt = DB::table('fulfillment_attempts')
                ->where('order_id', $order->id)
                ->where('attempt_no', '>', $failed->attempt_no)
                ->exists();
            if ($newerAttempt) {
                return null;
            }

            $snapshot = $this->json($order->snapshot);
            $maxPrice = $this->maxPrice($snapshot);
            $attemptedMappings = DB::table('fulfillment_attempts')
                ->where('order_id', $order->id)->pluck('provider_mapping_id');

            $mapping = DB::table('provider_mappings as mappings')
                ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                ->where('mappings.product_package_id', $order->product_package_id)
                ->where('mappings.is_active', true)
                ->where('providers.is_active', true)
                ->where('providers.fulfillment_mode', 'AUTO_PROVIDER')
                ->where('providers.code', 'DIGIFLAZZ')
                ->whereNotNull('mappings.external_sku')
                ->whereNotNull('mappings.cost_idr')
                ->where('mappings.cost_idr', '>', 0)
                ->where('mappings.cost_idr', '<=', $maxPrice)
                ->whereNotIn('mappings.id', $attemptedMappings)
                ->orderBy('mappings.priority')
                ->orderBy('mappings.cost_idr')
                ->orderBy('mappings.id')
                ->select('mappings.*', 'providers.code as provider_code', 'providers.is_active as provider_active')
                ->first();

            if (! $mapping) {
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'FAILED',
                    'updated_at' => now(),
                ]);
                $this->event((int) $order->id, 'FULFILLMENT_EXHAUSTED', $order->status, 'FAILED', [
                    'attempt_id' => $failed->id,
                ]);

                return null;
            }

            $attempt = $this->createAttempt($order, $mapping, 'CREATED');
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'PROCESSING',
                'updated_at' => now(),
            ]);
            $this->event((int) $order->id, 'FULFILLMENT_FAILOVER_QUEUED', $order->status, 'PROCESSING', [
                'from_attempt_id' => $failed->id,
                'attempt_id' => $attempt->id,
            ]);

            return (int) $attempt->id;
        }, 3);

        if ($nextAttemptId !== null) {
            SendFulfillmentJob::dispatch($nextAttemptId)->afterCommit();
        }
    }

    private function markBlocked(int $attemptId, string $reason): void
    {
        DB::transaction(function () use ($attemptId, $reason): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || in_array($attempt->status, ['SUCCESS', 'FAILED_CONFIRMED'], true)) {
                return;
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'BLOCKED',
                'safe_to_failover' => false,
                'last_error' => substr($reason, 0, 4000),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
            if ($order && $order->status === 'PROCESSING') {
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'PAID',
                    'updated_at' => now(),
                ]);
                $this->event((int) $order->id, 'FULFILLMENT_BLOCKED', 'PROCESSING', 'PAID', [
                    'attempt_id' => $attempt->id,
                ]);
            }
        }, 3);
    }

    private function markUnknown(int $attemptId, string $reason): void
    {
        DB::transaction(function () use ($attemptId, $reason): void {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt || in_array($attempt->status, ['SUCCESS', 'FAILED_CONFIRMED'], true)) {
                return;
            }

            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'UNKNOWN',
                'safe_to_failover' => false,
                'last_error' => substr($reason, 0, 4000),
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
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

    private function recordReconciliationError(int $attemptId, string $reason): void
    {
        DB::table('fulfillment_attempts')->where('id', $attemptId)->update([
            'last_error' => substr($reason, 0, 4000),
            'last_checked_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function blockedWithoutSend(object $order, object $mapping, string $reason): void
    {
        $attempt = $this->createAttempt($order, $mapping, 'BLOCKED', $reason);
        $this->event((int) $order->id, 'FULFILLMENT_BLOCKED', $order->status, $order->status, [
            'attempt_id' => $attempt->id,
        ]);
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
    private function mappingBlockReason(object $mapping, array $snapshot): ?string
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
        if ($mapping->provider_code === 'VOUCHER_STOCK') {
            $config = $this->json($mapping->fulfillment_config);
            $stockKey = trim((string) data_get($config, 'stock_key', ''));
            if ($stockKey === '') {
                return 'Kunci stok kode belum diatur.';
            }
            if (! $this->voucherStock->available($stockKey)) {
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
                    'fulfillment' => 'Response provider tidak lengkap.',
                ]);
            }
        }

        if (! hash_equals((string) $request['ref_id'], (string) $response['ref_id'])
            || ! hash_equals((string) $request['buyer_sku_code'], (string) $response['buyer_sku_code'])
            || ! hash_equals((string) $request['customer_no'], (string) $response['customer_no'])) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Identitas transaksi provider tidak cocok.',
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
