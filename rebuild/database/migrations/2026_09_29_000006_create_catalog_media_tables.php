<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('fulfillment_mode', 30)->default('AUTO_PROVIDER')->index();
            $table->text('manual_instructions')->nullable();
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_fulfillment_mode_check CHECK (fulfillment_mode IN ('AUTO_PROVIDER', 'MANUAL'))");

        Schema::create('store_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('target_url')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });

        $now = now();
        DB::table('categories')->insert(
            collect([
                ['name' => 'Game', 'slug' => 'game'],
                ['name' => 'Pulsa', 'slug' => 'pulsa'],
                ['name' => 'Paket Data', 'slug' => 'paket-data'],
                ['name' => 'PLN', 'slug' => 'pln'],
                ['name' => 'Voucher', 'slug' => 'voucher'],
                ['name' => 'Entertainment', 'slug' => 'entertainment'],
                ['name' => 'PPOB lainnya', 'slug' => 'ppob-lainnya'],
            ])->map(fn (array $item, int $index): array => [
                ...$item, 'sort_order' => $index + 1, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ])->all()
        );

        DB::table('store_assets')->insert(
            collect(['logo', 'favicon', 'banner_desktop', 'banner_mobile', 'popup'])
                ->map(fn (string $key): array => [
                    'key' => $key, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
                ])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('store_assets');
        DB::table('categories')->whereIn('slug', [
            'game', 'pulsa', 'paket-data', 'pln', 'voucher', 'entertainment', 'ppob-lainnya',
        ])->delete();
        DB::statement('ALTER TABLE products DROP CHECK products_fulfillment_mode_check');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['description', 'fulfillment_mode', 'manual_instructions']);
        });
    }
};
