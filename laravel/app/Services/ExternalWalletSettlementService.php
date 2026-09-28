<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalWalletSettlementService
{
    /**
     * @return array{found:bool,credited:bool,ignored:?string}
     */
    public function apply(
        string $referenceId,
        string $gateway,
        string $status,
        int $callbackAmount,
        ?string $originalRequestId = null,
        bool $authoritativePaid = false,
    ): array {
        return DB::transaction(function () use (
            $referenceId,
            $gateway,
            $status,
            $callbackAmount,
            $originalRequestId,
            $authoritativePaid,
        ): array {
            $topup = DB::table('wallet_topups')
                ->where('reference_id', $referenceId)
                ->where('payment_gateway', $gateway)
                ->lockForUpdate()
                ->first();

            if (!$topup) {
                return ['found' => false, 'credited' => false, 'ignored' => null];
            }

            if ($topup->gateway_request_id && $originalRequestId
                && !hash_equals((string) $topup->gateway_request_id, $originalRequestId)) {
                return ['found' => true, 'credited' => false, 'ignored' => 'request_mismatch'];
            }

            $expectedAmount = (int) ($topup->payment_total ?: $topup->amount);
            if ($status === 'paid' && ($callbackAmount <= 0 || $callbackAmount !== $expectedAmount)) {
                return ['found' => true, 'credited' => false, 'ignored' => 'amount_mismatch'];
            }

            if ($status === 'paid') {
                $allowed = $topup->status === 'pending'
                    || ($authoritativePaid
                        && $topup->status === 'rejected'
                        && in_array((string) $topup->admin_notes, [
                            'Pembayaran kedaluwarsa.',
                            'Pembayaran DOKU kedaluwarsa.',
                        ], true));

                if (!$allowed) {
                    return ['found' => true, 'credited' => false, 'ignored' => 'terminal'];
                }

                $ledgerReference = 'topup:'.$topup->id;
                if (DB::table('wallet_transactions')->where('reference', $ledgerReference)->exists()) {
                    return ['found' => true, 'credited' => false, 'ignored' => 'duplicate'];
                }

                $customer = DB::table('customer_users')
                    ->where('id', $topup->customer_id)
                    ->lockForUpdate()
                    ->first(['id', 'is_active']);

                if (!$customer || !(bool) $customer->is_active) {
                    return ['found' => true, 'credited' => false, 'ignored' => 'customer_inactive'];
                }

                $balanceBefore = (int) DB::table('wallet_transactions')
                    ->where('customer_id', $topup->customer_id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance")
                    ->value('balance');
                $balanceAfter = $balanceBefore + (int) $topup->amount;

                DB::table('wallet_transactions')->insert([
                    'id' => (string) Str::uuid(),
                    'customer_id' => $topup->customer_id,
                    'direction' => 'credit',
                    'amount' => (int) $topup->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference' => $ledgerReference,
                    'description' => 'Top up otomatis '.strtoupper($gateway).' '.strtoupper(substr((string) $topup->id, 0, 8)),
                    'created_at' => now(),
                ]);

                DB::table('wallet_topups')->where('id', $topup->id)->update([
                    'status' => 'approved',
                    'admin_notes' => null,
                    'reviewed_by' => $gateway.'-callback',
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('customer_users')->where('id', $topup->customer_id)->update([
                    'balance' => $balanceAfter,
                    'updated_at' => now(),
                ]);

                return ['found' => true, 'credited' => true, 'ignored' => null];
            }

            if (in_array($status, ['expired', 'failed'], true) && $topup->status === 'pending') {
                DB::table('wallet_topups')->where('id', $topup->id)->update([
                    'status' => 'rejected',
                    'admin_notes' => $status === 'expired' ? 'Pembayaran kedaluwarsa.' : 'Pembayaran gagal.',
                    'updated_at' => now(),
                ]);
            }

            return ['found' => true, 'credited' => false, 'ignored' => null];
        }, 3);
    }
}
