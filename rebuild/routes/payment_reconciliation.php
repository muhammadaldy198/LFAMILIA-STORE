<?php

use App\Services\MidtransStatusVerification;
use App\Services\Payment\MidtransGateway;
use App\Services\PaymentStateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('lfamilia:reconcile-pending-payments', function (
    MidtransGateway $midtrans,
    MidtransStatusVerification $verification,
    PaymentStateService $states,
): int {
    $checked = 0;
    $resolved = 0;

    // Only read existing transactions. Never create a new invoice or send funds.
    DB::table('payment_transactions')
        ->where('gateway_code', 'MIDTRANS')
        ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])
        ->whereNotNull('merchant_reference')
        ->where('created_at', '>=', now()->subDays(2))
        ->where('created_at', '<=', now()->subMinutes(2))
        ->where('updated_at', '<=', now()->subMinutes(2))
        ->orderBy('id')
        ->chunkById(100, function ($payments) use ($midtrans, $verification, $states, &$checked, &$resolved): void {
            foreach ($payments as $payment) {
                // Atomically throttle simultaneous scheduler invocations and retries.
                $claimed = DB::table('payment_transactions')
                    ->where('id', $payment->id)
                    ->where('status', $payment->status)
                    ->where('updated_at', $payment->updated_at)
                    ->update(['updated_at' => now()]);
                if ($claimed !== 1) {
                    continue;
                }

                $checked++;
                try {
                    $verified = $midtrans->status((string) $payment->merchant_reference);
                    if (hash_equals((string) $payment->merchant_reference, (string) $verified['order_id']) === false
                        || $verification->amount($verified['gross_amount']) !== (int) $payment->amount_idr) {
                        Log::warning('Scheduled payment verification rejected', [
                            'payment_transaction_id' => $payment->id,
                            'reason' => 'IDENTITY_OR_AMOUNT_MISMATCH',
                        ]);
                        continue;
                    }

                    $status = $verification->status($verified);
                    $states->apply((int) $payment->id, $status, ['source' => 'scheduled_status_check']);
                    if ($status !== 'PENDING') {
                        $resolved++;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Scheduled payment status unavailable', [
                        'payment_transaction_id' => $payment->id,
                        'exception' => class_basename($exception),
                    ]);
                }
            }
        });

    $this->info("Payment status checks: {$checked}; terminal responses: {$resolved}");

    return 0;
})->purpose('Recover payment settlements missed by callbacks using verified Midtrans status');

Schedule::command('lfamilia:reconcile-pending-payments')->everyMinute()->withoutOverlapping(10);

