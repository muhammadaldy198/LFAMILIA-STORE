<?php

namespace App\Jobs;

use App\Services\FulfillmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFulfillmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $attemptId)
    {
        $this->onQueue('fulfillment');
    }

    public function uniqueId(): string
    {
        return 'send:'.$this->attemptId;
    }

    public function handle(FulfillmentService $fulfillment): void
    {
        $fulfillment->sendAttempt($this->attemptId);
    }

    public function backoff(): array
    {
        return [15, 45];
    }
}
