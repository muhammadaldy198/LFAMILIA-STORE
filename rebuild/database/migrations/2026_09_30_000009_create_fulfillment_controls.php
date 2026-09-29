<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_mappings', function (Blueprint $table): void {
            $table->json('fulfillment_config')->nullable()->after('max_price_idr');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->json('delivery_payload')->nullable()->after('snapshot');
        });

        Schema::table('fulfillment_attempts', function (Blueprint $table): void {
            $table->foreignId('provider_id')->nullable()->after('provider_mapping_id')
                ->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_no')->default(1)->after('provider_id');
            $table->json('request_payload')->nullable()->after('correlation_id');
            $table->json('response_payload')->nullable()->after('request_payload');
            $table->string('provider_status', 40)->nullable()->after('response_payload');
            $table->string('provider_rc', 40)->nullable()->after('provider_status');
            $table->text('serial_number')->nullable()->after('provider_rc');
            $table->unsignedBigInteger('price_idr')->nullable()->after('serial_number');
            $table->boolean('safe_to_failover')->default(false)->after('price_idr');
            $table->text('last_error')->nullable()->after('safe_to_failover');
            $table->timestamp('last_checked_at')->nullable()->after('last_error');
            $table->timestamp('completed_at')->nullable()->after('reconciled_at');
            $table->unique(['order_id', 'provider_mapping_id'], 'fulfillment_order_mapping_unique');
        });

        DB::statement("ALTER TABLE fulfillment_attempts ADD CONSTRAINT fulfillment_attempt_status_check CHECK (status IN ('CREATED','SENDING','PENDING','UNKNOWN','SUCCESS','FAILED_CONFIRMED','BLOCKED','MANUAL_PENDING','MANUAL_FAILED'))");

        Schema::create('fulfillment_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fulfillment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_code', 40);
            $table->string('event_id', 64);
            $table->string('payload_hash', 64);
            $table->string('result', 40);
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['provider_code', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_callbacks');

        DB::statement('ALTER TABLE fulfillment_attempts DROP CHECK fulfillment_attempt_status_check');
        Schema::table('fulfillment_attempts', function (Blueprint $table): void {
            $table->dropUnique('fulfillment_order_mapping_unique');
            $table->dropForeign(['provider_id']);
            $table->dropColumn([
                'provider_id', 'attempt_no', 'request_payload', 'response_payload',
                'provider_status', 'provider_rc', 'serial_number', 'price_idr',
                'safe_to_failover', 'last_error', 'last_checked_at', 'completed_at',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_payload');
        });

        Schema::table('provider_mappings', function (Blueprint $table): void {
            $table->dropColumn('fulfillment_config');
        });
    }
};
