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
            $table->boolean('nickname_check_enabled')->default(false);
            $table->string('nickname_game_code', 80)->nullable();
            $table->string('nickname_user_field_key', 80)->nullable();
            $table->string('nickname_server_field_key', 80)->nullable();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_nickname_config_check CHECK (nickname_check_enabled = 0 OR (nickname_game_code IS NOT NULL AND nickname_user_field_key IS NOT NULL))');
        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_discount_check CHECK (discount_type IN ('FIXED', 'PERCENT') AND discount_value > 0 AND (discount_type <> 'PERCENT' OR discount_value <= 100))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_price_math_check CHECK (discount_idr <= cost_idr + margin_idr AND total_idr = cost_idr + margin_idr - discount_idr + fee_idr)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_price_math_check');
        DB::statement('ALTER TABLE vouchers DROP CHECK vouchers_discount_check');
        DB::statement('ALTER TABLE products DROP CHECK products_nickname_config_check');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'nickname_check_enabled',
                'nickname_game_code',
                'nickname_user_field_key',
                'nickname_server_field_key',
            ]);
        });
    }
};
