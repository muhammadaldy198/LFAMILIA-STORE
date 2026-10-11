<?php

namespace Tests\Unit;

use App\Services\MidtransStatusVerification;
use PHPUnit\Framework\TestCase;

class MidtransPartialRefundTest extends TestCase
{
    public function test_partial_refund_is_not_treated_as_full_refund(): void
    {
        $verification = new MidtransStatusVerification;
        $this->assertSame('PARTIAL_REFUND_REVIEW', $verification->status(['transaction_status' => 'partial_refund']));
        $this->assertSame('REFUNDED', $verification->status(['transaction_status' => 'refund']));
    }
}
