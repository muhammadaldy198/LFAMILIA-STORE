<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('balance_idr')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_idr');
            $table->unsignedBigInteger('balance_before_idr');
            $table->unsignedBigInteger('balance_after_idr');
            $table->string('source', 40);
            $table->string('reference_type', 40);
            $table->string('reference_id', 100);
            $table->string('actor_type', 40)->nullable();
            $table->string('actor_id', 100)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_id', 'created_at']);
            $table->unique(['source', 'reference_type', 'reference_id'], 'wallet_source_reference_unique');
        });
        DB::statement('ALTER TABLE wallet_ledger ADD CONSTRAINT wallet_ledger_balance_check CHECK (CAST(balance_after_idr AS DECIMAL(20,0)) = CAST(balance_before_idr AS DECIMAL(20,0)) + CAST(amount_idr AS DECIMAL(20,0)) AND amount_idr <> 0)');

        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('discount_type', 20);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('minimum_total_idr')->default(0);
            $table->unsignedInteger('total_quota')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('voucher_products', function (Blueprint $table): void {
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['voucher_id', 'product_id']);
        });

        Schema::create('voucher_categories', function (Blueprint $table): void {
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['voucher_id', 'category_id']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 80)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone', 32)->nullable();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_package_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_mapping_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('PENDING_PAYMENT');
            $table->string('currency', 3)->default('IDR');
            $table->json('customer_input');
            $table->json('snapshot');
            $table->unsignedBigInteger('cost_idr');
            $table->unsignedBigInteger('margin_idr');
            $table->unsignedBigInteger('discount_idr')->default(0);
            $table->unsignedBigInteger('fee_idr')->default(0);
            $table->unsignedBigInteger('total_idr');
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_positive_total_check CHECK (total_idr > 0)');

        Schema::create('voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('guest_identifier_hash', 64)->nullable();
            $table->string('status', 20)->default('RESERVED');
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['voucher_id', 'status', 'reserved_until']);
            $table->index(['voucher_id', 'user_id', 'status']);
            $table->index(['voucher_id', 'guest_identifier_hash', 'status'], 'voucher_guest_limit_idx');
        });

        Schema::create('wallet_topups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_idr');
            $table->unsignedBigInteger('fee_idr')->default(0);
            $table->unsignedBigInteger('total_idr');
            $table->string('status', 30)->default('PENDING_PAYMENT');
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        DB::statement('ALTER TABLE wallet_topups ADD CONSTRAINT wallet_topups_positive_amount_check CHECK (amount_idr > 0 AND total_idr = amount_idr + fee_idr)');

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_topup_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('gateway_code', 40);
            $table->string('channel_code', 60);
            $table->string('external_reference', 120)->nullable();
            $table->unsignedBigInteger('amount_idr');
            $table->string('status', 40)->default('CREATING');
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway_code', 'external_reference']);
            $table->index(['status', 'created_at']);
        });
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_target_check CHECK ((order_id IS NOT NULL) <> (wallet_topup_id IS NOT NULL) AND amount_idr > 0)');

        Schema::create('payment_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_code', 40);
            $table->string('event_id', 120);
            $table->string('payload_hash', 64);
            $table->string('result', 40);
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['gateway_code', 'event_id']);
        });

        Schema::create('fulfillment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_mapping_id')->constrained()->restrictOnDelete();
            $table->string('external_reference', 120)->unique();
            $table->string('status', 40)->default('CREATED');
            $table->string('correlation_id', 100);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('correlation_id', 100);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('fulfillment_attempts');
        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('voucher_categories');
        Schema::dropIfExists('voucher_products');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('wallet_ledger');
        Schema::dropIfExists('wallets');
    }
};
