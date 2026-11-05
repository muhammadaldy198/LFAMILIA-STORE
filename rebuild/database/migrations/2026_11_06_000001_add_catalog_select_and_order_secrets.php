<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_input_fields', function (Blueprint $table): void {
            $table->json('options')->nullable()->after('type');
        });

        Schema::create('order_sensitive_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('field_key', 80);
            $table->text('value');
            $table->timestamps();
            $table->unique(['order_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sensitive_inputs');

        Schema::table('product_input_fields', function (Blueprint $table): void {
            $table->dropColumn('options');
        });
    }
};
