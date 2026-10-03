<?php

use App\Services\PaymentRouteCatalogService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PaymentRouteCatalogService::class)->sync();
    }

    public function down(): void
    {
        // Protocol routes may already have transaction references or operator state.
        // Rollback intentionally keeps them; disabling/removing transaction routes
        // automatically would be unsafe.
    }
};
