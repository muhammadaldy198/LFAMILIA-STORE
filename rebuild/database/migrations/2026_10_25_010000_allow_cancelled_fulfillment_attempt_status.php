<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Percobaan pemenuhan milik pesanan yang dibatalkan harus bisa ditutup,
     * bukan dibiarkan BLOCKED/UNKNOWN sehingga pesanan batal tetap muncul
     * sebagai "perlu tindakan".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE fulfillment_attempts DROP CHECK fulfillment_attempt_status_check');
        DB::statement("ALTER TABLE fulfillment_attempts ADD CONSTRAINT fulfillment_attempt_status_check CHECK (status IN ('CREATED','SENDING','PENDING','UNKNOWN','SUCCESS','FAILED_CONFIRMED','BLOCKED','MANUAL_PENDING','MANUAL_FAILED','CANCELLED'))");
    }

    public function down(): void
    {
        // Tanpa status lama, constraint lama tidak bisa dipasang kembali: percobaan
        // yang sudah ditutup pembatalan dikembalikan ke BLOCKED.
        DB::table('fulfillment_attempts')->where('status', 'CANCELLED')->update(['status' => 'BLOCKED']);
        DB::statement('ALTER TABLE fulfillment_attempts DROP CHECK fulfillment_attempt_status_check');
        DB::statement("ALTER TABLE fulfillment_attempts ADD CONSTRAINT fulfillment_attempt_status_check CHECK (status IN ('CREATED','SENDING','PENDING','UNKNOWN','SUCCESS','FAILED_CONFIRMED','BLOCKED','MANUAL_PENDING','MANUAL_FAILED'))");
    }
};
