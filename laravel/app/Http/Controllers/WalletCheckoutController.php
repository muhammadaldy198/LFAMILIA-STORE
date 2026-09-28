<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutValidationException;
use App\Exceptions\NicknameServiceException;
use App\Exceptions\NicknameValidationException;
use App\Exceptions\PromotionQuoteException;
use App\Exceptions\WalletSettlementException;
use App\Services\CheckoutService;
use App\Services\CustomerAuthService;
use App\Services\DigiflazzFulfillmentService;
use App\Services\NicknameService;
use App\Services\PromotionService;
use App\Services\SecurityGuard;
use App\Services\WalletSettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WalletCheckoutController extends Controller
{
    public function create(
        Request $request,
        CustomerAuthService $auth,
        SecurityGuard $security,
        CheckoutService $checkout,
        PromotionService $promotions,
        NicknameService $nicknames,
        WalletSettlementService $wallet,
        DigiflazzFulfillmentService $fulfillment,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'wallet-checkout', 12, 600);
        if (!$rate['allowed']) {
            return response()->json([
                'error' => 'Terlalu banyak percobaan checkout. Coba lagi beberapa menit.',
                'retryable' => true,
            ], 429, ['Retry-After' => (string) $rate['retry_after']]);
        }

        $customer = $auth->current($request);
        if (!$customer) {
            return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401);
        }

        $referenceId = null;

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
                'voucherCode' => ['nullable', 'string', 'max:40'],
                'quantity' => ['nullable', 'integer', 'min:1', 'max:5'],
                'idempotencyKey' => ['required', 'uuid'],
            ]);

            $existing = DB::table('orders')
                ->where('customer_id', $customer['id'])
                ->where('wallet_checkout_key', $input['idempotencyKey'])
                ->where('payment_method', 'wallet')
                ->first();
            if ($existing) {
                return $this->existingResponse($existing, $wallet, $fulfillment);
            }

            $quantity = (int) ($input['quantity'] ?? 1);
            $item = $checkout->resolveItem($input['productSlug'], $input['packageSku']);
            if (!$item) {
                return response()->json(['error' => 'Produk atau nominal tidak tersedia.'], 404);
            }

            $checkout->assertPurchasable($item, $quantity);
            $promotion = $promotions->quote(
                $item['productSlug'],
                $item['packageSku'],
                $item['price'],
                $input['voucherCode'] ?? null,
                (string) $customer['id'],
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

            $identity = $checkout->identity();
            $referenceId = $identity['referenceId'];

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
                'paymentMethod' => 'wallet',
                'paymentChannel' => 'lfamilia-balance',
                'customerId' => $customer['id'],
                'walletCheckoutKey' => $input['idempotencyKey'],
                'promotion' => $promotion,
                'quantity' => $quantity,
                'adminFee' => 0,
            ]);

            $balanceAfter = $wallet->settle((string) $customer['id'], $identity['id']);
            $order = DB::table('orders')->where('id', $identity['id'])->first();
            if ($order && $order->fulfillment_type === 'automatic') {
                $fulfillment->fulfillOrder((string) $order->id);
                $order = DB::table('orders')->where('id', $identity['id'])->first();
            }

            return response()->json($this->successPayload($order, $balanceAfter), 201);
        } catch (QueryException $error) {
            if (str_contains(strtolower($error->getMessage()), 'wallet_checkout_key')) {
                $key = (string) $request->input('idempotencyKey', '');
                $existing = DB::table('orders')
                    ->where('customer_id', $customer['id'])
                    ->where('wallet_checkout_key', $key)
                    ->where('payment_method', 'wallet')
                    ->first();
                if ($existing) {
                    return $this->existingResponse($existing, $wallet);
                }
            }

            return response()->json([
                'error' => 'Pembayaran saldo belum dapat dipastikan. Coba lagi dengan data yang sama.',
                'retryable' => true,
            ], 503);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (CheckoutValidationException|PromotionQuoteException|NicknameValidationException $error) {
            if ($referenceId) {
                DB::table('orders')->where('reference_id', $referenceId)->where('payment_status', 'pending')->update([
                    'payment_status' => 'failed',
                    'provider_message' => $error->getMessage(),
                    'updated_at' => now(),
                ]);
            }
            return response()->json(['error' => $error->getMessage(), 'retryable' => false], 400);
        } catch (WalletSettlementException $error) {
            if ($referenceId) {
                DB::table('orders')->where('reference_id', $referenceId)->where('payment_status', 'pending')->update([
                    'payment_status' => 'failed',
                    'provider_message' => $error->getMessage(),
                    'updated_at' => now(),
                ]);
            }
            return response()->json(['error' => $error->getMessage(), 'retryable' => false], 409);
        } catch (NicknameServiceException $error) {
            return response()->json(['error' => $error->getMessage(), 'retryable' => true], 503);
        } catch (Throwable) {
            return response()->json([
                'error' => 'Pembayaran saldo belum dapat dipastikan. Coba lagi dengan data yang sama.',
                'retryable' => true,
            ], 503);
        }
    }

    private function existingResponse(object $order, WalletSettlementService $wallet, DigiflazzFulfillmentService $fulfillment): JsonResponse
    {
        if ($order->payment_status === 'pending') {
            try {
                $balance = $wallet->settle((string) $order->customer_id, (string) $order->id);
                $fresh = DB::table('orders')->where('id', $order->id)->first();
                if ($fresh && $fresh->fulfillment_type === 'automatic') {
                    $fulfillment->fulfillOrder((string) $fresh->id);
                    $fresh = DB::table('orders')->where('id', $order->id)->first();
                }
                return response()->json($this->successPayload($fresh, $balance));
            } catch (WalletSettlementException $error) {
                return response()->json(['error' => $error->getMessage(), 'retryable' => false], 409);
            }
        }

        if ($order->payment_status === 'paid') {
            $balance = DB::table('wallet_transactions')
                ->where('reference', 'order:'.$order->id)
                ->value('balance_after');
            if ($balance === null) {
                return response()->json(['error' => 'Pembayaran wallet perlu diperiksa admin.'], 409);
            }
            return response()->json($this->successPayload($order, (int) $balance));
        }

        return response()->json([
            'error' => 'Percobaan sebelumnya gagal. Silakan buat pesanan kembali.',
            'retryable' => false,
        ], 409);
    }

    /** @return array<string,mixed> */
    private function successPayload(?object $order, int $balanceAfter): array
    {
        if (!$order) {
            throw new WalletSettlementException('Pesanan wallet tidak ditemukan.');
        }

        return [
            'orderId' => $order->id,
            'referenceId' => $order->reference_id,
            'paymentNo' => null,
            'paymentName' => 'LFAMILIA Cash',
            'paymentUrl' => null,
            'fee' => 0,
            'total' => (int) $order->total,
            'expiredAt' => null,
            'paymentStatus' => 'paid',
            'balanceAfter' => $balanceAfter,
            'fulfillmentType' => $order->fulfillment_type,
            'basePrice' => (int) $order->base_subtotal,
            'sellingPrice' => (int) $order->subtotal,
            'discountAmount' => (int) $order->discount_amount,
            'voucherCode' => $order->voucher_code,
            'flashSaleId' => $order->flash_sale_id,
        ];
    }
}
