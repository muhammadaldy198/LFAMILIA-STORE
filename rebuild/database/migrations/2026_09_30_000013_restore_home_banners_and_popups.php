<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->string('subtitle', 500)->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_href', 500)->nullable();
            $table->boolean('show_desktop')->default(true)->index();
            $table->boolean('show_mobile')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('site_popups', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->text('body');
            $table->string('primary_label', 80)->nullable();
            $table->string('primary_href', 500)->nullable();
            $table->string('secondary_label', 80)->nullable();
            $table->string('secondary_href', 500)->nullable();
            $table->unsignedSmallInteger('dismiss_days')->default(7);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        DB::table('site_popups')->insert([
            'title' => 'Selamat datang di LFAMILIA STORE',
            'body' => "Temukan top up game, voucher digital, berita terbaru, dan komunitas LFAMILIA dalam satu tempat.\n\nIkuti kanal resmi LFAMILIA agar tidak ketinggalan info, promo, dan pengumuman layanan.",
            'primary_label' => 'Lihat Produk',
            'primary_href' => '/#produk',
            'secondary_label' => 'Hubungi Kami',
            'secondary_href' => '/contact',
            'dismiss_days' => 7,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_popups');
        Schema::dropIfExists('home_banners');
    }
};
