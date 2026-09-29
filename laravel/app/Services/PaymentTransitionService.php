<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PaymentTransitionService
{
    /**
     * Apply one gateway event exactly once.
     *
     * Paid callbacks may recover a locally-expired invoice only when the
     * caller has already cryptographically authenticated the provider event.
     *
     * @return array{found:bool,inserted:bool,changed:bool,firstPaid:bool}
     */
    public function applyOrderEvent(
        string $referenceId,
        string $source,
        string $eventId,
        string $status,
        mixed $payload,
        bool $authoritativePaid = false,
        bool $authoritativeExpired = false,
    ): array {
        return DB::transaction(function () use (
            $referenceId,
            $source,
            $eventId,
            $status,
            $payload,
            $authoritativePaid,
            $authoritativeExpired,
        ): array {
            $order = DB::table('orders')
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return ['found' => false, 'inserted' => false, 'changed' => false, 'firstPaid' => false];
            }

            $alreadyRecorded = DB::table('order_events')
                ->where('source', $source)
                ->where('event_id', $eventId)
                ->exists();

            if ($alreadyRecorded) {
                return ['found' => true, 'inserted' => false, 'changed' => false, 'firstPaid' => false];
            }

            DB::table('order_events')->insert([
                'order_id' => $order->id,
                'source' => $source,
                'event_id' => $eventId,
                'status' => $status,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            if ($status === 'pending') {
                return ['found' => true, 'inserted' => true, 'changed' => false, 'firstPaid' => false];
            }

            $current = (string) $order->payment_status;
            $changed = false;
            $firstPaid = false;

            if ($status === 'paid') {
                $allowed = $current === 'pending'
                    || ($authoritativePaid && $current === 'expired');

                if ($allowed && (!$this->locallyExpired($order) || $authoritativePaid)) {
                    $nextFulfillment = $order->fulfillment_type === 'manual'
                        ? 'manual_pending'
                        : 'processing';

                    DB::table('orders')->where('id', $order->id)->update([
                        'payment_status' => 'paid',
                        'fulfillment_status' => $nextFulfillment,
                        'updated_at' => now(),
                    ]);
                    $this->consumePromotion($order);
                    $changed = true;
                    $firstPaid = true;
                } elseif ($current === 'pending' && $this->locallyExpired($order)) {
                    DB::table('orders')->where('id', $order->id)->update([
                        'payment_status' => 'expired',
                        'updated_at' => now(),
                    ]);
                    $this->releasePromotion((string) $order->id);
                    $changed = true;
                }
            } elseif ($status === 'expired') {
                if ($current === 'pending' && ($authoritativeExpired || $this->locallyExpired($order))) {
                    DB::table('orders')->where('id', $order->id)->update([
                        'payment_status' => 'expired',
                        'updated_at' => now(),
                    ]);
                    $this->releasePromotion((string) $order->id);
                    $changed = true;
                }
            } elseif ($status === 'failed' && $current === 'pending') {
                DB::table('orders')->where('id', $order->id)->update([
                    'payment_status' => 'failed',
                    'updated_at' => now(),
                ]);
                $this->releasePromotion((string) $order->id);
                $changed = true;
            }

            return [
                'found' => true,
                'inserted' => true,
                'changed' => $changed,
                'firstPaid' => $firstPaid,
            ];
        }, 3);
    }

    /** @return array{found:bool,inserted:bool} */
    public function recordOrderEvent(
        string $referenceId,
        string $source,
        string $eventId,
        string $status,
        mixed $payload,
    ): array {
        return DB::transaction(function () use ($referenceId, $source, $eventId, $status, $payload): array {
            $order = DB::table('orders')->where('reference_id', $referenceId)->first(['id']);
            if (!$order) {
                return ['found' => false, 'inserted' => false];
            }

            $inserted = DB::table('order_events')->insertOrIgnore([
                'order_id' => $order->id,
                'source' => $source,
                'event_id' => $eventId,
                'status' => $status,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return ['found' => true, 'inserted' => $inserted > 0];
        }, 3);
    }

    private function locallyExpired(object $order): bool
    {
        $value = $order->gateway_expired_at ?? null;
        if (!$value) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $value)->lessThanOrEqualTo(now());
        } catch (\Throwable) {
            return false;
        }
    }

    private function consumePromotion(object $order): void
    {
        $reservation = DB::table('promotion_reservations')
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($reservation) {
            if ($reservation->status === 'consumed') {
                return;
            }

            if ($reservation->status === 'reserved') {
                DB::table('promotion_reservations')->where('order_id', $order->id)->update([
                    'status' => 'consumed',
                    'updated_at' => now(),
                ]);

                if ($reservation->voucher_code) {
                    DB::table('discount_vouchers')->where('code', $reservation->voucher_code)->update([
                        'reserved_count' => DB::raw('CASE WHEN reserved_count > 0 THEN reserved_count - 1 ELSE 0 END'),
                        'used_count' => DB::raw('used_count + 1'),
                        'updated_at' => now(),
                    ]);
                }
                if ($reservation->flash_sale_id) {
                    DB::table('flash_sales')->where('id', $reservation->flash_sale_id)->update([
                        'reserved_count' => DB::raw('CASE WHEN reserved_count > 0 THEN reserved_count - 1 ELSE 0 END'),
                        'sold_count' => DB::raw('sold_count + 1'),
                        'updated_at' => now(),
                    ]);
                }

                return;
            }

            // A signed late payment can legitimately arrive after local expiry.
            // Reclaim a released reservation exactly once without decrementing
            // reserved_count again because release already returned capacity.
            if ($reservation->status === 'released') {
                DB::table('promotion_reservations')->where('order_id', $order->id)->update([
                    'status' => 'consumed',
                    'updated_at' => now(),
                ]);

                if ($reservation->voucher_code) {
                    DB::table('discount_vouchers')->where('code', $reservation->voucher_code)->increment('used_count');
                }
                if ($reservation->flash_sale_id) {
                    DB::table('flash_sales')->where('id', $reservation->flash_sale_id)->increment('sold_count');
                }

                return;
            }
        }

        if ($order->voucher_code) {
            DB::table('discount_vouchers')->where('code', $order->voucher_code)->increment('used_count');
        }
        if ($order->flash_sale_id) {
            DB::table('flash_sales')->where('id', $order->flash_sale_id)->increment('sold_count');
        }
    }

    private function releasePromotion(string $orderId): void
    {
        $reservation = DB::table('promotion_reservations')
            ->where('order_id', $orderId)
            ->lockForUpdate()
            ->first();

        if (!$reservation || $reservation->status !== 'reserved') {
            return;
        }

        DB::table('promotion_reservations')->where('order_id', $orderId)->update([
            'status' => 'released',
            'updated_at' => now(),
        ]);

        if ($reservation->voucher_code) {
            DB::table('discount_vouchers')->where('code', $reservation->voucher_code)->update([
                'reserved_count' => DB::raw('CASE WHEN reserved_count > 0 THEN reserved_count - 1 ELSE 0 END'),
                'updated_at' => now(),
            ]);
        }
        if ($reservation->flash_sale_id) {
            DB::table('flash_sales')->where('id', $reservation->flash_sale_id)->update([
                'reserved_count' => DB::raw('CASE WHEN reserved_count > 0 THEN reserved_count - 1 ELSE 0 END'),
                'updated_at' => now(),
            ]);
        }
    }
}
