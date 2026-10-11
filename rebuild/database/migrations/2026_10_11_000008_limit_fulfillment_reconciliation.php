<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('reconcile_checks')->default(0);
            $table->timestamp('reconcile_claimed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['reconcile_checks', 'reconcile_claimed_at']);
        });
    }
};
