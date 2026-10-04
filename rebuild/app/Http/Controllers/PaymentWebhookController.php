<?php

namespace App\Http\Controllers;

use App\Services\IntegrationRuntimeConfig;
use App\Services\MidtransStatusVerification;
use App\Services\Payment\DokuSignature;
use App\Services\Payment\MidtransGateway;
use App\Services\PaymentStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController
{
    public function midtrans(
        Request $request,
        PaymentStateService $states,
        MidtransGateway $midtrans,
        IntegrationRuntimeConfig $runtime,
    ): JsonResponse {
        $payload = $request->json()->all();
        $resolved = $runtime->resolve('midtrans');
        $config = $resolved['config'] ?? [];
        if (!is_array($config) || empty($config['server_key'])) {
            abort(503, 'Payment verification unavailable.');
        }

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $key) {
            abort_unless(isset($payload[$key]) && is_scalar($payload[$key]), 400);
        }

        $expected = hash('sha512',
            (string) $payload['order_id'].
            (string) $payload['status_code'].
            (string) $payload['gross_amount'].
            (string) $config['server_key']
        );
        abort_unless(hash_equals($expected, (string) $payload['signature_key']), 401);

        $payment = DB::table('payment_transactions')
            ->where('gateway_code', 'MIDTRANS')
            ->where('merchant_reference', (string) $payload['order_id'])
            ->first();
        abort_unless($payment, 404);
        abort_unless(app(MidtransStatusVerification::class)->amount($payload['gross_amount']) === (int) $payment->amount_idr, 422);

        $eventId = hash('sha256', implode('|', [
            (string) ($payload['transaction_id'] ?? ''),
            (string) $payload['transaction_status'],
            (string) $payload['status_code'],
            (string) ($payload['settlement_time'] ?? ''),
        ]));
        if (DB::table('payment_callbacks')->where('gateway_code', 'MIDTRANS')
            ->where('event_id', $eventId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $verified = $midtrans->status((string) $payment->merchant_reference);
        } catch (\RuntimeException) {
            abort(503, 'Payment status verification unavailable.');
        }

        abort_unless(hash_equals((string) $payment->merchant_reference, (string) $verified['order_id']), 422);
        abort_unless(app(MidtransStatusVerification::class)->amount($verified['gross_amount']) === (int) $payment->amount_idr, 422);

        $processed = DB::transaction(function () use ($payment, $eventId, $payload, $verified, $states): bool {
            if (! $this->claimCallback($payment->id, 'MIDTRANS', $eventId, $payload)) {
                return false;
            }

            $status = app(MidtransStatusVerification::class)->status($verified);
            $result = $states->apply($payment->id, $status, [
                'source' => 'midtrans_status_challenge',
                'transaction_status' => $verified['transaction_status'],
            ]);
            $this->finishCallback('MIDTRANS', $eventId, (string) $result['result']);

            return true;
        });

        return response()->json(['status' => $processed ? 'ok' : 'duplicate']);
    }

    public function doku(
        Request $request,
        DokuSignature $signature,
        PaymentStateService $states,
        IntegrationRuntimeConfig $runtime,
    ): JsonResponse
    {
        $raw = $request->getContent();
        $payload = $request->json()->all();
        $resolved = $runtime->resolve('doku');
        $config = $resolved['config'] ?? [];
        if (!is_array($config) || empty($config['client_id']) || empty($config['secret_key'])) {
            abort(503, 'Payment verification unavailable.');
        }

        $clientId = (string) $request->header('Client-Id', '');
        $requestId = (string) $request->header('Request-Id', '');
        $timestamp = (string) $request->header('Request-Timestamp', '');
        $headerSignature = (string) $request->header('Signature', '');
        abort_unless($clientId !== '' && $requestId !== '' && $timestamp !== '' && $headerSignature !== '', 400);
        abort_unless(preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId) === 1, 400);
        abort_unless($signature->isFreshTimestamp($timestamp), 401);
        abort_unless(hash_equals((string) $config['client_id'], $clientId), 401);
        abort_unless($signature->verify(
            $headerSignature,
            $clientId,
            $requestId,
            $timestamp,
            $request->getPathInfo(),
            $raw,
            (string) $config['secret_key']
        ), 401);

        $merchantReference = data_get($payload, 'order.invoice_number');
        $amount = data_get($payload, 'order.amount');
        $transactionStatus = strtoupper((string) data_get($payload, 'transaction.status'));
        abort_unless(is_string($merchantReference) && $merchantReference !== '', 400);

        $payment = DB::table('payment_transactions')
            ->where('gateway_code', 'DOKU')
            ->where('merchant_reference', $merchantReference)
            ->first();
        abort_unless($payment, 404);
        abort_unless(app(MidtransStatusVerification::class)->amount($amount) === (int) $payment->amount_idr, 422);

        $eventId = hash('sha256', $requestId);
        $processed = DB::transaction(function () use (
            $payment,
            $eventId,
            $payload,
            $transactionStatus,
            $states,
        ): bool {
            if (! $this->claimCallback($payment->id, 'DOKU', $eventId, $payload)) {
                return false;
            }

            $status = match ($transactionStatus) {
                'SUCCESS' => 'PAID',
                'EXPIRED' => 'EXPIRED',
                'CANCELLED', 'CANCELED' => 'CANCELLED',
                'FAILED' => 'FAILED',
                'REFUNDED', 'REFUND' => 'REFUNDED',
                default => 'PENDING',
            };
            $result = $states->apply($payment->id, $status, [
                'source' => 'doku_callback',
                'transaction_status' => $transactionStatus,
            ]);
            $this->finishCallback('DOKU', $eventId, (string) $result['result']);

            return true;
        });

        return response()->json(['status' => $processed ? 'ok' : 'duplicate']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function claimCallback(int $paymentId, string $gateway, string $eventId, array $payload): bool
    {
        return DB::table('payment_callbacks')->insertOrIgnore([
            'payment_transaction_id' => $paymentId,
            'gateway_code' => $gateway,
            'event_id' => $eventId,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'result' => 'PROCESSING',
            'received_at' => now(),
        ]) === 1;
    }

    private function finishCallback(string $gateway, string $eventId, string $result): void
    {
        DB::table('payment_callbacks')
            ->where('gateway_code', $gateway)
            ->where('event_id', $eventId)
            ->update(['result' => substr($result, 0, 40)]);
    }
}
