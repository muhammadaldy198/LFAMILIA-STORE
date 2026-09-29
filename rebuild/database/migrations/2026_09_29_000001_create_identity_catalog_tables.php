<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->unsignedTinyInteger('rank')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('requirements')->nullable();
            $table->json('benefits')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('membership_tiers')->insert(
            collect(['BASIC', 'SILVER', 'GOLD', 'DIAMOND', 'PLATINUM', 'MAFIA'])
                ->map(fn (string $code, int $rank): array => [
                    'code' => $code,
                    'rank' => $rank + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
        );

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('password')->nullable();
            $table->string('google_sub')->nullable()->unique();
            $table->string('membership_tier_code', 20)->default('BASIC');
            $table->foreign('membership_tier_code')->references('code')->on('membership_tiers')->restrictOnDelete();
            $table->rememberToken();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('last_active_at');
        });

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->index(['role', 'is_active']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('margin_percent', 8, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['category_id', 'is_active', 'sort_order']);
        });

        Schema::create('product_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->unsignedBigInteger('nominal_value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'code']);
            $table->index(['product_id', 'is_active', 'nominal_value', 'sort_order'], 'packages_catalog_idx');
        });

        Schema::create('product_input_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 80);
            $table->string('label');
            $table->string('type', 30)->default('text');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'field_key']);
        });

        Schema::create('providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('fulfillment_mode', 30);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::table('providers')->insert([
            ['code' => 'DIGIFLAZZ', 'fulfillment_mode' => 'AUTO_PROVIDER', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MANUAL', 'fulfillment_mode' => 'MANUAL', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('provider_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_package_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('external_sku', 120)->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->unsignedBigInteger('max_price_minor')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['provider_id', 'external_sku']);
            $table->index(['product_package_id', 'is_active', 'priority'], 'mapping_selection_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_mappings');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('product_input_fields');
        Schema::dropIfExists('product_packages');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('users');
        Schema::dropIfExists('membership_tiers');
    }
};
