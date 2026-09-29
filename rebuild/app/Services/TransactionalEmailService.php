<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmailJob;
use Illuminate\Support\Facades\DB;

class TransactionalEmailService
{
    public function queue(string $email, string $subject, string $text): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            SendTransactionalEmailJob::dispatch(strtolower($email), $subject, $text)->afterCommit();
        }
    }

    public function queueForOrder(int $orderId, string $subject, string $text): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['user_id', 'guest_email']);
        if (! $order) {
            return;
        }

        $email = $order->user_id
            ? DB::table('users')->where('id', $order->user_id)->value('email')
            : $order->guest_email;

        if (is_string($email)) {
            $this->queue($email, $subject, $text);
        }
    }
}
