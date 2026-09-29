<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 40)->nullable();
            $table->string('actor_id', 100)->nullable();
            $table->string('actor_role', 40)->nullable();
            $table->string('action', 100);
            $table->string('target_type', 80);
            $table->string('target_id', 100)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id', 100);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_type', 'target_id', 'created_at'], 'audit_target_idx');
            $table->index(['actor_type', 'actor_id', 'created_at'], 'audit_actor_idx');
            $table->index('correlation_id');
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->json('value');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('audit_logs');
    }
};
