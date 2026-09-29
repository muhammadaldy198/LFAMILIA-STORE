<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\CheckoutService;
use App\Services\DigiflazzFulfillmentService;
use App\Services\SecurityGuard;
use App\Services\TransactionNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminOrdersController extends Controller
{
    public function index(Request $request, AdminAuthService $auth): JsonResponse
    {
        try {
            $access = $auth->require($request, 'staff');
            $requestedId = trim((string) $request->query('id', ''));

            if ($requestedId !== '') {
                if (!preg_match('/^[0-9a-fA-F-]{36}$/', $requestedId)) {
                    return response()->json(['error' => 'ID pesanan tidak valid.'], 400);
                }

                $order = DB::table('orders')->where('id', $requestedId)->first();
                if (!$order) {
                    return response()->json(['error' => 'Pesanan tidak ditemukan.'], 404);
                }

                $events = DB::table('order_events')
                    ->where('order_id', $order->id)
                    ->orderBy('created_at')
                    ->limit(100)
                    ->get(['id', 'source', 'event_id', 'status', 'payload_json', 'created_at']);

                return response()->json([
                    'order' => $this->visibleOrder($order, (string) $access['role']),
                    'events' => $access['role'] === 'staff'
                        ? $events->map(fn ($event) => [
                            'id' => $event->id,
                            'source' => 'system',
                            'status' => (string) $event->status,
                            'created_at' => $event->created_at,
                        ])->all()
                        : $events->map(fn ($event) => [
                            'id' => $event->id,
                            'source' => (string) $event->source,
                            'event_id' => (string) $event->event_id,
                            'status' => (string) $event->status,
                            'payload_json' => (string) $event->payload_json,
                            'created_at' => $event->created_at,
                        ])->all(),
                    'role' => $access['role'],
                ], 200, ['Cache-Control' => 'no-store']);
            }

            $orders = DB::table('orders')
                ->orderByDesc('created_at')
                ->limit(500)
                ->get()
                ->map(fn ($order) => $this->visibleOrder($order, (string) $access['role']))
                ->all();

            return response()->json([
                'orders' => $orders,
                'role' => $access['role'],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Pesanan gagal dimuat.',
            ], 503);
        }
    }

    public function create(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        CheckoutService $checkout,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $access = $auth->require($request, 'admin');
            $input = $request->validate([
                'customer' => ['required', 'string', 'min:2', 'max:120'],
                'phone' => ['required', 'string', 'min:5', 'max:30'],
                'customerEmail' => ['nullable', 'email', 'max:150'],
                'product' => ['required', 'string', 'min:2', 'max:120'],
                'packageName' => ['required', 'string', 'min:2', 'max:120'],
                'destination' => ['required', 'string', 'min:1', 'max:300'],
                'total' => ['required', 'integer', 'min:1', 'max:100000000'],
                'payment' => ['required', 'in:admin_manual'],
            ]);

            $identity = $checkout->identity();
            $slugBase = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $input['product']) ?? 'order');
            $slugBase = trim($slugBase, '-');
            $slug = 'manual-'.($slugBase !== '' ? $slugBase : 'order');

            DB::transaction(function () use ($identity, $slug, $input, $access): void {
                DB::table('orders')->insert([
                    'id' => $identity['id'],
                    'reference_id' => $identity['referenceId'],
                    'product_slug' => $slug,
                    'product_name' => trim($input['product']),
                    'package_sku' => 'MANUAL-'.strtoupper(substr(str_replace('-', '', $identity['id']), 0, 8)),
                    'package_label' => trim($input['packageName']),
                    'provider_code' => null,
                    'provider_sku' => null,
                    'fulfillment_type' => 'manual',
                    'delivery_mode' => 'manual',
                    'target_template' => '{{destination}}',
                    'destination' => trim($input['destination']),
                    'server' => null,
                    'nickname' => null,
                    'customer_no' => trim($input['destination']),
                    'buyer_name' => trim($input['customer']),
                    'buyer_email' => strtolower(trim((string) ($input['customerEmail'] ?? ''))),
                    'buyer_phone' => trim($input['phone']),
                    'customer_notes' => 'Dibuat manual oleh '.$access['email'],
                    'customer_inputs_json' => json_encode([[
                        'id' => 'destination',
                        'label' => 'Tujuan',
                        'value' => trim($input['destination']),
                    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'quantity' => 1,
                    'base_subtotal' => (int) $input['total'],
                    'subtotal' => (int) $input['total'],
                    'discount_amount' => 0,
                    'admin_fee' => 0,
                    'total' => (int) $input['total'],
                    'payment_method' => 'admin_manual',
                    'payment_channel' => 'admin_manual',
                    'payment_status' => 'paid',
                    'fulfillment_status' => 'manual_pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('order_events')->insert([
                    'order_id' => $identity['id'],
                    'source' => 'admin',
                    'event_id' => 'manual-created-'.$identity['id'],
                    'status' => 'manual_pending',
                    'payload_json' => json_encode([
                        'adminEmail' => $access['email'],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }, 3);

            return response()->json([
                'ok' => true,
                'id' => $identity['id'],
                'referenceId' => $identity['referenceId'],
            ], 201);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Pesanan manual gagal dibuat.',
            ], 400);
        }
    }

    public function update(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        DigiflazzFulfillmentService $fulfillment,
        TransactionNotificationService $notifications,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $access = $auth->require($request, 'staff');
            $input = $request->validate([
                'id' => ['required', 'uuid'],
                'action' => ['required', 'in:complete_manual,refresh_fulfillment,retry_digiflazz'],
                'serialNumber' => ['nullable', 'string', 'max:500'],
            ]);

            $order = DB::table('orders')->where('id', $input['id'])->first();
            if (!$order) {
                return response()->json(['error' => 'Pesanan tidak ditemukan.'], 404);
            }

            if ($input['action'] === 'retry_digiflazz') {
                if ($access['role'] === 'staff') {
                    throw new RuntimeException('Akses panel tidak diizinkan.');
                }

                $fresh = $fulfillment->retryFailedOrder((string) $order->id, (string) $access['email']);

                return response()->json([
                    'ok' => true,
                    'retried' => true,
                    'order' => $fresh ? $this->visibleOrder($fresh, (string) $access['role']) : null,
                ]);
            }

            if ($input['action'] === 'complete_manual') {
                if ($order->fulfillment_type !== 'manual'
                    || $order->payment_status !== 'paid'
                    || $order->fulfillment_status !== 'manual_pending') {
                    throw new RuntimeException('Pesanan manual belum siap diselesaikan atau status sudah berubah.');
                }

                $mode = $this->deliveryMode($order);
                $serial = trim((string) ($input['serialNumber'] ?? ''));
                if ($mode === 'voucher' && $serial === '') {
                    throw new RuntimeException('Kode voucher / serial wajib diisi sebelum pesanan diselesaikan.');
                }

                DB::transaction(function () use ($order, $access, $serial): void {
                    $changed = DB::table('orders')
                        ->where('id', $order->id)
                        ->where('payment_status', 'paid')
                        ->where('fulfillment_status', 'manual_pending')
                        ->update([
                            'fulfillment_status' => 'success',
                            'provider_status' => 'manual_done',
                            'provider_message' => 'Diselesaikan oleh '.$access['email'],
                            'provider_serial_number' => $serial !== ''
                                ? $serial
                                : $order->provider_serial_number,
                            'updated_at' => now(),
                        ]);

                    if ($changed !== 1) {
                        throw new RuntimeException('Pesanan sudah diproses atau status berubah.');
                    }

                    DB::table('order_events')->insert([
                        'order_id' => $order->id,
                        'source' => 'admin',
                        'event_id' => 'manual-complete-'.$order->id.'-'.str_replace('.', '', uniqid('', true)),
                        'status' => 'success',
                        'payload_json' => json_encode([
                            'adminEmail' => $access['email'],
                            'voucherCodeDelivered' => $serial !== '',
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                }, 3);

                $notifications->notifyOrderSuccessById((string) $order->id);

                return response()->json(['ok' => true]);
            }

            if ($order->payment_status !== 'paid') {
                return response()->json([
                    'error' => 'Fulfillment hanya boleh dicek ulang setelah pembayaran tervalidasi lunas.',
                ], 409);
            }
            if ($order->fulfillment_type !== 'automatic') {
                return response()->json([
                    'error' => 'Pesanan manual harus diselesaikan melalui aksi pesanan manual.',
                ], 409);
            }
            if (in_array($order->fulfillment_status, ['success', 'failed', 'cancelled'], true)) {
                return response()->json(['ok' => true, 'refreshed' => false, 'terminal' => true]);
            }

            DB::table('order_events')->insert([
                'order_id' => $order->id,
                'source' => 'admin',
                'event_id' => 'admin-refresh-'.$order->id.'-'.str_replace('.', '', uniqid('', true)),
                'status' => 'refresh_requested',
                'payload_json' => json_encode([
                    'adminEmail' => $access['email'],
                    'providerCode' => strtolower(trim((string) $order->provider_code)) ?: null,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            if (strtolower(trim((string) $order->provider_code)) !== 'digiflazz') {
                return response()->json([
                    'error' => 'Refresh otomatis provider ini belum tersedia pada runtime Laravel.',
                ], 409);
            }

            $fulfillment->fulfillOrder((string) $order->id);
            $fresh = DB::table('orders')->where('id', $order->id)->first();

            return response()->json([
                'ok' => true,
                'refreshed' => true,
                'order' => $fresh ? $this->visibleOrder($fresh, (string) $access['role']) : null,
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Pesanan gagal diperbarui.',
            ], 400);
        }
    }

    private function visibleOrder(object $order, string $role): array
    {
        $all = (array) $order;
        $all['delivery_mode'] = $this->deliveryMode($order);

        if ($role !== 'staff') {
            return $all;
        }

        return [
            'id' => (string) $order->id,
            'reference_id' => (string) $order->reference_id,
            'product_slug' => (string) $order->product_slug,
            'product_name' => (string) $order->product_name,
            'package_sku' => (string) $order->package_sku,
            'package_label' => (string) $order->package_label,
            'destination' => (string) $order->destination,
            'server' => $order->server,
            'nickname' => $order->nickname,
            'buyer_name' => (string) $order->buyer_name,
            'buyer_phone' => (string) $order->buyer_phone,
            'customer_inputs_json' => (string) $order->customer_inputs_json,
            'quantity' => max(1, (int) $order->quantity),
            'total' => null,
            'payment_method' => (string) $order->payment_method,
            'payment_channel' => (string) $order->payment_channel,
            'payment_status' => (string) $order->payment_status,
            'fulfillment_type' => (string) $order->fulfillment_type,
            'fulfillment_status' => (string) $order->fulfillment_status,
            'delivery_mode' => $this->deliveryMode($order),
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];
    }

    private function deliveryMode(object $order): string
    {
        $mode = trim((string) ($order->delivery_mode ?? ''));
        if (in_array($mode, ['direct', 'voucher', 'manual'], true)) {
            return $mode;
        }

        if ($order->fulfillment_type === 'manual') {
            return 'manual';
        }

        return strtolower(trim((string) $order->provider_code)) === 'voucher-stock'
            ? 'voucher'
            : 'direct';
    }

    private function isAccessError(RuntimeException $error): bool
    {
        return str_contains($error->getMessage(), 'Sesi panel')
            || str_contains($error->getMessage(), 'Akses panel');
    }

    private function accessError(RuntimeException $error): JsonResponse
    {
        return response()->json(
            ['error' => $error->getMessage()],
            str_contains($error->getMessage(), 'Sesi panel') ? 401 : 403,
            ['Cache-Control' => 'no-store'],
        );
    }
}
