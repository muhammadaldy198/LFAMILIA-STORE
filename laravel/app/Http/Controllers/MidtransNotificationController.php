<?php

namespace App\Http\Controllers;

use App\Services\DigiflazzFulfillmentService;
use App\Services\ExternalWalletSettlementService;
use App\Services\MidtransSnapService;
use App\Services\PaymentTransitionService;
use App\Services\TransactionNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MidtransNotificationController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'service' => 'midtrans-snap-notification',
            'method' => 'POST',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function handle(
        Request $request,
        MidtransSnapService $midtrans,
        PaymentTransitionService $transitions,
        ExternalWalletSettlementService $wallet,
        DigiflazzFulfillmentService $fulfillment,
        TransactionNotificationService $notifications,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return response()->json(['error' => 'Payload Midtrans tidak valid.'], 400);
        }

        $referenceId = trim((string) ($body['order_id'] ?? ''));
        $statusCode = trim((string) ($body['status_code'] ?? ''));
        $grossAmount = trim((string) ($body['gross_amount'] ?? ''));
        $signature = trim((string) ($body['signature_key'] ?? ''));

        if ($referenceId === '' || $statusCode === '' || $grossAmount === '' || $signature === '') {
            return response()->json(['error' => 'Payload Midtrans tidak lengkap.'], 400);
        }

        try {
            $order = DB::table('orders')->where('reference_id', $referenceId)->first();
            $topup = $order ? null : DB::table('wallet_topups')
                ->where('reference_id', $referenceId)
                ->where('payment_gateway', 'midtrans')
                ->first();

            if (!$order && !$topup) {
                return response()->json(['ok' => true]);
            }

            $gateway = $order?->payment_gateway ?? $topup?->payment_gateway;
            $mode = $order?->payment_gateway_mode ?? $topup?->payment_gateway_mode;
            $environment = $order?->payment_gateway_environment ?? $topup?->gateway_environment;

            if ($gateway !== 'midtrans' || $mode !== 'snap' || !in_array($environment, ['sandbox', 'production'], true)) {
                return response()->json(['ok' => true]);
            }

            if (!$midtrans->verifyNotification(
                $referenceId,
                $statusCode,
                $grossAmount,
                $signature,
                (string) $environment,
            )) {
                return response()->json(['error' => 'Signature Midtrans tidak valid.'], 401);
            }

            $status = $midtrans->mapStatus(
                trim((string) ($body['transaction_status'] ?? '')),
                isset($body['fraud_status']) ? (string) $body['fraud_status'] : null,
            );
            $amount = is_numeric($grossAmount) ? (int) round((float) $grossAmount) : 0;

            if ($status === 'paid' && $amount <= 0) {
                return response()->json(['error' => 'Nominal Midtrans tidak valid.'], 400);
            }

            if ($topup) {
                if ($status !== 'ignore') {
                    $settlement = $wallet->apply(
                        $referenceId,
                        'midtrans',
                        $status,
                        $amount,
                        null,
                        $status === 'paid',
                    );
                    if ($settlement['credited']) {
                        $topupId = DB::table('wallet_topups')
                            ->where('reference_id', $referenceId)
                            ->where('payment_gateway', 'midtrans')
                            ->value('id');
                        if ($topupId) {
                            $notifications->notifyWalletTopupSuccessById((string) $topupId);
                        }
                    }
                }

                return response()->json(['ok' => true]);
            }

            if ($status === 'paid' && $amount !== (int) $order->total) {
                return response()->json(['error' => 'Nominal Midtrans tidak sesuai.'], 400);
            }

            $transactionId = trim((string) ($body['transaction_id'] ?? ''));
            $eventId = $transactionId !== ''
                ? 'snap-'.$transactionId.'-'.$status
                : 'snap-'.hash('sha256', $rawBody);

            if ($status === 'ignore') {
                $transitions->recordOrderEvent($referenceId, 'midtrans', $eventId, 'ignore', $body);
            } else {
                $transition = $transitions->applyOrderEvent(
                    $referenceId,
                    'midtrans',
                    $eventId,
                    $status,
                    $body,
                    $status === 'paid',
                    $status === 'expired',
                );
                if ($transition['firstPaid'] && $order->fulfillment_type === 'automatic') {
                    $fulfillment->fulfillOrder((string) $order->id);
                }
            }

            return response()->json(['ok' => true]);
        } catch (Throwable) {
            return response()->json(['error' => 'Callback Midtrans Snap gagal diproses.'], 500);
        }
    }
}
