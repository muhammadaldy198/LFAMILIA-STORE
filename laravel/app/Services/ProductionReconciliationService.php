<?php

namespace App\Services;

use App\Exceptions\MidtransTransactionNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProductionReconciliationService
{
    public function __construct(
        private readonly MidtransSnapService $midtrans,
        private readonly DokuCheckoutService $doku,
        private readonly PaymentTransitionService $payments,
        private readonly ExternalWalletSettlementService $wallet,
        private readonly DigiflazzFulfillmentService $fulfillment,
        private readonly PromotionService $promotions,
        private readonly TransactionNotificationService $notifications,
    ) {
    }

    /** @return array<string,int> */
    public function run(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $result = [
            'midtrans_orders_checked' => 0,
            'midtrans_topups_checked' => 0,
            'doku_orders_checked' => 0,
            'doku_topups_checked' => 0,
            'digiflazz_retries' => 0,
            'promotions_repaired' => 0,
            'rate_limit_rows_deleted' => 0,
        ];

        $result['midtrans_orders_checked'] = $this->reconcileMidtransOrders($limit);
        $result['midtrans_topups_checked'] = $this->reconcileMidtransTopups($limit);
        $result['doku_orders_checked'] = $this->reconcileDokuOrders($limit);
        $result['doku_topups_checked'] = $this->reconcileDokuTopups($limit);
        $result['promotions_repaired'] = $this->repairPromotionReservations($limit);
        $result['digiflazz_retries'] = $this->recoverDigiflazz($limit);
        $result['rate_limit_rows_deleted'] = DB::table('security_rate_limits')
            ->where('bucket_start', '<', time() - 86400)
            ->delete();

        return $result;
    }

    private function reconcileMidtransOrders(int $limit): int
    {
        $rows = DB::table('orders')
            ->where('payment_status', 'pending')
            ->where('payment_gateway', 'midtrans')
            ->where('payment_gateway_mode', 'snap')
            ->whereIn('payment_gateway_environment', ['sandbox', 'production'])
            ->where('created_at', '<=', now()->subSeconds(60))
            ->where(function ($query) {
                $query->whereNull('gateway_status_checked_at')
                    ->orWhere('gateway_status_checked_at', '<=', now()->subSeconds(60));
            })
            ->orderByRaw('COALESCE(gateway_status_checked_at, created_at) ASC')
            ->limit($limit)
            ->get();

        $checked = 0;

        foreach ($rows as $order) {
            try {
                DB::table('orders')->where('id', $order->id)->where('payment_status', 'pending')->update([
                    'gateway_status_checked_at' => now(),
                ]);

                $query = $this->midtrans->queryStatus(
                    (string) $order->reference_id,
                    (string) $order->payment_gateway_environment,
                );
                $checked++;

                if ($query['status'] === 'paid' && $query['amount'] !== (int) $order->total) {
                    continue;
                }

                $eventId = 'scheduler-snap-'
                    .($query['transactionId'] ?: $order->reference_id)
                    .'-'.$query['status'];

                if ($query['status'] === 'ignore') {
                    $this->payments->recordOrderEvent(
                        (string) $order->reference_id,
                        'midtrans',
                        $eventId,
                        'ignore',
                        $query['raw'],
                    );
                    continue;
                }

                $transition = $this->payments->applyOrderEvent(
                    (string) $order->reference_id,
                    'midtrans',
                    $eventId,
                    $query['status'],
                    $query['raw'],
                    $query['status'] === 'paid',
                    $query['status'] === 'expired',
                );

                if ($transition['firstPaid'] && $order->fulfillment_type === 'automatic') {
                    $this->fulfillment->fulfillOrder((string) $order->id);
                }
            } catch (MidtransTransactionNotFoundException) {
                if ($this->olderThan($order->created_at, 70 * 60)) {
                    $transition = $this->payments->applyOrderEvent(
                        (string) $order->reference_id,
                        'midtrans',
                        'confirmed-missing-'.$order->id,
                        'expired',
                        ['reason' => 'midtrans_transaction_not_found_after_safe_window'],
                        false,
                        true,
                    );
                    if (!$transition['inserted']) {
                        continue;
                    }
                }
            } catch (Throwable) {
                // Keep last trusted state. The next scheduler run retries.
            }
        }

        return $checked;
    }

    private function reconcileMidtransTopups(int $limit): int
    {
        $rows = DB::table('wallet_topups')
            ->where('payment_gateway', 'midtrans')
            ->where('payment_gateway_mode', 'snap')
            ->where('status', 'pending')
            ->whereIn('gateway_environment', ['sandbox', 'production'])
            ->where('created_at', '<=', now()->subSeconds(60))
            ->where('updated_at', '<=', now()->subSeconds(60))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;

        foreach ($rows as $topup) {
            try {
                DB::table('wallet_topups')->where('id', $topup->id)->where('status', 'pending')->update([
                    'updated_at' => now(),
                ]);

                $query = $this->midtrans->queryStatus(
                    (string) $topup->reference_id,
                    (string) $topup->gateway_environment,
                );
                $checked++;

                if ($query['status'] === 'paid'
                    && $query['amount'] !== (int) ($topup->payment_total ?: $topup->amount)) {
                    continue;
                }

                if (!in_array($query['status'], ['paid', 'expired', 'failed'], true)) {
                    continue;
                }

                $settlement = $this->wallet->apply(
                    (string) $topup->reference_id,
                    'midtrans',
                    $query['status'],
                    (int) $query['amount'],
                    null,
                    $query['status'] === 'paid',
                );

                if ($settlement['credited']) {
                    $this->notifications->notifyWalletTopupSuccessById((string) $topup->id);
                }
            } catch (MidtransTransactionNotFoundException) {
                if ($this->olderThan($topup->created_at, 70 * 60)) {
                    DB::table('wallet_topups')
                        ->where('id', $topup->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'rejected',
                            'admin_notes' => 'Transaksi tidak ditemukan di Midtrans setelah batas verifikasi.',
                            'updated_at' => now(),
                        ]);
                }
            } catch (Throwable) {
                // Leave pending and retry later.
            }
        }

        return $checked;
    }

    private function reconcileDokuOrders(int $limit): int
    {
        $recentRejectedCutoff = now()->subHours(24);

        $rows = DB::table('orders')
            ->where('payment_gateway', 'doku')
            ->where('payment_gateway_mode', 'checkout')
            ->whereIn('payment_gateway_environment', ['sandbox', 'production'])
            ->where('created_at', '<=', now()->subSeconds(60))
            ->where(function ($query) use ($recentRejectedCutoff) {
                $query->where('payment_status', 'pending')
                    ->orWhere(function ($late) use ($recentRejectedCutoff) {
                        $late->where('payment_status', 'expired')
                            ->whereNotNull('gateway_expired_at')
                            ->where('gateway_expired_at', '>=', $recentRejectedCutoff);
                    });
            })
            ->where(function ($query) {
                $query->whereNull('gateway_status_checked_at')
                    ->orWhere('gateway_status_checked_at', '<=', now()->subSeconds(60));
            })
            ->orderByRaw('COALESCE(gateway_status_checked_at, created_at) ASC')
            ->limit($limit)
            ->get();

        $checked = 0;

        foreach ($rows as $order) {
            try {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->whereIn('payment_status', ['pending', 'expired'])
                    ->update(['gateway_status_checked_at' => now()]);

                $query = $this->doku->queryStatus(
                    (string) $order->reference_id,
                    (string) $order->payment_gateway_environment,
                );
                $checked++;

                DB::table('order_events')->insertOrIgnore([
                    'order_id' => $order->id,
                    'source' => 'doku',
                    'event_id' => 'scheduler-checkout-'.$order->reference_id.'-'.$query['status'],
                    'status' => $query['status'],
                    'payload_json' => json_encode($query['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);

                if ($query['status'] === 'paid'
                    && $query['amount'] !== (int) $order->total) {
                    continue;
                }

                if (!in_array($query['status'], ['paid', 'expired', 'failed'], true)) {
                    continue;
                }

                $transition = $this->payments->applyOrderEvent(
                    (string) $order->reference_id,
                    'doku',
                    'scheduler-transition-'.$order->reference_id.'-'.$query['status'],
                    $query['status'],
                    $query['raw'],
                    $query['status'] === 'paid',
                    $query['status'] === 'expired',
                );

                if ($transition['firstPaid'] && $order->fulfillment_type === 'automatic') {
                    $this->fulfillment->fulfillOrder((string) $order->id);
                }
            } catch (Throwable) {
                // Provider status remains authoritative; retry later.
            }
        }

        return $checked;
    }

    private function reconcileDokuTopups(int $limit): int
    {
        $recentRejectedCutoff = now()->subHours(24);

        $rows = DB::table('wallet_topups')
            ->where('payment_gateway', 'doku')
            ->where('payment_gateway_mode', 'checkout')
            ->whereIn('gateway_environment', ['sandbox', 'production'])
            ->where('created_at', '<=', now()->subSeconds(60))
            ->where('updated_at', '<=', now()->subSeconds(60))
            ->where(function ($query) use ($recentRejectedCutoff) {
                $query->where('status', 'pending')
                    ->orWhere(function ($late) use ($recentRejectedCutoff) {
                        $late->where('status', 'rejected')
                            ->where('admin_notes', 'Pembayaran kedaluwarsa.')
                            ->whereNotNull('gateway_expired_at')
                            ->where('gateway_expired_at', '>=', $recentRejectedCutoff);
                    });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;

        foreach ($rows as $topup) {
            try {
                DB::table('wallet_topups')
                    ->where('id', $topup->id)
                    ->whereIn('status', ['pending', 'rejected'])
                    ->update(['updated_at' => now()]);

                $query = $this->doku->queryStatus(
                    (string) $topup->reference_id,
                    (string) $topup->gateway_environment,
                );
                $checked++;

                if ($query['status'] === 'paid'
                    && $query['amount'] !== (int) ($topup->payment_total ?: $topup->amount)) {
                    continue;
                }

                if (!in_array($query['status'], ['paid', 'expired', 'failed'], true)) {
                    continue;
                }

                $settlement = $this->wallet->apply(
                    (string) $topup->reference_id,
                    'doku',
                    $query['status'],
                    (int) $query['amount'],
                    $query['originalRequestId'],
                    $query['status'] === 'paid',
                );

                if ($settlement['credited']) {
                    $this->notifications->notifyWalletTopupSuccessById((string) $topup->id);
                }
            } catch (Throwable) {
                // Leave the last trusted state and retry.
            }
        }

        return $checked;
    }

    private function repairPromotionReservations(int $limit): int
    {
        $rows = DB::table('promotion_reservations')
            ->where('status', 'reserved')
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $changed = 0;

        foreach ($rows as $reservation) {
            $order = DB::table('orders')->where('id', $reservation->order_id)->first([
                'id', 'payment_status',
            ]);

            if ($order?->payment_status === 'paid') {
                $changed += $this->consumeReservation((string) $reservation->order_id) ? 1 : 0;
                continue;
            }

            if ($order && $order->payment_status === 'pending') {
                continue;
            }

            try {
                if (CarbonImmutable::parse((string) $reservation->expires_at)->isFuture()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $this->promotions->releaseExternal((string) $reservation->order_id);
            $changed++;
        }

        return $changed;
    }

    private function consumeReservation(string $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $reservation = DB::table('promotion_reservations')
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$reservation || $reservation->status !== 'reserved') {
                return false;
            }

            DB::table('promotion_reservations')->where('order_id', $orderId)->update([
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

            return true;
        }, 3);
    }

    private function recoverDigiflazz(int $limit): int
    {
        $rows = DB::table('orders')
            ->where('payment_status', 'paid')
            ->where('fulfillment_type', 'automatic')
            ->whereRaw("LOWER(TRIM(provider_code)) = 'digiflazz'")
            ->whereNotIn('fulfillment_status', ['success', 'failed', 'cancelled'])
            ->where('created_at', '>=', now()->subDays(89))
            ->where(function ($query) {
                $query->where(function ($single) {
                    $single->whereIn('provider_status', ['processing', 'dispatching', 'retryable_error'])
                        ->where('updated_at', '<=', now()->subMinutes(2));
                })->orWhereNull('provider_status');
            })
            ->orderBy('updated_at')
            ->limit(min($limit, 20))
            ->get(['id']);

        foreach ($rows as $order) {
            try {
                $this->fulfillment->fulfillOrder((string) $order->id);
            } catch (Throwable) {
                // The fulfillment service keeps retryable state itself.
            }
        }

        return $rows->count();
    }

    private function olderThan(mixed $createdAt, int $seconds): bool
    {
        try {
            return CarbonImmutable::parse((string) $createdAt)
                ->lessThanOrEqualTo(now()->subSeconds($seconds));
        } catch (Throwable) {
            return false;
        }
    }
}
