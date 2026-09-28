<?php

namespace App\Http\Controllers;

use App\Services\DigiflazzFulfillmentService;
use App\Services\DokuCheckoutService;
use App\Services\MidtransSnapService;
use App\Services\PaymentTransitionService;
use App\Services\SecurityGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderStatusController extends Controller
{
    public function show(
        Request $request,
        SecurityGuard $security,
        DokuCheckoutService $doku,
        MidtransSnapService $midtrans,
        PaymentTransitionService $transitions,
        DigiflazzFulfillmentService $fulfillment,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $rate = $security->rateLimit($request, 'order-status', 240, 600);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak pengecekan transaksi. Coba lagi beberapa menit.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        try {
            $input = $request->validate([
                'referenceId' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            ]);
            $referenceId = strtoupper(trim($input['referenceId']));
            $order = $this->resolveOrder($referenceId);

            if (!$order) {
                return response()->json(['error' => 'Invoice tidak ditemukan.'], 404, [
                    'Cache-Control' => 'no-store',
                ]);
            }

            if ($order->payment_status === 'pending') {
                $order = $this->refreshGateway($order, $doku, $midtrans, $transitions, $fulfillment);
            }

            if ($order->payment_status === 'paid'
                && $order->fulfillment_type === 'automatic'
                && !in_array($order->fulfillment_status, ['success', 'failed', 'cancelled'], true)) {
                $fulfillment->fulfillOrder((string) $order->id);
                $order = DB::table('orders')->where('id', $order->id)->first() ?: $order;
            }

            $events = DB::table('order_events')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(50)
                ->get(['source', 'status', 'created_at'])
                ->map(fn ($event) => [
                    'source' => $this->publicEventSource((string) $event->source),
                    'status' => (string) $event->status,
                    'createdAt' => $event->created_at,
                ])
                ->values()
                ->all();

            array_unshift($events, [
                'source' => 'system',
                'status' => 'created',
                'createdAt' => $order->created_at,
            ]);

            $pending = $order->payment_status === 'pending';
            $internalVoucherDestination = trim((string) $order->destination) === '00000000';

            return response()->json([
                'order' => [
                    'referenceId' => $this->publicReferenceId((string) $order->reference_id),
                    'productName' => (string) $order->product_name,
                    'packageLabel' => (string) $order->package_label,
                    'destination' => $internalVoucherDestination
                        ? null
                        : $this->maskDestination((string) $order->destination, $order->server),
                    'productTotal' => max(0, (int) $order->total - (int) $order->admin_fee),
                    'paymentFee' => (int) $order->admin_fee,
                    'total' => (int) $order->total,
                    'paymentMethod' => (string) $order->payment_method,
                    'paymentChannel' => (string) $order->payment_channel,
                    'paymentStatus' => (string) $order->payment_status,
                    'fulfillmentStatus' => (string) $order->fulfillment_status,
                    'fulfillmentType' => (string) $order->fulfillment_type,
                    'paymentNo' => $pending ? $order->gateway_payment_no : null,
                    'qrContent' => $pending ? $order->gateway_qr_content : null,
                    'paymentName' => $this->paymentName((string) $order->payment_method, (string) $order->payment_channel),
                    'paymentUrl' => $pending ? $order->gateway_payment_url : null,
                    'expiredAt' => $pending ? $order->gateway_expired_at : null,
                    'voucherCode' => null,
                    'createdAt' => $order->created_at,
                    'updatedAt' => $order->updated_at,
                    'events' => $events,
                ],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (ValidationException) {
            return response()->json(['error' => 'Format invoice tidak valid.'], 400, [
                'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable) {
            return response()->json(['error' => 'Status pesanan belum dapat dimuat.'], 503, [
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    private function resolveOrder(string $referenceId): ?object
    {
        $exact = DB::table('orders')->where('reference_id', $referenceId)->first();
        if ($exact) {
            return $exact;
        }

        if (preg_match('/^LF[A-F0-9]{8,12}$/', $referenceId)) {
            $token = substr($referenceId, 2);

            return DB::table('orders')
                ->where('reference_id', 'like', '%-'.$token)
                ->first();
        }

        return null;
    }

    private function refreshGateway(
        object $order,
        DokuCheckoutService $doku,
        MidtransSnapService $midtrans,
        PaymentTransitionService $transitions,
        DigiflazzFulfillmentService $fulfillment,
    ): object {
        $environment = in_array($order->payment_gateway_environment, ['sandbox', 'production'], true)
            ? (string) $order->payment_gateway_environment
            : null;

        if (!$environment || !$this->dueForCheck($order)) {
            return $order;
        }

        try {
            DB::table('orders')
                ->where('id', $order->id)
                ->where('payment_status', 'pending')
                ->update(['gateway_status_checked_at' => now()]);

            if ($order->payment_gateway === 'midtrans' && $order->payment_gateway_mode === 'snap') {
                $query = $midtrans->queryStatus((string) $order->reference_id, $environment);
                if ($query['status'] === 'paid' && $query['amount'] !== (int) $order->total) {
                    return DB::table('orders')->where('id', $order->id)->first() ?: $order;
                }

                $eventSuffix = $query['transactionId'] ?: $order->reference_id;
                $eventId = 'snap-status-'.$eventSuffix.'-'.$query['status'];
                $transition = $query['status'] === 'ignore'
                    ? ['firstPaid' => false, ...$transitions->recordOrderEvent(
                        (string) $order->reference_id,
                        'midtrans',
                        $eventId,
                        'ignore',
                        $query['raw'],
                    )]
                    : $transitions->applyOrderEvent(
                        (string) $order->reference_id,
                        'midtrans',
                        $eventId,
                        $query['status'],
                        $query['raw'],
                        $query['status'] === 'paid',
                        $query['status'] === 'expired',
                    );

                if (($transition['firstPaid'] ?? false) && $order->fulfillment_type === 'automatic') {
                    $fulfillment->fulfillOrder((string) $order->id);
                }
            } elseif ($order->payment_gateway === 'doku' && $order->payment_gateway_mode === 'checkout') {
                $query = $doku->queryStatus((string) $order->reference_id, $environment);

                DB::table('order_events')->insertOrIgnore([
                    'order_id' => $order->id,
                    'source' => 'doku',
                    'event_id' => 'checkout-status-'.$order->reference_id.'-'.$query['status'],
                    'status' => $query['status'],
                    'payload_json' => json_encode($query['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);

                if ($query['status'] === 'paid' && $query['amount'] !== (int) $order->total) {
                    return DB::table('orders')->where('id', $order->id)->first() ?: $order;
                }

                $transition = $transitions->applyOrderEvent(
                    (string) $order->reference_id,
                    'doku',
                    'checkout-transition-'.$order->reference_id.'-'.$query['status'],
                    $query['status'],
                    $query['raw'],
                    $query['status'] === 'paid',
                    $query['status'] === 'expired',
                );

                if ($transition['firstPaid'] && $order->fulfillment_type === 'automatic') {
                    $fulfillment->fulfillOrder((string) $order->id);
                }
            }
        } catch (Throwable) {
            // Status endpoint must remain available even when the provider is
            // temporarily unreachable; the last trusted local state is returned.
        }

        return DB::table('orders')->where('id', $order->id)->first() ?: $order;
    }

    private function dueForCheck(object $order): bool
    {
        try {
            $created = CarbonImmutable::parse((string) $order->created_at);
            $minimum = $order->payment_gateway === 'midtrans' ? 3 : 60;
            if ($created->diffInSeconds(now(), false) < $minimum) {
                return false;
            }

            if (!$order->gateway_status_checked_at) {
                return true;
            }

            $last = CarbonImmutable::parse((string) $order->gateway_status_checked_at);

            return $last->diffInSeconds(now(), false) >= $minimum;
        } catch (Throwable) {
            return true;
        }
    }

    private function publicReferenceId(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^LF\d{6}[A-F0-9]{12,32}$/', $value) || preg_match('/^LF[A-F0-9]{8,12}$/', $value)) {
            return $value;
        }

        $parts = explode('-', $value);
        $token = end($parts) ?: $value;

        return 'LF'.$token;
    }

    private function maskDestination(string $value, ?string $server): string
    {
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);
        $visible = $length <= 6
            ? mb_substr($trimmed, 0, 2).str_repeat('•', max($length - 3, 1)).mb_substr($trimmed, -1)
            : mb_substr($trimmed, 0, 3).str_repeat('•', min($length - 5, 8)).mb_substr($trimmed, -2);

        return $server ? $visible.' ('.$server.')' : $visible;
    }

    private function publicEventSource(string $source): string
    {
        return match ($source) {
            'doku', 'midtrans' => 'payment',
            'digiflazz' => 'processing',
            'wallet' => 'balance',
            'voucher_stock' => 'delivery',
            'admin' => 'admin',
            default => 'system',
        };
    }

    private function paymentName(string $method, string $channel): string
    {
        return $method === 'qris'
            ? 'QRIS'
            : ($method === 'va' ? 'Virtual Account '.strtoupper($channel) : strtoupper($channel));
    }
}
