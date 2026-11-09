<?php

namespace App\Services;

use App\Jobs\SendFulfillmentJob;
use App\Models\OrderSensitiveInput;
use App\Services\Fulfillment\DigiflazzClient;
use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use App\Services\Fulfillment\FulfillmentTargetBuilder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pengiriman pesanan ke provider: memulai pemenuhan pesanan yang
 * sudah dibayar, mengirim attempt ke Digiflazz, dan menyusun nomor
 * tujuan pelanggan. Class ini TIDAK merekonsiliasi attempt dan
 * TIDAK menjalankan tindakan manual admin.
 */
class FulfillmentSendingService
{
    public function __construct(
        private readonly DigiflazzClient $digiflazz,
        private readonly FulfillmentTargetBuilder $targetBuilder,
        private readonly AdminNotificationService $notifications,
        private readonly FulfillmentSupportService $support,
        private readonly FulfillmentResultService $result,
    ) {}

    public function startOrder(int $orderId): void
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan pembukaan pemenuhan yang transaksional —
        // attempt dibuat dan antrean pengiriman dijadwalkan dari
        // konteks pesanan yang sama.
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

            $snapshot = $this->support->json($order->snapshot);
            $mappingId = (int) ($order->provider_mapping_id ?: data_get($snapshot, 'provider.mapping_id'));
            $mapping = $this->support->mapping($mappingId);
            if (! $mapping) {
                return null;
            }

            $mode = (string) data_get($snapshot, 'product.fulfillment_mode', '');
            if ($mode === 'MANUAL') {
                $attempt = $this->support->createAttempt($order, $mapping, 'MANUAL_PENDING');
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'PROCESSING',
                    'updated_at' => now(),
                ]);
                $this->support->event((int) $order->id, 'MANUAL_FULFILLMENT_QUEUED', $order->status, 'PROCESSING', [
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
                $this->support->blockedWithoutSend($order, $mapping, 'Mode fulfillment order tidak didukung.');

                return null;
            }

            $blockReason = $this->support->mappingBlockReason($mapping, $snapshot, (int) $order->id);
            if ($blockReason !== null) {
                // The order is locked to its selected SKU. No automatic
                // switch is allowed, even before the first provider request.
                $this->support->blockedWithoutSend($order, $mapping, $blockReason);

                return null;
            }

            $attempt = $this->support->createAttempt($order, $mapping, 'CREATED');
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'PROCESSING',
                'updated_at' => now(),
            ]);
            $this->support->event((int) $order->id, 'FULFILLMENT_QUEUED', $order->status, 'PROCESSING', [
                'attempt_id' => $attempt->id,
            ]);

            return (int) $attempt->id;
        }, 3);

        if ($attemptId !== null) {
            SendFulfillmentJob::dispatch($attemptId)->afterCommit();
        }
    }

    /**
     * Prepare an immutable provider request and encrypt persisted credential data.
     */
    public function sendAttempt(int $attemptId): void
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan pengiriman yang transaksional — konteks
        // attempt dikunci, panggilan provider dilakukan, dan
        // hasilnya diterapkan, dalam satu konteks attempt yang sama.
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

                $snapshot = $this->support->json($order->snapshot);
                $mapping = $this->support->mapping((int) $attempt->provider_mapping_id);
                if (! $mapping) {
                    throw ValidationException::withMessages(['fulfillment' => 'Mapping provider tidak ditemukan.']);
                }

                $blockReason = $this->support->mappingBlockReason($mapping, $snapshot, (int) $order->id);
                if ($blockReason !== null) {
                    throw ValidationException::withMessages(['fulfillment' => $blockReason]);
                }

                $config = $this->support->json($mapping->fulfillment_config);
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

                // Recovery attempts freeze a superadmin-confirmed destination
                // before enqueueing. Never silently rebuild it from editable
                // product/mapping templates after that confirmation.
                if ($attempt->request_payload !== null) {
                    $stored = $this->support->json($attempt->request_payload);
                    foreach (['buyer_sku_code', 'customer_no', 'ref_id'] as $key) {
                        if (! isset($stored[$key]) || ! is_string($stored[$key]) || $stored[$key] === '') {
                            throw ValidationException::withMessages(['fulfillment' => 'Payload attempt tersimpan tidak valid.']);
                        }
                    }
                    if (! hash_equals((string) $attempt->external_reference, $stored['ref_id'])
                        || strcasecmp((string) $mapping->external_sku, $stored['buyer_sku_code']) !== 0
                        || strcasecmp((string) data_get($snapshot, 'provider.sku', ''), $stored['buyer_sku_code']) !== 0) {
                        throw ValidationException::withMessages(['fulfillment' => 'Identitas request attempt tidak cocok dengan SKU dan referensi order.']);
                    }
                    $request = [
                        'buyer_sku_code' => $stored['buyer_sku_code'],
                        'customer_no' => $stored['customer_no'],
                        'ref_id' => $stored['ref_id'],
                    ];
                } else {
                    $request = [
                        'buyer_sku_code' => (string) $mapping->external_sku,
                        'customer_no' => $this->digiflazzCustomerNo($order, $mapping, $snapshot),
                        'ref_id' => (string) $attempt->external_reference,
                    ];
                }

                $storedRequest = json_encode($request, JSON_THROW_ON_ERROR);
                if (OrderSensitiveInput::where('order_id', $order->id)->exists()) {
                    $storedRequest = json_encode(['encrypted' => Crypt::encryptString($storedRequest)], JSON_THROW_ON_ERROR);
                }
                DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                    'status' => 'SENDING',
                    'request_payload' => $storedRequest,
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
            $this->support->markBlocked($attemptId, $exception->getMessage());

            return;
        }

        if ($context === null) {
            return;
        }

        // Gerbang terakhir sebelum panggilan provider: transaksi persiapan di
        // atas sudah commit, jadi pembatalan yang mendarat di celah ini tidak
        // terlihat oleh pemeriksaan status di dalamnya.
        if ($this->support->attemptCancelled($attemptId)) {
            return;
        }

        if ($context['provider_code'] === 'VOUCHER_STOCK') {
            try {
                $this->support->fulfillVoucherStock($context['attempt_id'], $context['stock_key']);
            } catch (ValidationException $exception) {
                $this->support->markBlocked($attemptId, $exception->getMessage());
            }

            return;
        }

        try {
            $response = $this->digiflazz->transact($context['request'], $attemptId);
        } catch (ValidationException $exception) {
            $this->support->markBlocked($attemptId, $exception->getMessage());

            return;
        } catch (RuntimeException $exception) {
            $this->support->markUnknown($attemptId, 'Provider request tidak dapat dipastikan.');

            return;
        }

        try {
            $this->result->applyProviderResult($attemptId, $response, 'provider_response');
        } catch (ValidationException $exception) {
            Log::warning('Digiflazz initial response rejected', [
                'fulfillment_attempt_id' => $attemptId,
                'reason' => $exception->getMessage(),
            ]);
            $this->support->markUnknown($attemptId, 'Response provider tidak dapat diverifikasi.');
        }
    }

    /**
     * Compute the exact Digiflazz destination used by normal sends and
     * quarantine approval, including the historical mapping fallback.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function digiflazzCustomerNo(object $order, object $mapping, array $snapshot): string
    {
        $config = $this->support->json($mapping->fulfillment_config);
        $product = DB::table('products')->where('id', $order->product_id)
            ->first(['slug', 'customer_no_template']);

        if ($product) {
            if (array_key_exists('customer_no_template', $snapshot['provider'] ?? [])) {
                $config['customer_no_template'] = $snapshot['provider']['customer_no_template'];
            } else {
                $config['customer_no_template'] = $product->customer_no_template
                    ?: ($config['customer_no_template'] ?? null);
            }
        }

        $snapshotTemplate = (string) ($config['customer_no_template'] ?? '');
        // Prefer the frozen template's fields when optional input is empty.
        preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $snapshotTemplate, $matches);
        $input = $this->support->json($order->customer_input);
        $secretKeys = [];
        foreach (OrderSensitiveInput::where('order_id', $order->id)->get() as $secret) {
            $input[$secret->field_key] = $secret->value;
            $secretKeys[] = $secret->field_key;
        }
        $config['raw_field_keys'] = $secretKeys;
        $keys = $matches[1] ?: array_keys($input);
        $config['customer_no_template'] = app(DigiflazzDestinationTemplate::class)->resolve(
            (string) data_get($snapshot, 'product.slug', $product?->slug ?? ''),
            $snapshotTemplate !== '' ? $snapshotTemplate : null,
            $keys
        );

        return $this->targetBuilder->customerNo($input, $config);
    }
}
