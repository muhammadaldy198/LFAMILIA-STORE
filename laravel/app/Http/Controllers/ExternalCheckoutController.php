<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutValidationException;
use App\Exceptions\NicknameServiceException;
use App\Exceptions\NicknameValidationException;
use App\Exceptions\PromotionQuoteException;
use App\Services\CheckoutService;
use App\Services\CustomerAuthService;
use App\Services\GatewayPaymentService;
use App\Services\NicknameService;
use App\Services\PaymentChannelService;
use App\Services\PromotionService;
use App\Services\SecurityGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExternalCheckoutController extends Controller
{
    public function create(
        Request $request,
        CustomerAuthService $auth,
        SecurityGuard $security,
        CheckoutService $checkout,
        PromotionService $promotions,
        NicknameService $nicknames,
        PaymentChannelService $channels,
        GatewayPaymentService $gateway,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'automatic-checkout', 12, 600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak percobaan checkout. Coba lagi beberapa menit.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        $referenceId = null;
        $orderId = null;
        $paymentDispatchStarted = false;

        try {
            $input = $request->validate([
                'productSlug' => ['required', 'string', 'min:2', 'max:80'],
                'packageSku' => ['required', 'string', 'min:2', 'max:100'],
                'destination' => ['nullable', 'string', 'max:150'],
                'server' => ['nullable', 'string', 'max:40'],
                'customerInputs' => ['array', 'max:12'],
                'customerInputs.*.id' => ['required', 'string', 'max:60'],
                'customerInputs.*.value' => ['nullable', 'string', 'max:300'],
                'buyerName' => ['required', 'string', 'min:2', 'max:100'],
                'buyerEmail' => ['required', 'email', 'max:150'],
                'buyerPhone' => ['required', 'regex:/^\+?[0-9]{8,16}$/'],
                'customerNotes' => ['nullable', 'string', 'max:500'],
                'paymentMethod' => ['required', 'in:va,ewallet,qris'],
                'paymentChannel' => ['required', 'string', 'min:2', 'max:30'],
                'voucherCode' => ['nullable', 'string', 'max:40'],
                'quantity' => ['nullable', 'integer', 'min:1', 'max:5'],
                'idempotencyKey' => ['required', 'uuid'],
            ]);

            $prior = DB::table('orders')
                ->where('external_checkout_key', $input['idempotencyKey'])
                ->first();
            if ($prior) {
                return $this->existingResponse($prior);
            }

            $paymentChannel = $input['paymentMethod'] === 'qris' && $input['paymentChannel'] === 'qris'
                ? 'mpm'
                : $input['paymentChannel'];
            $managed = $channels->activeChannel($input['paymentMethod'], $paymentChannel);
            if (!$managed) {
                return response()->json(['error' => 'Metode pembayaran belum didukung atau sedang dinonaktifkan.'], 400);
            }

            $readiness = $gateway->readiness(
                $managed['gateway'],
                $input['paymentMethod'],
                $paymentChannel,
                $managed['gatewayConfig'],
            );
            if (!$readiness['ready']) {
                return response()->json(['error' => 'Metode pembayaran ini belum siap.'], 503);
            }

            $item = $checkout->resolveItem($input['productSlug'], $input['packageSku']);
            if (!$item) {
                return response()->json(['error' => 'Produk atau nominal tidak tersedia.'], 404);
            }

            $quantity = (int) ($input['quantity'] ?? 1);
            $checkout->assertPurchasable($item, $quantity);
            $customer = $auth->current($request);

            $promotion = $promotions->quote(
                $item['productSlug'],
                $item['packageSku'],
                $item['price'],
                $input['voucherCode'] ?? null,
                $customer['id'] ?? null,
                $quantity,
            );

            $customerData = $checkout->normalizeCustomerInputs(
                $item,
                $input['customerInputs'] ?? [],
                $input['destination'] ?? '',
                $input['server'] ?? null,
            );
            $verified = $nicknames->verifyForCheckout(
                $item['productSlug'],
                $customerData['destination'],
                $customerData['server'],
            );

            $fee = $channels->customerFee($promotion['finalPrice'], $managed['gatewayConfig']);
            $identity = $checkout->identity();
            $referenceId = $identity['referenceId'];
            $orderId = $identity['id'];

            $checkout->insertPendingOrder([
                ...$identity,
                'item' => $item,
                'destination' => $customerData['destination'],
                'server' => $customerData['server'],
                'nickname' => $verified['nickname'],
                'buyerName' => trim($input['buyerName']),
                'buyerEmail' => strtolower(trim($input['buyerEmail'])),
                'buyerPhone' => trim($input['buyerPhone']),
                'customerNotes' => isset($input['customerNotes']) ? trim((string) $input['customerNotes']) : null,
                'customerInputs' => $customerData['values'],
                'paymentMethod' => $input['paymentMethod'],
                'paymentChannel' => $paymentChannel,
                'paymentGateway' => $managed['gateway'],
                'paymentGatewayMode' => $readiness['mode'],
                'paymentGatewayEnvironment' => $readiness['environment'],
                'customerId' => $customer['id'] ?? null,
                'externalCheckoutKey' => $input['idempotencyKey'],
                'promotion' => $promotion,
                'quantity' => $quantity,
                'adminFee' => $fee,
            ]);

            $promotions->reserveExternal(
                $orderId,
                $promotion['voucherCode'],
                $promotion['flashSaleId'],
                now()->addMinutes(75)->toIso8601String(),
            );

            $baseUrl = rtrim(trim((string) config('lfamilia.public_base_url')), '/');
            if (!preg_match('#^https://[^/]+#i', $baseUrl)) {
                throw new CheckoutValidationException('PUBLIC_BASE_URL belum dikonfigurasi dengan benar.');
            }

            $paymentDispatchStarted = true;
            $payment = $gateway->create([
                'gateway' => $managed['gateway'],
                'referenceId' => $referenceId,
                'amount' => $promotion['finalPrice'] + $fee,
                'paymentMethod' => $input['paymentMethod'],
                'paymentChannel' => $paymentChannel,
                'gatewayConfig' => $managed['gatewayConfig'],
                'buyerName' => trim($input['buyerName']),
                'buyerEmail' => strtolower(trim($input['buyerEmail'])),
                'buyerPhone' => trim($input['buyerPhone']),
                'productName' => $item['productName'],
                'packageLabel' => $item['packageLabel'],
                'finishUrl' => $baseUrl.'/payment?invoice='.rawurlencode($referenceId),
                'publicBaseUrl' => $baseUrl,
            ]);

            DB::table('orders')->where('id', $orderId)->update([
                'payment_gateway' => $payment['gateway'],
                'payment_gateway_mode' => $payment['mode'],
                'payment_gateway_environment' => $payment['environment'],
                'gateway_request_id' => $payment['requestId'],
                'gateway_reference_no' => $payment['referenceNo'],
                'gateway_payment_no' => $payment['paymentNo'],
                'gateway_qr_content' => $payment['qrContent'],
                'gateway_payment_url' => $payment['paymentUrl'],
                'gateway_expired_at' => $payment['expiredAt'],
                'gateway_status_checked_at' => null,
                'total' => $promotion['finalPrice'] + $fee,
                'updated_at' => now(),
            ]);

            $promotions->updateExternalExpiry($orderId, $payment['expiredAt']);
            DB::table('order_events')->insertOrIgnore([
                'order_id' => $orderId,
                'source' => $payment['gateway'],
                'event_id' => 'create-'.$payment['requestId'],
                'status' => 'pending',
                'payload_json' => json_encode($payment['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            $fresh = DB::table('orders')->where('id', $orderId)->first();

            return response()->json($this->responsePayload($fresh), 201);
        } catch (QueryException $error) {
            if (str_contains(strtolower($error->getMessage()), 'external_checkout_key')) {
                $prior = DB::table('orders')
                    ->where('external_checkout_key', (string) $request->input('idempotencyKey'))
                    ->first();
                if ($prior) {
                    return $this->existingResponse($prior);
                }
            }

            return response()->json([
                'error' => 'Status pembuatan pembayaran belum dapat dipastikan. Jangan bayar dua kali; cek invoice beberapa saat lagi.',
                'retryable' => true,
            ], 503);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (CheckoutValidationException|PromotionQuoteException|NicknameValidationException $error) {
            if (!$paymentDispatchStarted) {
                $this->failBeforeDispatch($orderId, $referenceId, $promotions, $error->getMessage());
            }
            return response()->json(['error' => $error->getMessage(), 'retryable' => false], 400);
        } catch (NicknameServiceException $error) {
            if (!$paymentDispatchStarted) {
                $this->failBeforeDispatch($orderId, $referenceId, $promotions, $error->getMessage());
            }
            return response()->json(['error' => $error->getMessage(), 'retryable' => true], 503);
        } catch (Throwable $error) {
            if ($paymentDispatchStarted) {
                if ($referenceId) {
                    DB::table('orders')
                        ->where('reference_id', $referenceId)
                        ->where('payment_status', 'pending')
                        ->update([
                            'provider_message' => 'Status pembuatan pembayaran belum dapat dipastikan: '.mb_substr($error->getMessage(), 0, 420),
                            'gateway_expired_at' => DB::raw("COALESCE(gateway_expired_at, datetime('now', '+75 minutes'))"),
                            'updated_at' => now(),
                        ]);
                }

                return response()->json([
                    'error' => 'Status pembuatan pembayaran belum dapat dipastikan. Jangan bayar dua kali; coba cek invoice ini beberapa saat lagi.',
                    'retryable' => true,
                ], 503);
            }

            $this->failBeforeDispatch($orderId, $referenceId, $promotions, $error->getMessage());

            return response()->json([
                'error' => $error->getMessage() ?: 'Pembayaran gagal dibuat.',
                'retryable' => false,
            ], 503);
        }
    }

    private function existingResponse(object $order): JsonResponse
    {
        if (in_array($order->payment_status, ['failed', 'expired'], true)) {
            return response()->json([
                'error' => 'Percobaan pembayaran sebelumnya sudah gagal atau kedaluwarsa. Buat pembayaran baru.',
                'retryable' => false,
            ], 409);
        }

        if (!$order->gateway_request_id) {
            return response()->json([
                'error' => 'Invoice sedang dibuat. Coba lagi dengan data yang sama.',
                'retryable' => true,
            ], 409);
        }

        return response()->json($this->responsePayload($order));
    }

    /** @return array<string,mixed> */
    private function responsePayload(object $order): array
    {
        return [
            'orderId' => $order->id,
            'referenceId' => $order->reference_id,
            'publicInvoice' => strtoupper(trim((string) $order->reference_id)),
            'fulfillmentType' => $order->fulfillment_type,
            'quantity' => max(1, (int) $order->quantity),
            'basePrice' => (int) $order->base_subtotal,
            'sellingPrice' => (int) $order->subtotal,
            'discountAmount' => (int) $order->discount_amount,
            'voucherCode' => $order->voucher_code,
            'flashSaleId' => $order->flash_sale_id,
            'paymentMethod' => $order->payment_method,
            'paymentNo' => $order->gateway_payment_no,
            'qrContent' => $order->gateway_qr_content,
            'paymentName' => $this->paymentName((string) $order->payment_method, (string) $order->payment_channel),
            'paymentUrl' => $order->gateway_payment_url,
            'fee' => (int) $order->admin_fee,
            'total' => (int) $order->total,
            'expiredAt' => $order->gateway_expired_at,
            'paymentStatus' => $order->payment_status,
        ];
    }

    private function paymentName(string $method, string $channel): string
    {
        return $method === 'qris'
            ? 'QRIS'
            : ($method === 'va' ? 'Virtual Account '.strtoupper($channel) : strtoupper($channel));
    }

    private function failBeforeDispatch(
        ?string $orderId,
        ?string $referenceId,
        PromotionService $promotions,
        string $message,
    ): void {
        if ($orderId) {
            $promotions->releaseExternal($orderId);
        }
        if ($referenceId) {
            DB::table('orders')
                ->where('reference_id', $referenceId)
                ->where('payment_status', 'pending')
                ->update([
                    'payment_status' => 'failed',
                    'provider_message' => mb_substr($message, 0, 500),
                    'updated_at' => now(),
                ]);
        }
    }
}
