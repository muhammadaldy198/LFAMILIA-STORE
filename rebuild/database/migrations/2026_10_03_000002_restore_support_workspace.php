<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('kind', 30)->default('GENERAL')->after('message');
            $table->foreignId('handled_by_admin_id')->nullable()->after('status')
                ->constrained('admin_users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable()->after('handled_by_admin_id');
            $table->index(['kind', 'status', 'updated_at']);
            $table->index(['handled_by_admin_id', 'updated_at']);
        });

        DB::table('support_tickets')
            ->whereNotNull('order_id')
            ->update(['kind' => 'ORDER']);
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropForeign(['handled_by_admin_id']);
            $table->dropIndex(['kind', 'status', 'updated_at']);
            $table->dropIndex(['handled_by_admin_id', 'updated_at']);
            $table->dropColumn(['kind', 'handled_by_admin_id', 'handled_at']);
        });
    }
};
