<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentCallbackSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_midtrans_paid_callback_is_signature_checked_idempotent_and_pending_cannot_regress_paid(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'test-midtrans-server-key',
            'clientKey' => 'test-midtrans-client-key',
        ]);

        $this->insertOrder('midtrans-ref', 'midtrans', 'snap', 'sandbox', 15000);

        $paid = [
            'order_id' => 'midtrans-ref',
            'status_code' => '200',
            'gross_amount' => '15000.00',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'tx-1',
        ];
        $paid['signature_key'] = hash(
            'sha512',
            $paid['order_id'].$paid['status_code'].$paid['gross_amount'].'test-midtrans-server-key',
        );

        $this->postJson('/api/payments/midtrans/snap/notification', $paid)->assertOk();
        $this->postJson('/api/payments/midtrans/snap/notification', $paid)->assertOk();

        $this->assertDatabaseHas('orders', [
            'reference_id' => 'midtrans-ref',
            'payment_status' => 'paid',
        ]);
        $this->assertSame(1, DB::table('order_events')
            ->where('source', 'midtrans')
            ->where('event_id', 'snap-tx-1-paid')
            ->count());

        $pending = [
            'order_id' => 'midtrans-ref',
            'status_code' => '201',
            'gross_amount' => '15000.00',
            'transaction_status' => 'pending',
            'transaction_id' => 'tx-2',
        ];
        $pending['signature_key'] = hash(
            'sha512',
            $pending['order_id'].$pending['status_code'].$pending['gross_amount'].'test-midtrans-server-key',
        );

        $this->postJson('/api/payments/midtrans/snap/notification', $pending)->assertOk();
        $this->assertDatabaseHas('orders', [
            'reference_id' => 'midtrans-ref',
            'payment_status' => 'paid',
        ]);
    }

    public function test_midtrans_paid_amount_mismatch_is_rejected_before_state_change(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'test-midtrans-server-key',
            'clientKey' => 'test-midtrans-client-key',
        ]);

        $this->insertOrder('amount-ref', 'midtrans', 'snap', 'sandbox', 15000);

        $body = [
            'order_id' => 'amount-ref',
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'tx-wrong',
        ];
        $body['signature_key'] = hash(
            'sha512',
            $body['order_id'].$body['status_code'].$body['gross_amount'].'test-midtrans-server-key',
        );

        $this->postJson('/api/payments/midtrans/snap/notification', $body)->assertStatus(400);
        $this->assertDatabaseHas('orders', [
            'reference_id' => 'amount-ref',
            'payment_status' => 'pending',
        ]);
    }

    public function test_doku_signed_paid_callback_is_accepted_and_duplicate_is_safe(): void
    {
        $this->saveIntegrationSetting('doku_environment', 'sandbox');
        $this->saveIntegrationProfile('doku', 'checkout', 'sandbox', [
            'clientId' => 'doku-client',
            'secretKey' => 'doku-secret',
            'apiUrl' => 'https://api-sandbox.doku.com',
        ]);

        $this->insertOrder('doku-ref', 'doku', 'checkout', 'sandbox', 22000, 'provider-request-1');

        $payload = [
            'order' => ['invoice_number' => 'doku-ref', 'amount' => 22000],
            'transaction' => ['status' => 'SUCCESS', 'original_request_id' => 'provider-request-1'],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = '2026-09-28T09:00:00Z';
        $requestId = 'doku-event-1';
        $signature = $this->dokuSignature(
            $raw,
            '/api/payments/doku/callback',
            'doku-client',
            'doku-secret',
            $requestId,
            $timestamp,
        );

        $headers = [
            'client-id' => 'doku-client',
            'request-id' => $requestId,
            'request-timestamp' => $timestamp,
            'signature' => $signature,
            'content-type' => 'application/json',
        ];

        $this->call('POST', '/api/payments/doku/callback', [], [], [], $this->serverHeaders($headers), $raw)
            ->assertOk();
        $this->call('POST', '/api/payments/doku/callback', [], [], [], $this->serverHeaders($headers), $raw)
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'reference_id' => 'doku-ref',
            'payment_status' => 'paid',
        ]);
        $this->assertSame(1, DB::table('order_events')
            ->where('source', 'doku')
            ->where('event_id', $requestId)
            ->count());
    }

    public function test_duplicate_wallet_paid_callback_cannot_credit_twice(): void
    {
        $this->saveIntegrationSetting('midtrans_environment', 'sandbox');
        $this->saveIntegrationProfile('midtrans', 'snap', 'sandbox', [
            'serverKey' => 'test-midtrans-server-key',
            'clientKey' => 'test-midtrans-client-key',
        ]);

        $customerId = (string) Str::uuid();
        DB::table('customer_users')->insert([
            'id' => $customerId,
            'email' => 'wallet@example.com',
            'name' => 'Wallet',
            'phone' => '+6281234567000',
            'password_hash' => str_repeat('a', 64),
            'password_salt' => str_repeat('b', 32),
            'balance' => 0,
            'leaderboard_opt_in' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_topups')->insert([
            'id' => 'topup-1',
            'customer_id' => $customerId,
            'amount' => 50000,
            'sender_name' => 'Wallet',
            'payment_method' => 'qris',
            'proof_url' => '',
            'source' => 'midtrans',
            'reference_id' => 'topup-ref',
            'payment_fee' => 0,
            'payment_total' => 50000,
            'status' => 'pending',
            'payment_gateway' => 'midtrans',
            'payment_gateway_mode' => 'snap',
            'gateway_environment' => 'sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $body = [
            'order_id' => 'topup-ref',
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'wallet-tx-1',
        ];
        $body['signature_key'] = hash(
            'sha512',
            $body['order_id'].$body['status_code'].$body['gross_amount'].'test-midtrans-server-key',
        );

        $this->postJson('/api/payments/midtrans/snap/notification', $body)->assertOk();
        $this->postJson('/api/payments/midtrans/snap/notification', $body)->assertOk();

        $this->assertSame(1, DB::table('wallet_transactions')->where('reference', 'topup:topup-1')->count());
        $this->assertSame(50000, (int) DB::table('customer_users')->where('id', $customerId)->value('balance'));
        $this->assertDatabaseHas('wallet_topups', ['id' => 'topup-1', 'status' => 'approved']);
    }

    private function insertOrder(
        string $reference,
        string $gateway,
        string $mode,
        string $environment,
        int $total,
        ?string $requestId = null,
    ): void {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'reference_id' => $reference,
            'product_slug' => 'game',
            'product_name' => 'Game',
            'package_sku' => 'SKU',
            'package_label' => 'Nominal',
            'fulfillment_type' => 'automatic',
            'target_template' => '{{destination}}',
            'destination' => '12345',
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '+6281234567890',
            'customer_inputs_json' => '[]',
            'quantity' => 1,
            'base_subtotal' => $total,
            'subtotal' => $total,
            'discount_amount' => 0,
            'admin_fee' => 0,
            'total' => $total,
            'payment_method' => 'qris',
            'payment_channel' => 'qris',
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'payment_gateway' => $gateway,
            'payment_gateway_mode' => $mode,
            'payment_gateway_environment' => $environment,
            'gateway_request_id' => $requestId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dokuSignature(
        string $rawBody,
        string $target,
        string $clientId,
        string $secret,
        string $requestId,
        string $timestamp,
    ): string {
        $digest = base64_encode(hash('sha256', $rawBody, true));
        $raw = implode("\n", [
            'Client-Id:'.$clientId,
            'Request-Id:'.$requestId,
            'Request-Timestamp:'.$timestamp,
            'Request-Target:'.$target,
            'Digest:'.$digest,
        ]);

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $raw, $secret, true));
    }

    private function serverHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $result[$key === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.$key] = $value;
        }

        return $result;
    }
}
