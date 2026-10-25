<?php

namespace Tests\Unit;

use App\Services\MidtransStatusVerification;
use PHPUnit\Framework\TestCase;

class MidtransPartialRefundTest extends TestCase
{
    public function test_paid_requires_success_status_code_and_acceptable_fraud_status(): void
    {
        $verification = new MidtransStatusVerification;

        foreach (['settlement', 'capture'] as $status) {
            $this->assertSame('PAID', $verification->status([
                'transaction_status' => $status,
                'status_code' => '200',
                'fraud_status' => 'accept',
            ]));
            $this->assertSame('PAID', $verification->status([
                'transaction_status' => $status,
                'status_code' => '200',
            ]), 'A missing fraud status is valid for lower-risk payment types.');

            foreach (['deny', 'challenge'] as $fraud) {
                $this->assertSame('PENDING', $verification->status([
                    'transaction_status' => $status,
                    'status_code' => '200',
                    'fraud_status' => $fraud,
                ]));
            }
            $this->assertSame('PENDING', $verification->status([
                'transaction_status' => $status,
                'status_code' => '201',
                'fraud_status' => 'accept',
            ]));
            $this->assertSame('PENDING', $verification->status([
                'transaction_status' => $status,
                'fraud_status' => 'accept',
            ]));
        }
    }

    public function test_partial_refund_is_not_treated_as_full_refund(): void
    {
        $verification = new MidtransStatusVerification;
        $this->assertSame('PARTIAL_REFUND_REVIEW', $verification->status(['transaction_status' => 'partial_refund']));
        $this->assertSame('REFUNDED', $verification->status(['transaction_status' => 'refund']));
    }
}
