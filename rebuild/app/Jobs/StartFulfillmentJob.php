<?php

namespace App\Jobs;

use App\Services\FulfillmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StartFulfillmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('fulfillment');
    }

    public function uniqueId(): string
    {
        return 'order:'.$this->orderId;
    }

    public function handle(FulfillmentService $fulfillment): void
    {
        $fulfillment->startOrder($this->orderId);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
