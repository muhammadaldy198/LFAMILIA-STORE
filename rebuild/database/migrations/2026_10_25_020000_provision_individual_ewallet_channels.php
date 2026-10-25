<?php

use App\Services\PaymentRouteCatalogService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Create separate GoPay/ShopeePay channels and Midtrans routes.
        // Existing channel fees, provider selections, and active flags survive sync.
        // Both new channels stay disabled until intentionally activated by admin.
        app(PaymentRouteCatalogService::class)->sync();
    }

    public function down(): void
    {
        // Existing transactions may reference these channels/routes.
        // Removing or re-enabling them on rollback is unsafe.
    }
};
