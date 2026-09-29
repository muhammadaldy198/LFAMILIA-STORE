<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->json('permissions')->nullable()->after('role');
        });

        DB::table('admin_users')->where('role', 'ADMIN')->update([
            'permissions' => json_encode([
                'dashboard.view', 'orders.view', 'catalog.manage', 'content.manage',
                'fulfillment.manage', 'providers.manage', 'payments.manage',
                'customers.view', 'vouchers.manage', 'support.manage',
                'reports.view', 'settings.manage', 'notifications.view',
            ], JSON_THROW_ON_ERROR),
        ]);

        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 80);
            $table->string('severity', 20)->default('INFO');
            $table->string('title', 180);
            $table->text('message');
            $table->string('target_type', 80)->nullable();
            $table->string('target_id', 120)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['event_type', 'created_at']);
        });

        Schema::create('admin_notification_reads', function (Blueprint $table): void {
            $table->foreignId('admin_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['admin_notification_id', 'admin_user_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 30);
            $table->string('status', 30);
            $table->string('destination_hash', 64)->nullable();
            $table->string('response_code', 30)->nullable();
            $table->text('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['channel', 'status', 'created_at']);
            $table->unique(['admin_notification_id', 'channel'], 'notification_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('admin_notification_reads');
        Schema::dropIfExists('admin_notifications');

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn('permissions');
        });
    }
};
