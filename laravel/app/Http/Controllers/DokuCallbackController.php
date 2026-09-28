<?php

namespace App\Http\Controllers;

use App\Services\DigiflazzFulfillmentService;
use App\Services\DokuCheckoutService;
use App\Services\ExternalWalletSettlementService;
use App\Services\PaymentTransitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class DokuCallbackController extends Controller
{
    public function show()
    {
        return response()->json([
            'ok' => true,
            'service' => 'doku-checkout-notification',
            'method' => 'POST',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function handle(
        Request $request,
        DokuCheckoutService $doku,
        PaymentTransitionService $transitions,
        ExternalWalletSettlementService $wallet,
        DigiflazzFulfillmentService $fulfillment,
    ) {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'Payload callback DOKU tidak valid.'], 400);
        }

        try {
            $notification = $doku->parseNotification($payload);
            if ($notification['referenceId'] === '') {
                return response()->json(['error' => 'Referensi transaksi DOKU tidak ada.'], 400);
            }

            $order = DB::table('orders')
                ->where('reference_id', $notification['referenceId'])
                ->first();
            $topup = $order ? null : DB::table('wallet_topups')
                ->where('reference_id', $notification['referenceId'])
                ->where('payment_gateway', 'doku')
                ->first();

            if (!$order && !$topup) {
                return $this->acknowledge();
            }

            $gateway = $order?->payment_gateway ?? $topup?->payment_gateway;
            $mode = $order?->payment_gateway_mode ?? $topup?->payment_gateway_mode;
            $environment = $order?->payment_gateway_environment ?? $topup?->gateway_environment;

            if ($gateway !== 'doku' || $mode !== 'checkout' || !in_array($environment, ['sandbox', 'production'], true)) {
                return $this->acknowledge();
            }

            if (!$doku->validateNotification(
                $rawBody,
                '/api/payments/doku/callback',
                $request->header('client-id'),
                $request->header('request-id'),
                $request->header('request-timestamp'),
                $request->header('signature'),
                (string) $environment,
            )) {
                return response()->json(['error' => 'Signature callback DOKU tidak valid.'], 401);
            }

            if ($topup) {
                $wallet->apply(
                    $notification['referenceId'],
                    'doku',
                    $notification['status'],
                    $notification['amount'],
                    $notification['originalRequestId'],
                    $notification['status'] === 'paid',
                );

                return $this->acknowledge();
            }

            if ($order->gateway_request_id && $notification['originalRequestId']
                && !hash_equals((string) $order->gateway_request_id, $notification['originalRequestId'])) {
                return $this->acknowledge();
            }

            if ($notification['status'] === 'paid' && $notification['amount'] !== (int) $order->total) {
                return $this->acknowledge();
            }

            $eventId = trim((string) $request->header('request-id'));
            if ($eventId === '') {
                $eventId = 'body-'.hash('sha256', $rawBody);
            }

            $transition = $transitions->applyOrderEvent(
                $notification['referenceId'],
                'doku',
                $eventId,
                $notification['status'],
                $payload,
                $notification['status'] === 'paid',
                $notification['status'] === 'expired',
            );
            if ($transition['firstPaid'] && $order->fulfillment_type === 'automatic') {
                $fulfillment->fulfillOrder((string) $order->id);
            }

            return $this->acknowledge();
        } catch (Throwable) {
            return response()->json(['error' => 'Callback DOKU gagal diproses.'], 503);
        }
    }

    private function acknowledge(): Response
    {
        return response('OK', 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
