<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 180)->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('source_label', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('faq_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('content_pages', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach (['footer_banner_desktop', 'footer_banner_mobile'] as $key) {
            DB::table('store_assets')->insertOrIgnore([
                'key' => $key, 'target_url' => null, 'is_active' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('faq_entries')->insert([
            ['question' => 'Bagaimana cara top up di LFAMILIA STORE?', 'answer' => 'Pilih produk, isi data tujuan dengan benar, pilih nominal dan metode pembayaran, lalu selesaikan pembayaran. Status pesanan dapat dipantau melalui menu Cek Pesanan.', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['question' => 'Metode pembayaran apa saja yang tersedia?', 'answer' => 'Metode pembayaran yang tampil mengikuti kanal yang sedang aktif. Biaya kanal, jika ada, akan dihitung oleh server dan ditampilkan sebelum pesanan dibuat.', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['question' => 'Berapa lama proses top up?', 'answer' => 'Produk otomatis umumnya diproses segera setelah pembayaran terverifikasi. Waktu dapat bertambah jika provider, publisher, atau jaringan sedang mengalami gangguan.', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['question' => 'Apakah transaksi aman?', 'answer' => 'Harga dan status pembayaran diverifikasi di backend. LFAMILIA tidak pernah meminta password game, PIN pembayaran, atau kode OTP melalui kolom pesanan.', 'sort_order' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['question' => 'Bagaimana cara cek transaksi?', 'answer' => 'Buka menu Cek Pesanan, masukkan nomor pesanan dan kode akses guest yang diberikan setelah checkout. Status dan log proses akan diperbarui dari data transaksi server.', 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['question' => 'Apakah ada promo?', 'answer' => 'Promo dan voucher aktif dapat berubah. Gunakan kode promo pada halaman checkout jika tersedia dan memenuhi syarat.', 'sort_order' => 6, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['question' => 'Bagaimana jika ada kendala?', 'answer' => 'Gunakan tombol bantuan di kanan bawah atau halaman Hubungi Kami. Sertakan nomor pesanan agar pemeriksaan lebih cepat.', 'sort_order' => 7, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('content_pages')->insert([
            ['key' => 'terms', 'title' => 'Syarat & Ketentuan', 'intro' => 'Syarat ini mengatur penggunaan website dan pembelian produk digital di LFAMILIA STORE.', 'body' => "Periksa produk, nominal, User ID, server, dan data tujuan sebelum membayar.\n\nPesanan diproses setelah pembayaran terverifikasi. Produk digital yang sudah berhasil dikirim umumnya tidak dapat dibatalkan.\n\nLFAMILIA tidak pernah meminta password, PIN, atau kode OTP. Pengguna bertanggung jawab atas ketepatan data tujuan yang dikirim.", 'is_active' => true, 'updated_by_admin_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'refund', 'title' => 'Kebijakan Pengembalian Dana', 'intro' => 'Karena top up, voucher, dan lisensi merupakan produk digital yang dapat dikirim segera, refund hanya tersedia untuk kondisi tertentu.', 'body' => "Produk yang sudah berhasil dikirim atau kode yang sudah digunakan tidak dapat direfund.\n\nRefund dapat diajukan jika pembayaran terverifikasi tetapi pesanan dinyatakan gagal dan tidak dapat diproses ulang, terjadi pembayaran ganda, atau produk tidak tersedia.\n\nKesalahan User ID, server, produk, nominal, region, email, atau nomor tujuan menjadi tanggung jawab pembeli. Ajukan kendala melalui kanal bantuan resmi dengan nomor pesanan dan bukti pembayaran.", 'is_active' => true, 'updated_by_admin_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'privacy', 'title' => 'Kebijakan Privasi', 'intro' => 'Kebijakan ini menjelaskan bagaimana LFAMILIA STORE menggunakan dan melindungi informasi pengguna.', 'body' => "Data digunakan untuk memproses pesanan, menjaga keamanan, menampilkan status transaksi, dan memberikan bantuan.\n\nLFAMILIA tidak menjual atau menyewakan data pribadi pengguna. Data dapat dibagikan secara terbatas kepada penyedia pembayaran, pemasok produk digital, hosting, dan layanan keamanan sejauh diperlukan untuk menjalankan layanan.\n\nJangan pernah mengirim password, PIN, kode OTP, atau data kartu pembayaran melalui formulir bantuan.", 'is_active' => true, 'updated_by_admin_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach ([
            'store.support_widget_enabled' => true,
            'store.footer_description' => 'Top up game dan produk digital dengan alur transaksi yang cepat, aman, dan transparan.',
            'store.home_news_title' => 'LFAMILIA NEWS: INFO GAMING & UPDATE TERBARU',
            'store.home_news_intro' => 'Info gaming, produk, promo, pengumuman layanan, dan kabar terbaru LFAMILIA.',
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'version' => 1,
                'updated_by_admin_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('store_assets')->whereIn('key', ['footer_banner_desktop', 'footer_banner_mobile'])->delete();
        DB::table('system_settings')->whereIn('key', [
            'store.support_widget_enabled', 'store.footer_description',
            'store.home_news_title', 'store.home_news_intro',
        ])->delete();
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('faq_entries');
        Schema::dropIfExists('news_articles');
    }
};
