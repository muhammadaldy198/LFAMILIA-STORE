<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('publisher')->nullable()->after('name');
        });

        Schema::table('product_packages', function (Blueprint $table): void {
            $table->string('group_name', 120)->nullable()->after('name')->index();
        });

        Schema::create('product_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('saved_game_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->json('customer_input');
            $table->string('nickname')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'product_id']);
        });

        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name', 100);
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('saved_game_accounts');
        Schema::dropIfExists('product_notices');

        Schema::table('product_packages', function (Blueprint $table): void {
            $table->dropColumn('group_name');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('publisher');
        });
    }
};
