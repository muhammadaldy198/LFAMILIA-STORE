<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('support_tickets')->whereNull('user_id')->exists()) {
            throw new \RuntimeException('Tiket guest harus dipertahankan; rollback diblokir.');
        }
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
