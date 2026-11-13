<?php

namespace App\Services;

use App\Models\OrderSensitiveInput;
use App\Services\Fulfillment\DigiflazzClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Hasil dan rekonsiliasi provider: menerapkan jawaban provider ke
 * attempt dan pesanan, memeriksa ulang attempt yang belum tuntas
 * ke provider, dan mengeskalasi rekonsiliasi yang macet terlalu
 * lama. Class ini TIDAK mengirim attempt baru dan TIDAK
 * menjalankan tindakan manual admin.
 */
class FulfillmentResultService
{
    public function __construct(
        private readonly DigiflazzClient $digiflazz,
        private readonly AdminNotificationService $notifications,
        private readonly TransactionalEmailService $emails,
        private readonly FulfillmentSupportService $support,
    ) {}

    public function reconcileAttempt(int $attemptId, bool $manual = false): void
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan rekonsiliasi — attempt dikunci, keadaan
        // provider ditanyakan, dan hasilnya diterapkan atau
        // dieskalasi, dalam satu konteks attempt yang sama.
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

            // Age stop: automatic checks give up after a day. Manual checks
            // get the repository's absolute bound instead — never re-check a
            // transaction older than 90 days, because Digiflazz treats the
            // re-post as a brand-new transaction (AGENTS.md, PRD). An aged
            // attempt is escalated for a human, never re-posted.
            $oldestRecheckable = $manual ? now()->subDays(90) : now()->subDay();
            if (Carbon::parse($attempt->created_at)->lte($oldestRecheckable)) {
                if ($manual) {
                    $this->support->escalateAgedReconciliation($attempt, $order);
                } else {
                    $this->support->escalateStalledReconciliation($attempt, $order);
                }

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
            $mapping = $this->support->mapping((int) $attempt->provider_mapping_id);
            if ($mapping?->provider_code === 'VOUCHER_STOCK') {
                $config = $this->support->json($mapping->fulfillment_config);
                $stockKey = trim((string) data_get($config, 'stock_key', ''));
                try {
                    $this->support->fulfillVoucherStock($attemptId, $stockKey);
                } catch (ValidationException $exception) {
                    $this->support->markBlocked($attemptId, $exception->getMessage());
                }

                return;
            }

            $request = $this->support->json($attempt->request_payload);
            if ($request === []) {
                $this->support->markUnknown($attemptId, 'Payload reconciliation tidak tersedia.');

                return;
            }

            // Gerbang yang sama seperti pengiriman awal: klaim rekonsiliasi
            // sudah commit, jadi pembatalan bisa mendarat di celah sebelum
            // panggilan ini dan tidak boleh tetap memicu transaksi provider.
            if ($this->support->attemptCancelled($attemptId)) {
                return;
            }

            try {
                $response = $this->digiflazz->transact($request, $attemptId);
            } catch (ValidationException $exception) {
                $this->support->recordReconciliationError($attemptId, 'Integrasi provider belum siap untuk reconciliation.');

                return;
            } catch (RuntimeException $exception) {
                $this->support->markUnknown($attemptId, 'Reconciliation provider belum dapat dipastikan.');

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
                $this->support->markUnknown($attemptId, 'Hasil reconciliation tidak dapat diverifikasi.');
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
     * @param  array<string, mixed>  $response
     */
    public function applyProviderResult(int $attemptId, array $response, string $source): void
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu mesin keadaan hasil provider — status lama dan baru
        // dibandingkan lalu seluruh imbasnya (pesanan, event,
        // notifikasi, email) diterapkan sebagai satu kesatuan;
        // memisahkannya menyebar satu keputusan keadaan ke banyak
        // tempat.
        $failover = DB::transaction(function () use ($attemptId, $response, $source): bool {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt) {
                throw ValidationException::withMessages(['fulfillment' => 'Fulfillment attempt tidak ditemukan.']);
            }

            // A pre-cutover response must never undo a quarantined attempt,
            // including an authenticated webhook arriving after migration.
            if ($this->support->isLegacyQuarantined($attempt)) {
                return false;
            }

            $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['fulfillment' => 'Order fulfillment tidak ditemukan.']);
            }

            $request = $this->support->json($attempt->request_payload);
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
            if ($attempt->status === 'SUCCESS' || $order->status === 'SUCCESS') {
                return false;
            }
            if ($attempt->status === 'FAILED_CONFIRMED') {
                if ($incoming !== 'FAILED_CONFIRMED') {
                    $this->support->event((int) $order->id, 'FULFILLMENT_CONFLICT_IGNORED', $order->status, $order->status, [
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
            $providerStatus = substr((string) $response['status'], 0, 40);

            // Providers can echo customer_no (including via-login credentials)
            // in any free-text field; redact those echoes before persistence.
            $secrets = OrderSensitiveInput::where('order_id', $order->id)->get()
                ->pluck('value')->filter(fn ($value) => is_string($value) && $value !== '')->all();
            $safeResponse = $this->sanitizeProviderResponse($response);
            $safeMessage = isset($response['message']) ? (string) $response['message'] : null;
            if ($secrets !== []) {
                unset($safeResponse['customer_no'], $safeResponse['message']);
                $safeMessage = $safeMessage === null ? null : str_replace($secrets, '[REDACTED]', $safeMessage);
                foreach ($safeResponse as $key => $value) {
                    if (is_string($value)) {
                        $safeResponse[$key] = str_replace($secrets, '[REDACTED]', $value);
                    }
                }
                $serial = $serial === null ? null : str_replace($secrets, '[REDACTED]', $serial);

                // Known protocol values are independent of echoed credentials.
                // Normalize them instead of substring-redacting status or response codes.
                $providerStatus = match ($this->providerStatus((string) $response['status'])) {
                    'SUCCESS' => 'Sukses',
                    'FAILED_CONFIRMED' => 'Gagal',
                    'PENDING' => 'Pending',
                    default => str_replace($secrets, '[REDACTED]', $providerStatus),
                };
                $providerRc = preg_match('/^[0-9]{2}$/', $responseCode)
                    ? $responseCode
                    : ($providerRc === null ? null : str_replace($secrets, '[REDACTED]', $providerRc));
                $safeResponse['status'] = $providerStatus;
                if (array_key_exists('rc', $safeResponse)) {
                    $safeResponse['rc'] = $providerRc;
                }
            }

            $common = [
                'provider_status' => $providerStatus,
                'provider_rc' => $providerRc,
                'serial_number' => $serial !== '' ? $serial : null,
                'price_idr' => $price,
                'response_payload' => json_encode($safeResponse, JSON_THROW_ON_ERROR),
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
                $this->support->event((int) $order->id, 'FULFILLMENT_SUCCEEDED', $order->status, 'SUCCESS', [
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
                    'last_error' => $safeMessage === null ? null : substr($safeMessage, 0, 1000),
                    'completed_at' => now(),
                ]);
                $this->support->event((int) $order->id, 'FULFILLMENT_FAILED_CONFIRMED', $order->status, $order->status, [
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
                $this->support->event((int) $order->id, 'FULFILLMENT_EXHAUSTED', $order->status, 'BLOCKED', [
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
            $this->support->event((int) $order->id, 'FULFILLMENT_'.$incoming, $order->status, 'PROCESSING', [
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

        $this->support->escalateStalledReconciliation($attempt, $order);
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
}
