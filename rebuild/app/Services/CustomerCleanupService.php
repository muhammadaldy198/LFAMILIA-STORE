<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCleanupService
{
    public function settings(): array
    {
        $saved = json_decode((string) DB::table('system_settings')->where('key', 'customers.cleanup')->value('value'), true);

        return array_replace(['enabled' => true, 'inactivity_days' => 30, 'last_run_at' => null, 'last_deleted_count' => 0], is_array($saved) ? $saved : []);
    }

    public function run(bool $force = false): int
    {
        $settings = $this->settings();
        if (! $settings['enabled'] && ! $force) {
            return 0;
        }
        $cutoff = now()->subDays(max(7, min(365, (int) $settings['inactivity_days'])));
        $count = 0;
        $deletion = app(CustomerAccountDeletion::class);
        User::query()->whereRaw('COALESCE(last_active_at, created_at) <= ?', [$cutoff])
            ->chunkById(100, function ($users) use ($deletion, $cutoff, &$count): void {
                foreach ($users as $user) {
                    try {
                        $deletion->delete($user, $cutoff);
                        if (User::withTrashed()->find($user->id)?->trashed()) {
                            $count++;
                        }
                    } catch (ValidationException) {
                        // Retain every account with balance, ledger, order, top-up or support history.
                    }
                }
            });
        DB::table('system_settings')->updateOrInsert(['key' => 'customers.cleanup'], [
            'value' => json_encode([...$settings, 'last_run_at' => now()->toIso8601String(), 'last_deleted_count' => $count]),
            'updated_at' => now(),
        ]);

        return $count;
    }
}
