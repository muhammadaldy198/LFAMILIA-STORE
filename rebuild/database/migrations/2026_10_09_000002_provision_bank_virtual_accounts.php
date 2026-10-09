<?php

use App\Services\PaymentRouteCatalogService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // One-time auto-provisioning. Existing payments and manual overrides
        // are never deleted or reset by a later catalog sync.
        app(PaymentRouteCatalogService::class)->sync();
    }

    public function down(): void
    {
        // Keep channels and routes referenced by orders, wallet topups or payments.
    }
};
