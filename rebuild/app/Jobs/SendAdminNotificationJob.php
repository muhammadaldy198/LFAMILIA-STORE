<?php

namespace App\Jobs;

use App\Services\ExternalNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAdminNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(ExternalNotificationService $service): void
    {
        $service->deliver($this->notificationId);
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }
}
