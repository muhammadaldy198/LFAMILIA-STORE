<?php

namespace App\Services;

use App\Exceptions\CheckoutValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DigiflazzFulfillmentService
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly TransactionNotificationService $notifications,
        private readonly IntegrationConfigService $integrations,
        private readonly VoucherStockService $vouchers,
    ) {
    }

    public function fulfillOrder(string $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order || $order->payment_status !== 'paid' || $order->fulfillment_type !== 'automatic') {
            return;
        }

        $providerCode = strtolower(trim((string) $order->provider_code));
        if ($providerCode === 'voucher-stock') {
            $this->vouchers->fulfillOrder($orderId);
            return;
        }
        if ($providerCode !== 'digiflazz') {
            return;
        }

        if (!trim((string) $order->provider_sku) || !trim((string) $order->customer_no)) {
            $this->setError($orderId, 'Provider, SKU, atau format tujuan belum lengkap.');
            return;
        }

        $item = $this->checkout->resolveItem((string) $order->product_slug, (string) $order->package_sku);
        try {
            if (!$item
                || $item['providerCode'] !== 'digiflazz'
                || $item['providerSku'] !== trim((string) $order->provider_sku)) {
                throw new CheckoutValidationException('Nominal tidak lagi sesuai dengan konfigurasi provider.');
            }
            $this->checkout->assertPurchasable($item, max(1, (int) $order->quantity));
        } catch (CheckoutValidationException $error) {
            $this->setError(
                $orderId,
                'Nominal tidak lagi tersedia atau harga provider melebihi Max Price saat fulfillment. '.$error->getMessage(),
            );
            return;
        }

        if (max(1, (int) $order->quantity) > 1) {
            $this->fulfillUnits($order);
            return;
        }

        if (!$this->claimOrder($orderId)) {
            return;
        }

        try {
            $result = $this->send(
                trim((string) $order->provider_sku),
                trim((string) $order->customer_no),
                (string) $order->reference_id,
            );
            $this->applyOrderResult($orderId, $result);
            if ($result['status'] === 'success') {
                $this->notifications->notifyOrderSuccessById($orderId);
            }
        } catch (Throwable $error) {
            $this->setRetryableError($orderId, $error->getMessage() ?: 'Provider gagal dihubungi.');
        }
    }

    /**
     * Explicit admin recovery for a paid DigiFlazz order that ended in a failure/review state.
     * The original LFAMILIA reference_id is reused so the provider sees the same idempotency key.
     *
     * @return object|null
     */
    public function retryFailedOrder(string $orderId, string $adminEmail): ?object
    {
        DB::transaction(function () use ($orderId, $adminEmail): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                throw new RuntimeException('Pesanan tidak ditemukan.');
            }
            if ($order->payment_status !== 'paid'
                || $order->fulfillment_type !== 'automatic'
                || strtolower(trim((string) $order->provider_code)) !== 'digiflazz') {
                throw new RuntimeException('Hanya pesanan DigiFlazz otomatis yang sudah dibayar yang dapat dikirim ulang.');
            }
            if (in_array($order->fulfillment_status, ['success', 'cancelled'], true)
                || in_array($order->provider_status, ['success'], true)) {
                throw new RuntimeException('Pesanan sudah selesai dan tidak boleh dikirim ulang.');
            }

            $retryable = in_array((string) $order->fulfillment_status, ['failed', 'needs_review'], true)
                || in_array((string) $order->provider_status, ['failed', 'retry_exhausted', 'retryable_error', 'error', 'unknown'], true);
            if (!$retryable) {
                throw new RuntimeException('Pesanan belum berada pada status gagal yang membutuhkan kirim ulang.');
            }

            $retryUnits = [];
            if ((int) $order->quantity > 1) {
                $units = DB::table('order_fulfillment_units')
                    ->where('order_id', $orderId)
                    ->orderBy('unit_index')
                    ->lockForUpdate()
                    ->get();
                if ($units->count() !== (int) $order->quantity) {
                    throw new RuntimeException('Unit pemenuhan DigiFlazz tidak lengkap; periksa pesanan sebelum kirim ulang.');
                }
                foreach ($units as $unit) {
                    if (in_array($unit->provider_status, ['failed', 'retry_exhausted'], true)) {
                        $retryUnits[] = [
                            'unitIndex' => (int) $unit->unit_index,
                            'referenceId' => (string) $unit->provider_ref_id,
                            'previousStatus' => (string) $unit->provider_status,
                            'previousMessage' => $unit->provider_message,
                        ];
                        DB::table('order_fulfillment_units')->where('id', $unit->id)->update([
                            'provider_status' => 'waiting',
                            'provider_message' => null,
                            'attempts' => 0,
                            'updated_at' => now(),
                        ]);
                    }
                }
                if ($retryUnits === [] && !$units->contains(
                    fn ($unit) => in_array($unit->provider_status, ['waiting', 'retryable_error'], true)
                )) {
                    throw new RuntimeException('Tidak ada unit gagal yang dapat dikirim ulang.');
                }
            }

            $changed = DB::table('orders')
                ->where('id', $orderId)
                ->where('payment_status', 'paid')
                ->whereNotIn('fulfillment_status', ['success', 'cancelled'])
                ->update([
                    'fulfillment_status' => 'processing',
                    'provider_status' => null,
                    'provider_message' => 'Kirim ulang DigiFlazz diizinkan oleh '.$adminEmail.'.',
                    'updated_at' => now(),
                ]);

            if ($changed !== 1) {
                throw new RuntimeException('Status pesanan berubah. Muat ulang sebelum mencoba lagi.');
            }

            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'source' => 'admin',
                'event_id' => 'admin-retry-'.$orderId.'-'.Str::uuid(),
                'status' => 'retry_authorized',
                'payload_json' => json_encode([
                    'adminEmail' => $adminEmail,
                    'providerCode' => 'digiflazz',
                    'referenceId' => (string) $order->reference_id,
                    'previousFulfillmentStatus' => (string) $order->fulfillment_status,
                    'previousProviderStatus' => $order->provider_status,
                    'previousProviderMessage' => $order->provider_message,
                    'units' => $retryUnits,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }, 3);

        try {
            $this->fulfillOrder($orderId);
        } finally {
            $result = DB::table('orders')->where('id', $orderId)->first();
            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'source' => 'admin',
                'event_id' => 'admin-retry-result-'.$orderId.'-'.Str::uuid(),
                'status' => 'retry_result',
                'payload_json' => json_encode([
                    'adminEmail' => $adminEmail,
                    'fulfillmentStatus' => $result?->fulfillment_status,
                    'providerStatus' => $result?->provider_status,
                    'providerMessage' => $result?->provider_message,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }

        return DB::table('orders')->where('id', $orderId)->first();
    }

    /** @return array{externalId:string,status:string,message:string,serialNumber:?string,raw:array<string,mixed>} */
    public function send(string $providerSku, string $customerNo, string $referenceId): array
    {
        $config = $this->runtimeConfig();
        $body = [
            'username' => $config['username'],
            'buyer_sku_code' => $providerSku,
            'customer_no' => $customerNo,
            'ref_id' => $referenceId,
            'sign' => md5($config['username'].$config['apiKey'].$referenceId),
            'testing' => $config['environment'] === 'development',
            'cb_url' => rtrim((string) config('lfamilia.public_base_url'), '/').'/api/fulfillment/digiflazz/callback',
        ];
        if (str_contains($customerNo, '.')) {
            $body['allow_dot'] = true;
        }

        $response = DigiflazzEndpoint::request()
            ->timeout(15)
            ->post($config['transactionUrl'], $body);

        $payload = $response->json();
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            throw new RuntimeException('DigiFlazz tidak memberikan jawaban transaksi yang valid.');
        }

        $data = $payload['data'];
        if (!$response->successful()) {
            throw new RuntimeException($this->providerMessage($data, 'DigiFlazz menolak transaksi.'));
        }

        if (trim((string) ($data['ref_id'] ?? '')) !== $referenceId) {
            throw new RuntimeException('Ref ID jawaban DigiFlazz tidak cocok dengan order LFAMILIA.');
        }
        if (trim((string) ($data['buyer_sku_code'] ?? '')) !== $providerSku) {
            throw new RuntimeException('SKU jawaban DigiFlazz tidak cocok dengan order LFAMILIA.');
        }

        return [
            'externalId' => $referenceId,
            'status' => $this->mapStatus(
                (string) ($data['status'] ?? ''),
                (string) ($data['rc'] ?? ''),
            ),
            'message' => $this->providerMessage(
                $data,
                'Status DigiFlazz: '.((string) ($data['status'] ?? $data['rc'] ?? 'tidak diketahui')),
            ),
            'serialNumber' => trim((string) ($data['sn'] ?? '')) ?: null,
            'raw' => $payload,
        ];
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        $secret = $this->integrations->digiflazzRuntime()['webhookSecret'];
        if ($secret === '' || !$signature) {
            return false;
        }

        $expected = 'sha1='.hash_hmac('sha1', $rawBody, $secret);

        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    /**
     * @param array{externalId:string,status:string,message:string,serialNumber:?string,raw:array<string,mixed>} $result
     */
    public function applyWebhook(string $providerRefId, string $eventId, array $result): bool
    {
        $changed = DB::transaction(function () use ($providerRefId, $eventId, $result): bool {
            $unit = DB::table('order_fulfillment_units')
                ->where('provider_ref_id', $providerRefId)
                ->lockForUpdate()
                ->first();

            if ($unit) {
                $parent = DB::table('orders')->where('id', $unit->order_id)->lockForUpdate()->first();
                if (!$parent || $parent->payment_status !== 'paid') {
                    return false;
                }

                if (DB::table('order_events')->where('source', 'digiflazz')->where('event_id', $eventId)->exists()) {
                    return false;
                }

                $changed = 0;
                if (!in_array($unit->provider_status, ['success', 'failed', 'retry_exhausted'], true)) {
                    $changed = DB::table('order_fulfillment_units')->where('id', $unit->id)->update([
                        'provider_status' => $result['status'],
                        'provider_message' => $result['message'],
                        'provider_serial_number' => $result['serialNumber'] ?: $unit->provider_serial_number,
                        'updated_at' => now(),
                    ]);
                }

                DB::table('order_events')->insertOrIgnore([
                    'order_id' => $parent->id,
                    'source' => 'digiflazz',
                    'event_id' => $eventId,
                    'status' => $result['status'],
                    'payload_json' => json_encode($result['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);

                $this->refreshMultiUnitOrder((string) $parent->id);

                return $changed > 0;
            }

            $order = DB::table('orders')
                ->whereRaw("LOWER(TRIM(provider_code)) = 'digiflazz'")
                ->where(function ($query) use ($providerRefId) {
                    $query->where('provider_ref_id', $providerRefId)
                        ->orWhere('reference_id', $providerRefId);
                })
                ->lockForUpdate()
                ->first();

            if (!$order || $order->payment_status !== 'paid') {
                return false;
            }

            if (DB::table('order_events')->where('source', 'digiflazz')->where('event_id', $eventId)->exists()) {
                return false;
            }

            $changed = $this->transitionAllowed($order, $result['status'])
                ? DB::table('orders')->where('id', $order->id)->update([
                    'provider_ref_id' => $order->provider_ref_id ?: $result['externalId'],
                    'provider_status' => $result['status'],
                    'provider_message' => $result['message'],
                    'provider_serial_number' => $result['serialNumber'] ?: $order->provider_serial_number,
                    'fulfillment_status' => $result['status'],
                    'updated_at' => now(),
                ])
                : 0;

            DB::table('order_events')->insertOrIgnore([
                'order_id' => $order->id,
                'source' => 'digiflazz',
                'event_id' => $eventId,
                'status' => $result['status'],
                'payload_json' => json_encode($result['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return $changed > 0;
        }, 3);

        if ($result['status'] === 'success') {
            $this->notifications->notifyOrderSuccessByProviderRef('digiflazz', $providerRefId);
        }

        return $changed;
    }

    public function mapStatus(string $status, string $rc = ''): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'sukses') {
            return 'success';
        }
        if ($normalized === 'gagal') {
            return 'failed';
        }
        if ($normalized === 'pending') {
            return 'processing';
        }

        $code = trim($rc);
        if ($code === '00') {
            return 'success';
        }
        if (in_array($code, ['03', '99'], true) || $code === '') {
            return 'processing';
        }

        return 'failed';
    }

    private function claimOrder(string $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (!$order
                || $order->payment_status !== 'paid'
                || $order->fulfillment_type !== 'automatic'
                || in_array($order->fulfillment_status, ['success', 'failed', 'cancelled'], true)) {
                return false;
            }

            $stale = $order->updated_at ? now()->subMinutes(2)->gte($order->updated_at) : true;
            $eligible = $order->provider_status === null
                || (in_array($order->provider_status, ['processing', 'dispatching', 'retryable_error'], true) && $stale);

            if (!$eligible) {
                return false;
            }

            $latestManualRetry = DB::table('order_events')
                ->where('order_id', $orderId)
                ->where('source', 'admin')
                ->where('status', 'retry_authorized')
                ->orderByDesc('id')
                ->first(['id']);

            $attemptQuery = DB::table('order_events')
                ->where('order_id', $orderId)
                ->where('source', 'admin')
                ->where('status', 'dispatching');
            if ($latestManualRetry) {
                $attemptQuery->where('id', '>', $latestManualRetry->id);
            }
            $attempts = $attemptQuery->count();

            if ($attempts >= 5) {
                DB::table('orders')->where('id', $orderId)->update([
                    'fulfillment_status' => 'needs_review',
                    'provider_status' => 'retry_exhausted',
                    'provider_message' => 'Pemenuhan otomatis gagal setelah 5 percobaan; periksa sebelum mencoba ulang.',
                    'updated_at' => now(),
                ]);
                return false;
            }

            $eventId = 'fulfillment-attempt-'.$orderId.'-'.Str::uuid();
            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'source' => 'admin',
                'event_id' => $eventId,
                'status' => 'dispatching',
                'payload_json' => json_encode(['providerCode' => 'digiflazz']),
                'created_at' => now(),
            ]);

            DB::table('orders')->where('id', $orderId)->update([
                'fulfillment_status' => 'dispatching',
                'provider_status' => 'dispatching',
                'provider_ref_id' => $order->provider_ref_id ?: $order->reference_id,
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function fulfillUnits(object $order): void
    {
        $units = DB::table('order_fulfillment_units')
            ->where('order_id', $order->id)
            ->orderBy('unit_index')
            ->get();

        foreach ($units as $unit) {
            if (in_array($unit->provider_status, ['success', 'failed', 'retry_exhausted'], true)) {
                continue;
            }
            if (!$this->claimUnit($unit->id)) {
                continue;
            }

            try {
                $result = $this->send(
                    trim((string) $order->provider_sku),
                    trim((string) $order->customer_no),
                    (string) $unit->provider_ref_id,
                );
                DB::transaction(function () use ($order, $unit, $result): void {
                    $fresh = DB::table('order_fulfillment_units')->where('id', $unit->id)->lockForUpdate()->first();
                    if (!$fresh || in_array($fresh->provider_status, ['success', 'failed', 'retry_exhausted'], true)) {
                        return;
                    }

                    DB::table('order_fulfillment_units')->where('id', $unit->id)->update([
                        'provider_status' => $result['status'],
                        'provider_message' => $result['message'],
                        'provider_serial_number' => $result['serialNumber'] ?: $fresh->provider_serial_number,
                        'updated_at' => now(),
                    ]);
                    DB::table('order_events')->insertOrIgnore([
                        'order_id' => $order->id,
                        'source' => 'digiflazz',
                        'event_id' => 'unit-request-'.$unit->provider_ref_id.'-'.$fresh->attempts,
                        'status' => $result['status'],
                        'payload_json' => json_encode($result['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                }, 3);
            } catch (Throwable $error) {
                DB::table('order_fulfillment_units')
                    ->where('id', $unit->id)
                    ->whereNotIn('provider_status', ['success', 'failed', 'retry_exhausted'])
                    ->update([
                        'provider_status' => 'retryable_error',
                        'provider_message' => mb_substr($error->getMessage() ?: 'Provider gagal dihubungi.', 0, 500),
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->refreshMultiUnitOrder((string) $order->id);
        $freshOrder = DB::table('orders')->where('id', $order->id)->first(['fulfillment_status']);
        if ($freshOrder?->fulfillment_status === 'success') {
            $this->notifications->notifyOrderSuccessById((string) $order->id);
        }
    }

    private function claimUnit(int $unitId): bool
    {
        return DB::transaction(function () use ($unitId): bool {
            $unit = DB::table('order_fulfillment_units')->where('id', $unitId)->lockForUpdate()->first();
            if (!$unit || in_array($unit->provider_status, ['success', 'failed', 'retry_exhausted'], true)) {
                return false;
            }

            if ((int) $unit->attempts >= 5) {
                DB::table('order_fulfillment_units')->where('id', $unitId)->update([
                    'provider_status' => 'retry_exhausted',
                    'provider_message' => $unit->provider_message ?: 'Pemrosesan gagal setelah 5 percobaan.',
                    'updated_at' => now(),
                ]);
                return false;
            }

            $stale = $unit->updated_at ? now()->subMinutes(2)->gte($unit->updated_at) : true;
            $eligible = $unit->provider_status === 'waiting'
                || (in_array($unit->provider_status, ['processing', 'dispatching', 'retryable_error'], true) && $stale);
            if (!$eligible) {
                return false;
            }

            DB::table('order_fulfillment_units')->where('id', $unitId)->update([
                'provider_status' => 'dispatching',
                'attempts' => ((int) $unit->attempts) + 1,
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function refreshMultiUnitOrder(string $orderId): void
    {
        $rows = DB::table('order_fulfillment_units')
            ->where('order_id', $orderId)
            ->orderBy('unit_index')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $total = $rows->count();
        $successful = $rows->where('provider_status', 'success')->count();
        $terminalFailed = $rows->filter(
            fn ($unit) => in_array($unit->provider_status, ['failed', 'retry_exhausted'], true),
        )->count();
        $pending = $total - $successful - $terminalFailed;
        $serials = $rows->pluck('provider_serial_number')->filter()->implode(' | ') ?: null;

        if ($successful === $total) {
            DB::table('orders')->where('id', $orderId)->where('payment_status', 'paid')->update([
                'fulfillment_status' => 'success',
                'provider_status' => 'success',
                'provider_message' => "{$successful}/{$total} item berhasil dikirim.",
                'provider_serial_number' => $serials,
                'updated_at' => now(),
            ]);
            return;
        }

        if ($pending === 0 && $terminalFailed > 0) {
            DB::table('orders')->where('id', $orderId)->where('payment_status', 'paid')->update([
                'fulfillment_status' => 'needs_review',
                'provider_status' => 'partial_failed',
                'provider_message' => "{$successful}/{$total} item berhasil; {$terminalFailed} item memerlukan pemeriksaan.",
                'provider_serial_number' => $serials,
                'updated_at' => now(),
            ]);
            return;
        }

        $retryable = $rows->contains(fn ($unit) => $unit->provider_status === 'retryable_error');
        DB::table('orders')->where('id', $orderId)->where('payment_status', 'paid')->update([
            'fulfillment_status' => 'processing',
            'provider_status' => $retryable ? 'retryable_error' : 'dispatching',
            'provider_message' => "{$successful}/{$total} item selesai, sisanya masih diproses.",
            'provider_serial_number' => $serials,
            'updated_at' => now(),
        ]);
    }

    /** @param array{externalId:string,status:string,message:string,serialNumber:?string,raw:array<string,mixed>} $result */
    private function applyOrderResult(string $orderId, array $result): void
    {
        DB::transaction(function () use ($orderId, $result): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (!$order || !$this->transitionAllowed($order, $result['status'])) {
                return;
            }

            DB::table('orders')->where('id', $orderId)->update([
                'provider_ref_id' => $order->provider_ref_id ?: $result['externalId'],
                'provider_status' => $result['status'],
                'provider_message' => $result['message'],
                'provider_serial_number' => $result['serialNumber'] ?: $order->provider_serial_number,
                'fulfillment_status' => $result['status'],
                'updated_at' => now(),
            ]);

            $semantic = json_encode([
                $result['externalId'],
                $result['status'],
                $result['message'],
                $result['serialNumber'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            DB::table('order_events')->insertOrIgnore([
                'order_id' => $orderId,
                'source' => 'digiflazz',
                'event_id' => 'request-'.hash('sha256', $semantic),
                'status' => $result['status'],
                'payload_json' => json_encode($result['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function transitionAllowed(object $order, string $next): bool
    {
        if ($order->payment_status !== 'paid'
            || $order->fulfillment_type !== 'automatic'
            || $order->fulfillment_status === 'cancelled') {
            return false;
        }

        if ($next === 'success') {
            return $order->fulfillment_status !== 'success';
        }
        if ($next === 'failed') {
            return !in_array($order->fulfillment_status, ['success', 'failed'], true);
        }

        return !in_array($order->fulfillment_status, ['success', 'failed'], true)
            && !in_array($order->provider_status, ['success', 'failed'], true);
    }

    private function setError(string $orderId, string $message): void
    {
        DB::table('orders')
            ->where('id', $orderId)
            ->where('payment_status', 'paid')
            ->where('fulfillment_type', 'automatic')
            ->whereNotIn('fulfillment_status', ['success', 'failed', 'cancelled'])
            ->whereNotIn(DB::raw("COALESCE(provider_status, '')"), ['success', 'failed'])
            ->update([
                'fulfillment_status' => 'needs_review',
                'provider_status' => 'error',
                'provider_message' => mb_substr($message, 0, 500),
                'updated_at' => now(),
            ]);
    }

    private function setRetryableError(string $orderId, string $message): void
    {
        DB::table('orders')
            ->where('id', $orderId)
            ->where('payment_status', 'paid')
            ->where('fulfillment_type', 'automatic')
            ->whereNotIn('fulfillment_status', ['success', 'failed', 'cancelled'])
            ->whereNotIn(DB::raw("COALESCE(provider_status, '')"), ['success', 'failed'])
            ->update([
                'fulfillment_status' => 'processing',
                'provider_status' => 'retryable_error',
                'provider_message' => mb_substr($message, 0, 500),
                'updated_at' => now(),
            ]);
    }

    /** @return array{environment:string,username:string,apiKey:string,transactionUrl:string} */
    private function runtimeConfig(): array
    {
        $runtime = $this->integrations->digiflazzRuntime();
        $environment = $runtime['environment'];
        $username = $runtime['username'];
        $apiKey = $runtime['apiKey'];
        $transactionUrl = $runtime['transactionApiUrl'];

        if ($username === '' || $apiKey === '') {
            throw new RuntimeException('Kredensial DigiFlazz belum lengkap.');
        }
        $transactionUrl = DigiflazzEndpoint::requireOfficial($transactionUrl, '/v1/transaction');

        return [
            'environment'=>$environment,
            'username'=>$username,
            'apiKey'=>$apiKey,
            'transactionUrl'=>$transactionUrl,
        ];
    }

    private function providerMessage(array $data, string $fallback): string
    {
        $message = trim((string) ($data['message'] ?? '')) ?: $fallback;
        $rc = trim((string) ($data['rc'] ?? ''));

        return $rc !== '' ? '[RC '.$rc.'] '.$message : $message;
    }
}
