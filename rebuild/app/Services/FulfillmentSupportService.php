<?php

namespace App\Services;

use App\Services\Fulfillment\DigiflazzClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kotak alat bersama pemenuhan pesanan: mencatat event dan korelasi,
 * membaca dan memeriksa mapping provider, membuat attempt, menandai
 * attempt terblokir atau tidak pasti, mengeskalasi rekonsiliasi yang
 * macet, dan memenuhi pesanan dari stok voucher. Class ini TIDAK
 * mengirim pesanan ke provider, TIDAK menerapkan hasil provider,
 * dan TIDAK menjalankan tindakan manual admin; ketiganya ada di
 * service pemenuhan masing-masing.
 */
class FulfillmentSupportService
{
    public function __construct(
        private readonly DigiflazzClient $digiflazz,
        private readonly DigiflazzCatalogService $digiflazzCatalog,
        private readonly VoucherStockService $voucherStock,
        private readonly AdminNotificationService $notifications,
        private readonly TransactionalEmailService $emails,
    ) {}

    public function event(int $orderId, string $type, ?string $from, ?string $to, array $metadata): void
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

    public function correlationId(int $orderId): string
    {
        $root = DB::table('order_events')->where('order_id', $orderId)
            ->whereNotNull('correlation_id')->orderBy('id')->value('correlation_id');

        return is_string($root) && $root !== '' ? $root : (string) Str::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    public function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded) && isset($decoded['encrypted']) && is_string($decoded['encrypted'])) {
            try {
                $decoded = json_decode(Crypt::decryptString($decoded['encrypted']), true);
            } catch (DecryptException) {
                return [];
            }
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persisted cutover quarantine must survive late queue responses and
     * callbacks without ever marking an unverified delivery as successful.
     */
    public function isLegacyQuarantined(object $attempt): bool
    {
        return $attempt->status === 'BLOCKED'
            && str_starts_with((string) ($attempt->last_error ?? ''), 'LEGACY_ENV_QUARANTINED:');
    }

    public function createAttempt(object $order, object $mapping, string $status, ?string $error = null): object
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

    public function mapping(int $mappingId): ?object
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
    public function mappingBlockReason(object $mapping, array $snapshot, ?int $orderId = null): ?string
    {
        // Alasan belum dipecah: satu definisi tunggal alasan
        // pemblokiran mapping — seluruh pemeriksaannya membaca
        // snapshot dan mapping yang sama dan hasilnya satu
        // keputusan; memisahkannya menyebar definisi itu.
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
    public function maxPrice(array $snapshot): int
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

    public function markBlocked(int $attemptId, string $reason): void
    {
        // Alasan belum dipecah: satu penandaan terblokir yang
        // transaksional — attempt dikunci, alasannya dicatat, dan
        // event serta notifikasinya ditulis sebagai satu kesatuan.
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

    public function markUnknown(int $attemptId, string $reason): void
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

    public function ensureLatestAttempt(object $attempt): void
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

    public function fulfillVoucherStock(int $attemptId, string $stockKey): void
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu pemenuhan stok yang transaksional — stok dikunci,
        // kode diambil, dan attempt ditutup beserta notifikasinya
        // dalam konteks attempt yang sama.
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

    public function recordReconciliationError(int $attemptId, string $reason): void
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
    public function escalateStalledReconciliation(object $attempt, object $order): void
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

    /**
     * Age-specific counterpart of escalateStalledReconciliation(): an
     * attempt older than the 90-day recheck bound will never be checked
     * again - automatically or manually - so the notice must say exactly
     * that instead of claiming checks are still running.
     */
    public function escalateAgedReconciliation(object $attempt, object $order): void
    {
        $alreadyEscalated = DB::table('admin_notifications')
            ->where('event_type', 'fulfillment.reconcile.too_old')
            ->where('target_type', 'fulfillment_attempt')
            ->where('target_id', (string) $attempt->id)
            ->exists();
        if ($alreadyEscalated) {
            return;
        }

        $this->event((int) $order->id, 'FULFILLMENT_RECONCILE_STALLED', $order->status, $order->status, [
            'attempt_id' => $attempt->id,
            'reconcile_checks' => (int) $attempt->reconcile_checks,
            'reason' => 'attempt_older_than_90_days',
        ]);
        $this->notifications->record(
            'fulfillment.reconcile.too_old',
            'Hasil pengiriman melewati batas umur pemeriksaan - perlu penanganan manual',
            'Pesanan '.$order->order_number.' mempunyai attempt pengiriman berumur lebih dari 90 hari yang hasilnya belum pasti. Pemeriksaan ulang tidak lagi dilakukan (otomatis maupun manual) karena Digiflazz akan membuat transaksi baru; periksa dan tangani manual dari menu Pemenuhan.',
            'ERROR',
            'fulfillment_attempt',
            $attempt->id,
            ['order_id' => (int) $order->id]
        );
    }

    public function blockedWithoutSend(object $order, object $mapping, string $reason): object
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
}
