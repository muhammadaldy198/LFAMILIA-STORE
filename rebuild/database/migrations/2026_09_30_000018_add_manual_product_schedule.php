<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('manual_open_time', 5)->nullable()->after('manual_instructions');
            $table->string('manual_close_time', 5)->nullable()->after('manual_open_time');
            $table->string('manual_timezone', 40)->default('Asia/Jakarta')->after('manual_close_time');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['manual_open_time', 'manual_close_time', 'manual_timezone']);
        });
    }
};
