<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdminCustomerService
{
    private const TIERS = [
        'basic' => ['label' => 'BASIC', 'minSpend' => 0],
        'gold' => ['label' => 'GOLD', 'minSpend' => 1000000],
        'diamond' => ['label' => 'DIAMOND', 'minSpend' => 10000000],
        'platinum' => ['label' => 'PLATINUM', 'minSpend' => 50000000],
        'mafia' => ['label' => 'MAFIA', 'minSpend' => null],
    ];

    /** @return array<int,array<string,mixed>> */
    public function tierSettings(): array
    {
        $rows = DB::table('member_tier_settings')->get()->keyBy('tier');

        return collect(self::TIERS)->map(function (array $definition, string $tier) use ($rows) {
            $row = $rows->get($tier);

            return [
                'tier' => $tier,
                'label' => $definition['label'],
                'minSpend' => $definition['minSpend'],
                'discountPercent' => max(0.0, min(100.0, (float) ($row->discount_percent ?? 0))),
                'benefits' => (string) ($row->benefits ?? ''),
            ];
        })->values()->all();
    }

    /** @param array<int,array{tier:string,discountPercent:float|int,benefits:string}> $settings */
    public function saveTierSettings(array $settings): array
    {
        $byTier = collect($settings)->keyBy('tier');
        if ($byTier->count() !== count(self::TIERS)
            || collect(array_keys(self::TIERS))->contains(fn (string $tier) => !$byTier->has($tier))) {
            throw new RuntimeException('Semua tier member wajib dikirim satu kali.');
        }

        DB::transaction(function () use ($byTier): void {
            foreach (array_keys(self::TIERS) as $tier) {
                $item = $byTier->get($tier);
                DB::table('member_tier_settings')->updateOrInsert(
                    ['tier' => $tier],
                    [
                        'discount_percent' => max(0.0, min(100.0, (float) $item['discountPercent'])),
                        'benefits' => trim((string) $item['benefits']),
                        'updated_at' => now(),
                    ],
                );
            }
        }, 3);

        return $this->tierSettings();
    }

    /** @return array<int,array<string,mixed>> */
    public function members(bool $includeSensitive): array
    {
        $rows = DB::table('customer_users as u')
            ->leftJoin('orders as o', 'o.customer_id', '=', 'u.id')
            ->where('u.email', 'not like', '__lfadmin__:%')
            ->groupBy([
                'u.id', 'u.name', 'u.email', 'u.phone', 'u.balance', 'u.is_active', 'u.created_at',
                'u.tier_mode', 'u.tier_override', 'u.tier_progress_bonus',
            ])
            ->select([
                'u.id', 'u.name', 'u.email', 'u.phone', 'u.balance', 'u.is_active', 'u.created_at',
                'u.tier_mode', 'u.tier_override', 'u.tier_progress_bonus',
            ])
            ->selectRaw("COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS lifetime_spend")
            ->selectRaw("COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_orders")
            ->orderByDesc('lifetime_spend')
            ->orderByDesc('u.created_at')
            ->limit(500)
            ->get();

        return $rows->map(function ($row) use ($includeSensitive) {
            $lifetimeSpend = (int) ($row->lifetime_spend ?? 0);
            $bonus = max(0, (int) ($row->tier_progress_bonus ?? 0));
            $progress = $lifetimeSpend + $bonus;
            $override = array_key_exists((string) $row->tier_override, self::TIERS)
                ? (string) $row->tier_override
                : null;
            $mode = $row->tier_mode === 'manual' ? 'manual' : 'automatic';
            $tier = $mode === 'manual' && $override ? $override : $this->tierForProgress($progress);

            $base = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'phone' => (string) $row->phone,
                'isActive' => (bool) $row->is_active,
                'createdAt' => $row->created_at,
                'paidOrders' => (int) ($row->paid_orders ?? 0),
                'tierMode' => $mode,
                'tierOverride' => $override,
                'tier' => $tier,
                'tierLabel' => self::TIERS[$tier]['label'],
            ];

            if (!$includeSensitive) {
                return $base;
            }

            return [
                ...$base,
                'balance' => (int) $row->balance,
                'lifetimeSpend' => $lifetimeSpend,
                'tierProgress' => $progress,
                'tierProgressBonus' => $bonus,
            ];
        })->all();
    }

    public function updateMember(string $customerId, string $role, int $addBalance, string $adminEmail, ?string $reason): void
    {
        DB::transaction(function () use ($customerId, $role, $addBalance, $adminEmail, $reason): void {
            $customer = DB::table('customer_users')
                ->where('id', $customerId)
                ->where('email', 'not like', '__lfadmin__:%')
                ->lockForUpdate()
                ->first(['id', 'balance', 'tier_progress_bonus']);

            if (!$customer) {
                throw new RuntimeException('Pelanggan tidak ditemukan.');
            }

            if ($role === 'automatic') {
                DB::table('customer_users')->where('id', $customerId)->update([
                    'tier_mode' => 'automatic',
                    'tier_override' => null,
                    'tier_progress_bonus' => 0,
                    'updated_at' => now(),
                ]);
            } else {
                if (!array_key_exists($role, self::TIERS)) {
                    throw new RuntimeException('Tier member tidak valid.');
                }

                $bonus = max(0, (int) $customer->tier_progress_bonus);
                if ($role !== 'mafia') {
                    $lifetimeSpend = (int) DB::table('orders')
                        ->where('customer_id', $customerId)
                        ->where('payment_status', 'paid')
                        ->sum('total');
                    $currentProgress = $lifetimeSpend + $bonus;
                    $minimum = (int) self::TIERS[$role]['minSpend'];
                    $bonus += max(0, $minimum - $currentProgress);
                }

                DB::table('customer_users')->where('id', $customerId)->update([
                    'tier_mode' => 'manual',
                    'tier_override' => $role,
                    'tier_progress_bonus' => $bonus,
                    'updated_at' => now(),
                ]);
            }

            if ($addBalance > 0) {
                $this->adjustLockedCustomerBalance(
                    $customerId,
                    'credit',
                    $addBalance,
                    $adminEmail,
                    $reason ?: 'Penambahan saldo dari pengaturan member',
                );
            }
        }, 3);
    }

    /** @return array{customers:array<int,array<string,mixed>>,admins:array<int,array<string,mixed>>} */
    public function balanceOverview(): array
    {
        $customers = DB::table('customer_users as u')
            ->leftJoin('orders as o', 'o.customer_id', '=', 'u.id')
            ->where('u.email', 'not like', '__lfadmin__:%')
            ->groupBy(['u.id', 'u.name', 'u.email', 'u.phone', 'u.balance', 'u.is_active', 'u.created_at'])
            ->select(['u.id', 'u.name', 'u.email', 'u.phone', 'u.balance', 'u.is_active', 'u.created_at'])
            ->selectRaw("COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS lifetime_spend")
            ->selectRaw("COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_orders")
            ->orderByDesc('u.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'phone' => (string) $row->phone,
                'balance' => (int) $row->balance,
                'is_active' => (bool) $row->is_active,
                'created_at' => $row->created_at,
                'lifetime_spend' => (int) ($row->lifetime_spend ?? 0),
                'paid_orders' => (int) ($row->paid_orders ?? 0),
            ])->all();

        $admins = DB::table('admin_users')->orderByRaw("CASE role WHEN 'super_admin' THEN 0 ELSE 1 END")
            ->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']);
        $credentialEmails = $admins->map(fn ($row) => '__lfadmin__:'.strtolower((string) $row->email))->all();
        $credentials = $credentialEmails === []
            ? collect()
            : DB::table('customer_users')->whereIn('email', $credentialEmails)->get(['id', 'email', 'balance'])->keyBy('email');

        $adminRows = $admins->map(function ($row) use ($credentials) {
            $credential = $credentials->get('__lfadmin__:'.strtolower((string) $row->email));

            return [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => (string) $row->role,
                'is_active' => (bool) $row->is_active,
                'ledger_id' => $credential?->id ? (string) $credential->id : null,
                'balance' => (int) ($credential->balance ?? 0),
            ];
        })->all();

        return ['customers' => $customers, 'admins' => $adminRows];
    }

    /** @return array<string,mixed> */
    public function adjustBalance(string $accountType, string $targetId, string $operation, int $amount, string $reason, string $adminEmail): array
    {
        return DB::transaction(function () use ($accountType, $targetId, $operation, $amount, $reason, $adminEmail): array {
            if ($accountType === 'customer') {
                $customerId = $targetId;
                $target = DB::table('customer_users')
                    ->where('id', $customerId)
                    ->where('email', 'not like', '__lfadmin__:%')
                    ->first(['id', 'name', 'email']);
            } else {
                $admin = DB::table('admin_users')->where('id', (int) $targetId)->first(['email', 'name']);
                $customerId = $admin ? (string) DB::table('customer_users')
                    ->where('email', '__lfadmin__:'.strtolower((string) $admin->email))
                    ->value('id') : '';
                $target = ($admin && $customerId !== '') ? (object) [
                    'id' => $customerId,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ] : null;
            }

            if (!$target) {
                throw new RuntimeException($accountType === 'customer' ? 'Pelanggan tidak ditemukan.' : 'Akun admin tidak ditemukan.');
            }

            $result = $this->adjustLockedCustomerBalance(
                $customerId,
                $operation,
                $amount,
                $adminEmail,
                $reason,
                $accountType === 'admin' ? 'Saldo admin' : 'Saldo pelanggan',
            );

            return [
                ...$result,
                'target' => [
                    'id' => $targetId,
                    'name' => (string) $target->name,
                    'email' => (string) $target->email,
                ],
            ];
        }, 3);
    }

    /** @return array{deleted:bool,reason:?string,eligibility:?array<string,mixed>} */
    public function deleteEmptyCustomer(string $customerId): array
    {
        return DB::transaction(function () use ($customerId): array {
            $customer = DB::table('customer_users')
                ->where('id', $customerId)
                ->where('email', 'not like', '__lfadmin__:%')
                ->lockForUpdate()
                ->first(['id', 'balance']);
            if (!$customer) {
                return ['deleted' => false, 'reason' => 'not_found', 'eligibility' => null];
            }

            $eligibility = $this->eligibility($customerId, (int) $customer->balance);
            if (!$eligibility['canDelete']) {
                return ['deleted' => false, 'reason' => 'has_history', 'eligibility' => $eligibility];
            }

            $deleted = DB::table('customer_users')->where('id', $customerId)->delete();

            return [
                'deleted' => $deleted > 0,
                'reason' => $deleted > 0 ? null : 'changed',
                'eligibility' => $eligibility,
            ];
        }, 3);
    }

    /** @return array{enabled:bool,inactivityDays:int,lastRunAt:mixed,lastDeletedCount:int} */
    public function cleanupSettings(): array
    {
        DB::table('customer_cleanup_settings')->insertOrIgnore([
            'id' => 1,
            'enabled' => 1,
            'inactivity_days' => 30,
            'last_deleted_count' => 0,
            'updated_at' => now(),
        ]);
        $row = DB::table('customer_cleanup_settings')->where('id', 1)->first();

        return [
            'enabled' => (bool) ($row->enabled ?? true),
            'inactivityDays' => max(7, min(365, (int) ($row->inactivity_days ?? 30))),
            'lastRunAt' => $row->last_run_at ?? null,
            'lastDeletedCount' => max(0, (int) ($row->last_deleted_count ?? 0)),
        ];
    }

    public function saveCleanupSettings(bool $enabled, int $inactivityDays): array
    {
        DB::table('customer_cleanup_settings')->updateOrInsert(
            ['id' => 1],
            [
                'enabled' => $enabled ? 1 : 0,
                'inactivity_days' => max(7, min(365, $inactivityDays)),
                'updated_at' => now(),
            ],
        );

        return $this->cleanupSettings();
    }

    /** @return array<string,mixed> */
    public function cleanupDormant(bool $force = false): array
    {
        $settings = $this->cleanupSettings();
        if (!$settings['enabled'] && !$force) {
            return ['skipped' => true, 'deleted' => 0, 'settings' => $settings];
        }

        $cutoff = now()->subDays((int) $settings['inactivityDays']);
        $deleted = DB::table('customer_users')
            ->where('email', 'not like', '__lfadmin__:%')
            ->where('balance', 0)
            ->whereRaw('COALESCE(last_login_at, created_at) <= ?', [$cutoff])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('orders as o')->whereColumn('o.customer_id', 'customer_users.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wallet_topups as t')->whereColumn('t.customer_id', 'customer_users.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wallet_transactions as w')->whereColumn('w.customer_id', 'customer_users.id'))
            ->delete();

        DB::table('customer_cleanup_settings')->where('id', 1)->update([
            'last_run_at' => now(),
            'last_deleted_count' => max(0, (int) $deleted),
            'updated_at' => now(),
        ]);

        return [
            'skipped' => false,
            'deleted' => max(0, (int) $deleted),
            'settings' => $this->cleanupSettings(),
        ];
    }

    private function tierForProgress(int $progress): string
    {
        if ($progress >= self::TIERS['platinum']['minSpend']) return 'platinum';
        if ($progress >= self::TIERS['diamond']['minSpend']) return 'diamond';
        if ($progress >= self::TIERS['gold']['minSpend']) return 'gold';
        return 'basic';
    }

    /** @return array{balanceBefore:int,balanceAfter:int} */
    private function adjustLockedCustomerBalance(
        string $customerId,
        string $operation,
        int $amount,
        string $adminEmail,
        string $reason,
        string $label = 'Saldo pelanggan',
    ): array {
        $row = DB::table('customer_users')->where('id', $customerId)->lockForUpdate()->first(['id', 'balance']);
        if (!$row) {
            throw new RuntimeException('Akun saldo tidak ditemukan.');
        }

        $before = max(0, (int) $row->balance);
        if ($operation === 'debit' && $before < $amount) {
            throw new RuntimeException('Saldo tidak mencukupi untuk dikurangi.');
        }

        $direction = $operation === 'credit' ? 'credit' : 'debit';
        $after = $direction === 'credit' ? $before + $amount : $before - $amount;
        $reference = 'admin-adjustment:'.Str::uuid();

        DB::table('wallet_transactions')->insert([
            'id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'direction' => $direction,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => $reference,
            'description' => $label.' oleh '.$adminEmail.': '.trim($reason),
            'created_at' => now(),
        ]);
        DB::table('customer_users')->where('id', $customerId)->update([
            'balance' => $after,
            'updated_at' => now(),
        ]);

        return ['balanceBefore' => $before, 'balanceAfter' => $after];
    }

    /** @return array<string,mixed> */
    private function eligibility(string $customerId, int $balance): array
    {
        $orderCount = DB::table('orders')->where('customer_id', $customerId)->count();
        $topupCount = DB::table('wallet_topups')->where('customer_id', $customerId)->count();
        $walletTransactionCount = DB::table('wallet_transactions')->where('customer_id', $customerId)->count();

        return [
            'customerId' => $customerId,
            'balance' => $balance,
            'orderCount' => $orderCount,
            'topupCount' => $topupCount,
            'walletTransactionCount' => $walletTransactionCount,
            'canDelete' => $balance === 0 && $orderCount === 0 && $topupCount === 0 && $walletTransactionCount === 0,
        ];
    }
}
