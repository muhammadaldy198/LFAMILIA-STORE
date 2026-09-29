<?php

use App\Jobs\ReconcileFulfillmentJob;
use App\Jobs\SendFulfillmentJob;
use App\Jobs\StartFulfillmentJob;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\CustomerAccountDeletion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

Artisan::command('lfamilia:bootstrap-super-admin', function (): int {
    if (AdminUser::where('role', 'SUPER_ADMIN')->exists()) {
        $this->error('Super Admin sudah ada. Kelola akses berikutnya dari panel.');

        return 1;
    }

    $name = $this->ask('Nama Super Admin');
    $email = strtolower(trim((string) $this->ask('Email Super Admin')));
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    $confirmation = $this->secret('Ulangi kata sandi');

    $data = Validator::make([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', Rule::unique('admin_users', 'email')],
        'password' => ['required', 'string', 'min:12', 'confirmed'],
    ])->validate();

    AdminUser::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
        'role' => 'SUPER_ADMIN',
        'is_active' => true,
    ]);

    $this->info('Super Admin dibuat.');

    return 0;
})->purpose('Bootstrap the first Super Admin without storing credentials in Git');

Artisan::command('lfamilia:cleanup-empty-customers', function (CustomerAccountDeletion $deletion): void {
    $cutoff = now()->subDays(30);
    $count = 0;

    User::query()->whereRaw('COALESCE(last_active_at, created_at) <= ?', [$cutoff])
        ->chunkById(100, function ($users) use ($deletion, $cutoff, &$count): void {
            foreach ($users as $user) {
                try {
                    $deletion->delete($user, $cutoff);
                    if (User::withTrashed()->find($user->id)?->trashed()) {
                        $count++;
                    }
                } catch (ValidationException) {
                    // Balances, orders, top-ups, and tickets retain the account.
                }
            }
        });

    $this->info("Akun kosong yang dihapus: {$count}");
})->purpose('Remove inactive empty accounts after 30 days, retaining accounts with obligations');

Schedule::command('lfamilia:cleanup-empty-customers')->dailyAt('03:30');

Artisan::command('lfamilia:recover-fulfillment', function (): void {
    $queued = 0;

    DB::table('orders')
        ->where('status', 'PAID')
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('fulfillment_attempts')
                ->whereColumn('fulfillment_attempts.order_id', 'orders.id');
        })
        ->orderBy('id')
        ->chunkById(100, function ($orders) use (&$queued): void {
            foreach ($orders as $order) {
                StartFulfillmentJob::dispatch((int) $order->id);
                $queued++;
            }
        });

    DB::table('fulfillment_attempts')
        ->where('status', 'CREATED')
        ->where('updated_at', '<=', now()->subMinute())
        ->orderBy('id')
        ->chunkById(100, function ($attempts) use (&$queued): void {
            foreach ($attempts as $attempt) {
                SendFulfillmentJob::dispatch((int) $attempt->id);
                $queued++;
            }
        });

    DB::table('fulfillment_attempts')
        ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])
        ->where('updated_at', '<=', now()->subMinutes(2))
        ->orderBy('id')
        ->chunkById(100, function ($attempts) use (&$queued): void {
            foreach ($attempts as $attempt) {
                ReconcileFulfillmentJob::dispatch((int) $attempt->id);
                $queued++;
            }
        });

    $this->info("Fulfillment jobs queued: {$queued}");
})->purpose('Recover paid orders and reconcile uncertain provider attempts without creating duplicate fulfillment');

Schedule::command('lfamilia:recover-fulfillment')->everyMinute()->withoutOverlapping();
