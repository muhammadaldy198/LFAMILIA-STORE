<?php

use App\Services\PaymentRouteCatalogService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Adds the newly supported BSI VA channel as initially disabled.
        // Reconciles protocol-owned method restrictions without changing
        // operator toggles or gateway active states (DOKU stays as configured).
        app(PaymentRouteCatalogService::class)->sync();
    }

    public function down(): void
    {
        // Existing invoices may reference any of these routes.
        // Never remove bank channels or routes on rollback.
    }
};
