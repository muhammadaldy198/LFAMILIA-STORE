<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_input_fields', function (Blueprint $table): void {
            $table->string('placeholder')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('product_input_fields', function (Blueprint $table): void {
            $table->dropColumn('placeholder');
        });
    }
};
