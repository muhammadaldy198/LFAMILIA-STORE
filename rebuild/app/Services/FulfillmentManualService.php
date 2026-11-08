<?php

namespace App\Services;

use App\Jobs\SendFulfillmentJob;
use App\Models\OrderSensitiveInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tindakan manual dan pemulihan oleh admin: menyelesaikan
 * karantina lama, mencoba lagi attempt yang aman, mengirim ulang
 * sesudah kegagalan provider yang pasti, serta menyelesaikan atau
 * menggagalkan attempt secara manual. Class ini TIDAK mengirim
 * attempt otomatis dan TIDAK menerapkan hasil provider.
 */
class FulfillmentManualService
{
    public function __construct(
        private readonly AdminNotificationService $notifications,
        private readonly TransactionalEmailService $emails,
        private readonly FulfillmentSupportService $support,
        private readonly FulfillmentSendingService $sending,
    ) {}

    private const DEFINITIVE_DIGIFLAZZ_FAILURE_CODES = [
        '02', '51', '52', '53', '54', '55', '57', '58',
        '59', '60', '71', '72', '73', '74', '84',
    ];

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
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan penyelesaian karantina yang transaksional —
        // attempt lama ditutup dan attempt pengganti dibuat dari
        // konteks attempt yang sama.
        // Only known final results of a transaction that WAS created
        // may authorize reissue after a superadmin verifies the history.
        // Reject 01/70 (timeouts), 49 (duplicate ref_id), 50 (not found)
        // and request-validation errors: none establishes the old result.
        if (! in_array($providerRc, self::DEFINITIVE_DIGIFLAZZ_FAILURE_CODES, true)) {
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
            if (! $legacy || ! $this->support->isLegacyQuarantined($legacy)) {
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

            $snapshot = $this->support->json($order->snapshot);
            if (data_get($snapshot, 'product.fulfillment_mode') !== 'AUTO_PROVIDER') {
                throw ValidationException::withMessages(['fulfillment' => 'Hanya pesanan pengiriman Digiflazz otomatis yang dapat dipulihkan.']);
            }
            $mapping = $this->support->mapping((int) $legacy->provider_mapping_id);
            if (! $mapping || $mapping->provider_code !== 'DIGIFLAZZ'
                || (int) $mapping->product_package_id !== (int) $order->product_package_id) {
                throw ValidationException::withMessages(['fulfillment' => 'Mapping Digiflazz warisan tidak tersedia.']);
            }
            $snapshotSku = (string) data_get($snapshot, 'provider.sku', '');
            if ($snapshotSku === '' || strcasecmp($snapshotSku, (string) $mapping->external_sku) !== 0) {
                throw ValidationException::withMessages(['fulfillment' => 'SKU pesanan berbeda dari mapping sekarang; tidak boleh mengganti SKU diam-diam.']);
            }
            $blockReason = $this->support->mappingBlockReason($mapping, $snapshot, (int) $order->id);
            if ($blockReason !== null) {
                throw ValidationException::withMessages(['fulfillment' => $blockReason]);
            }

            if (OrderSensitiveInput::where('order_id', $order->id)->exists()) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Karantina via-login tidak dapat diselesaikan lewat jalur ini.',
                ]);
            }

            $target = $this->sending->digiflazzCustomerNo($order, $mapping, $snapshot);
            if (! hash_equals($target, $confirmedCustomerNo)) {
                throw ValidationException::withMessages(['confirmed_customer_no' => 'Nomor tujuan tidak cocok dengan data pesanan.']);
            }

            $newAttempt = $this->support->createAttempt($order, $mapping, 'CREATED');
            DB::table('fulfillment_attempts')->where('id', $newAttempt->id)->update([
                'request_payload' => json_encode([
                    'buyer_sku_code' => $snapshotSku,
                    'customer_no' => $confirmedCustomerNo,
                    'ref_id' => $newAttempt->external_reference,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'PROCESSING',
                'updated_at' => now(),
            ]);
            $this->support->event((int) $order->id, 'FULFILLMENT_QUARANTINE_MANUALLY_RESOLVED', 'BLOCKED', 'PROCESSING', [
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
        // Alasan belum dipecah: satu pemeriksaan keamanan sebelum
        // pengiriman ulang dijadwalkan — attempt terbaru dan
        // keadaan karantina diperiksa dalam konteks attempt yang
        // sama dari awal sampai akhir.
        $sendAttemptId = DB::transaction(function () use ($attemptId): ?int {
            $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)
                ->lockForUpdate()->first();
            if (! $attempt) {
                return null;
            }
            $this->support->ensureLatestAttempt($attempt);

            if ($this->support->isLegacyQuarantined($attempt)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Attempt dari sebelum peralihan Production tidak boleh dikirim ulang.',
                ]);
            }

            if ($attempt->status === 'FAILED_CONFIRMED') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Attempt yang sudah terkonfirmasi gagal tidak dapat dicoba ulang. Periksa hasil penyedia dan syarat Kirim Ulang sebelum mengirim ulang.',
                ]);
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
            $this->support->event((int) $order->id, 'FULFILLMENT_RETRY_QUEUED', $order->status, 'PROCESSING', [
                'attempt_id' => $attempt->id,
            ]);

            return (int) $attempt->id;
        }, 3);

        $attempt = DB::table('fulfillment_attempts')->where('id', $attemptId)->first();

        if ($sendAttemptId !== null) {
            SendFulfillmentJob::dispatch($sendAttemptId)->afterCommit();
        }
    }

    /**
     * Open one new attempt after a provider-confirmed failure. Never switch
     * SKUs or retry any reference whose outcome is still uncertain.
     */
    public static function isDefinitiveProviderFailure(?string $status, ?string $rc): bool
    {
        return strcasecmp(trim((string) $status), 'Gagal') === 0
            && in_array(trim((string) $rc), self::DEFINITIVE_DIGIFLAZZ_FAILURE_CODES, true);
    }

    public function resendAfterConfirmedFailure(int $orderId, int $adminId): int
    {
        // Alasan belum dipecah: satu urutan kirim ulang sesudah
        // kegagalan pasti — kegagalan attempt terakhir dibuktikan
        // dulu, baru attempt pengganti dibuat dan dijadwalkan,
        // dalam konteks pesanan yang sama.
        $newAttemptId = DB::transaction(function () use ($orderId, $adminId): int {
            // An order lock serializes competing requests from two admins.
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'BLOCKED' || $order->paid_at === null) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Hanya pesanan terblokir yang sudah dibayar dapat dikirim ulang.',
                ]);
            }
            $latest = DB::table('fulfillment_attempts')->where('order_id', $orderId)
                ->orderByDesc('attempt_no')->lockForUpdate()->first();
            if (! $latest || $latest->status !== 'FAILED_CONFIRMED') {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Attempt terakhir belum terkonfirmasi gagal. Muat ulang halaman.',
                ]);
            }
            if (! self::isDefinitiveProviderFailure($latest->provider_status, $latest->provider_rc)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Kode respons atau status penyedia tidak membuktikan kegagalan final. Periksa riwayat Digiflazz terlebih dahulu.',
                ]);
            }
            if ($this->support->isLegacyQuarantined($latest)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Attempt karantina harus diselesaikan lewat resolusi khusus.',
                ]);
            }
            if (DB::table('fulfillment_attempts')->where('order_id', $orderId)
                ->whereIn('status', ['CREATED', 'QUEUED', 'SENDING', 'PENDING', 'UNKNOWN', 'SUCCESS'])->exists()) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Masih ada attempt berjalan, belum pasti, atau sukses. Tidak boleh mengirim ulang.',
                ]);
            }

            $snapshot = $this->support->json($order->snapshot);
            $mapping = $this->support->mapping((int) $latest->provider_mapping_id);
            if (data_get($snapshot, 'product.fulfillment_mode') !== 'AUTO_PROVIDER'
                || ! $mapping || $mapping->provider_code !== 'DIGIFLAZZ'
                || (int) $mapping->provider_id !== (int) $latest->provider_id
                || (int) $mapping->product_package_id !== (int) $order->product_package_id
                || ! is_string($mapping->external_sku)
                || trim($mapping->external_sku) === ''
                || strcasecmp((string) data_get($snapshot, 'provider.sku', ''), (string) $mapping->external_sku) !== 0) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'SKU atau mapping pesanan tidak cocok dengan sumber yang aktif.',
                ]);
            }
            if ($reason = $this->support->mappingBlockReason($mapping, $snapshot, $orderId)) {
                throw ValidationException::withMessages(['fulfillment' => $reason]);
            }

            $newAttempt = $this->support->createAttempt($order, $mapping, 'CREATED');
            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'PROCESSING', 'updated_at' => now(),
            ]);
            $this->support->event($orderId, 'FULFILLMENT_MANUAL_RESEND_QUEUED', 'BLOCKED', 'PROCESSING', [
                'previous_attempt_id' => (int) $latest->id,
                'previous_provider_rc' => $latest->provider_rc,
                'new_attempt_id' => (int) $newAttempt->id,
                'admin_id' => $adminId,
                'automatic_failover' => false,
            ]);

            return (int) $newAttempt->id;
        }, 3);

        SendFulfillmentJob::dispatch($newAttemptId)->afterCommit();

        return $newAttemptId;
    }

    public function completeManual(int $attemptId, ?string $deliveryCode, ?string $note, int $adminId): void
    {
        // Alasan belum dipecah: satu penyelesaian manual yang
        // transaksional — attempt ditutup sebagai terkirim beserta
        // event, notifikasi, dan emailnya sebagai satu kesatuan.
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
            $this->support->ensureLatestAttempt($attempt);

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
            $this->support->event((int) $order->id, 'MANUAL_FULFILLMENT_SUCCEEDED', $order->status, 'SUCCESS', [
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
            $this->support->ensureLatestAttempt($attempt);

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
            $this->support->event((int) $order->id, 'MANUAL_FULFILLMENT_FAILED', $order->status, 'FAILED', [
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
}
