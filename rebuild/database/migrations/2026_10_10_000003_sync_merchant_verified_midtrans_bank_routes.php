<?php

use App\Services\PaymentRouteCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adds the newly supported BSI VA channel as initially disabled.
        // Reconciles protocol-owned method restrictions without changing
        // operator toggles or gateway active states (DOKU stays as configured).
        app(PaymentRouteCatalogService::class)->sync();

        // The only dashboard-confirmed e-wallet is GoPay. Preserve any
        // customized merchant label instead of overwriting it.
        DB::table('payment_channels')->where('code', 'ewallet')
            ->whereIn('name', ['E-Wallet', 'Dompet Digital'])
            ->update([
                'name' => 'GoPay',
                'description' => 'Bayar melalui GoPay.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Existing invoices may reference any of these routes.
        // Never remove bank channels or routes on rollback.
    }
};
