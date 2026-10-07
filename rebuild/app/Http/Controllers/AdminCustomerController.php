<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\MembershipTier;
use App\Services\AdminAuditService;
use App\Services\CustomerAccountDeletion;
use App\Services\CustomerCleanupService;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminCustomerController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tier' => ['nullable', 'string', 'max:20'],
            'membership_mode' => ['nullable', Rule::in(['AUTO', 'MANUAL'])],
            'verification' => ['nullable', Rule::in(['verified', 'unverified'])],
            'activity' => ['nullable', Rule::in(['active_30d', 'inactive_30d'])],
            'orders' => ['nullable', Rule::in(['with_orders', 'without_orders'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $filters = [
            'q' => mb_substr(trim((string) ($filters['q'] ?? '')), 0, 100),
            'tier' => strtoupper(trim((string) ($filters['tier'] ?? ''))),
            'membership_mode' => strtoupper(trim((string) ($filters['membership_mode'] ?? ''))),
            'verification' => (string) ($filters['verification'] ?? ''),
            'activity' => (string) ($filters['activity'] ?? ''),
            'orders' => (string) ($filters['orders'] ?? ''),
            'per_page' => (int) ($filters['per_page'] ?? 25),
        ];

        if ($filters['tier'] !== '' && !MembershipTier::where('code', $filters['tier'])->exists()) {
            $filters['tier'] = '';
        }

        $query = DB::table('users')
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('users.name', 'like', $like)
                        ->orWhere('users.email', 'like', $like)
                        ->orWhere('users.phone', 'like', $like);
                });
            })
            ->when($filters['tier'] !== '', fn ($query) => $query->where('users.membership_tier_code', $filters['tier']))
            ->when($filters['membership_mode'] !== '', fn ($query) => $query->where('users.membership_mode', $filters['membership_mode']))
            ->when($filters['verification'] === 'verified', fn ($query) => $query->whereNotNull('users.email_verified_at'))
            ->when($filters['verification'] === 'unverified', fn ($query) => $query->whereNull('users.email_verified_at'))
            ->when($filters['activity'] === 'active_30d', fn ($query) => $query->whereRaw('COALESCE(users.last_active_at, users.created_at) >= ?', [now()->subDays(30)]))
            ->when($filters['activity'] === 'inactive_30d', fn ($query) => $query->whereRaw('COALESCE(users.last_active_at, users.created_at) < ?', [now()->subDays(30)]))
            ->when($filters['orders'] === 'with_orders', fn ($query) => $query->whereExists(
                fn ($sub) => $sub->selectRaw('1')->from('orders')->whereColumn('orders.user_id', 'users.id')
            ))
            ->when($filters['orders'] === 'without_orders', fn ($query) => $query->whereNotExists(
                fn ($sub) => $sub->selectRaw('1')->from('orders')->whereColumn('orders.user_id', 'users.id')
            ))
            ->orderByDesc('users.id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.email_verified_at',
                'users.google_sub',
                'users.membership_tier_code',
                'users.membership_mode',
                'users.membership_override_code',
                'users.membership_progress_bonus_idr',
                'users.leaderboard_opt_in',
                'users.last_active_at',
                'users.created_at',
                'wallets.balance_idr',
                DB::raw('(SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id) as order_count'),
                DB::raw("(SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id AND o.status = 'SUCCESS') as successful_order_count"),
                DB::raw("(SELECT COALESCE(SUM(o.total_idr),0) FROM orders o WHERE o.user_id=users.id AND o.status IN ('PAID','PROCESSING','SUCCESS')) as lifetime_spend_idr"),
                DB::raw("(SELECT COUNT(*) FROM support_tickets st WHERE st.user_id = users.id AND st.status IN ('OPEN','IN_PROGRESS')) as open_ticket_count"),
                DB::raw('(SELECT COUNT(*) FROM support_tickets st WHERE st.user_id = users.id) as ticket_count'),
                DB::raw('(SELECT COUNT(*) FROM wallet_topups wt WHERE wt.user_id = users.id) as topup_count'),
                DB::raw('(SELECT COUNT(*) FROM wallet_ledger wl WHERE wl.wallet_id = wallets.id) as ledger_count'),
                DB::raw('(SELECT COUNT(*) FROM saved_game_accounts sga WHERE sga.user_id = users.id) as saved_account_count'),
            ]);

        $customers = $query->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => $row->email,
                'phone' => $row->phone,
                'email_verified' => $row->email_verified_at !== null,
                'google_linked' => filled($row->google_sub),
                'membership_tier_code' => (string) $row->membership_tier_code,
                'membership_mode' => (string) $row->membership_mode,
                'membership_assignment' => $row->membership_mode === 'MANUAL'
                    ? ($row->membership_override_code ?: $row->membership_tier_code)
                    : 'AUTO',
                'membership_progress_bonus_idr' => (int) $row->membership_progress_bonus_idr,
                'leaderboard_opt_in' => (bool) $row->leaderboard_opt_in,
                'balance_idr' => (int) ($row->balance_idr ?? 0),
                'order_count' => (int) $row->order_count,
                'successful_order_count' => (int) $row->successful_order_count,
                'lifetime_spend_idr' => (int) $row->lifetime_spend_idr,
                'open_ticket_count' => (int) $row->open_ticket_count,
                'saved_account_count' => (int) $row->saved_account_count,
                'deletable' => (int) ($row->balance_idr ?? 0) === 0
                    && (int) $row->order_count === 0
                    && (int) $row->ticket_count === 0
                    && (int) $row->topup_count === 0
                    && (int) $row->ledger_count === 0,
                'last_active_at' => $row->last_active_at,
                'created_at' => $row->created_at,
            ]);

        $activeUsers = DB::table('users')->whereNull('deleted_at');

        return Inertia::render('Admin/Customers', [
            'isSuperAdmin' => $request->user('admin')?->role === 'SUPER_ADMIN',
            'customers' => $customers,
            'filters' => $filters,
            'membershipTiers' => DB::table('membership_tiers')
                ->where('is_active', true)->orderBy('rank')->get(['code', 'rank']),
            'cleanupSettings' => app(CustomerCleanupService::class)->settings(),
            'summary' => [
                'total' => (clone $activeUsers)->count(),
                'new_30d' => (clone $activeUsers)->where('created_at', '>=', now()->subDays(30))->count(),
                'verified' => (clone $activeUsers)->whereNotNull('email_verified_at')->count(),
                'google_linked' => (clone $activeUsers)->whereNotNull('google_sub')->count(),
                'wallet_total_idr' => (int) DB::table('wallets')
                    ->join('users', 'users.id', '=', 'wallets.user_id')
                    ->whereNull('users.deleted_at')->sum('wallets.balance_idr'),
                'open_tickets' => DB::table('support_tickets')
                    ->whereNotNull('user_id')->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count(),
            ],
        ]);
    }

    public function show(Request $request, int $userId, MembershipService $membership): Response
    {
        $user = User::query()->whereNull('deleted_at')->findOrFail($userId);
        $wallet = $user->wallet;
        $membershipProfile = $membership->profile($user);

        $orders = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.user_id', $userId)
            ->orderByDesc('orders.id')
            ->select([
                'orders.id',
                'orders.order_number',
                'orders.status',
                'orders.total_idr',
                'orders.paid_at',
                'orders.created_at',
                'products.name as product_name',
                'product_packages.name as package_name',
            ])->paginate(20, ['*'], 'orders_page')->withQueryString();

        $ledger = $wallet
            ? DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->orderByDesc('id')->limit(50)
                ->get([
                    'id', 'amount_idr', 'balance_before_idr', 'balance_after_idr',
                    'source', 'reference_type', 'reference_id', 'actor_type', 'created_at',
                ])
            : collect();

        $topups = DB::table('wallet_topups as topups')
            ->leftJoin('payment_channels as channels', 'channels.id', '=', 'topups.payment_channel_id')
            ->where('topups.user_id', $userId)
            ->orderByDesc('topups.id')->limit(30)
            ->get([
                'topups.id', 'topups.amount_idr', 'topups.fee_idr', 'topups.total_idr',
                'topups.status', 'topups.paid_at', 'topups.created_at',
                'channels.name as payment_channel_name',
            ]);

        $tickets = DB::table('support_tickets')
            ->where('user_id', $userId)
            ->orderByDesc('id')->limit(30)
            ->get(['id', 'order_id', 'subject', 'status', 'created_at']);

        $savedAccounts = DB::table('saved_game_accounts as saved')
            ->join('products', 'products.id', '=', 'saved.product_id')
            ->where('saved.user_id', $userId)
            ->orderByDesc('saved.id')->limit(30)
            ->get([
                'saved.id', 'saved.label', 'saved.nickname', 'saved.created_at',
                'products.name as product_name',
            ]);

        $deletable = (int) ($wallet?->balance_idr ?? 0) === 0
            && !($wallet && DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->exists())
            && !DB::table('wallet_topups')->where('user_id', $userId)->exists()
            && !DB::table('orders')->where('user_id', $userId)->exists()
            && !DB::table('support_tickets')->where('user_id', $userId)->exists();

        return Inertia::render('Admin/CustomerDetail', [
            'isSuperAdmin' => $request->user('admin')?->role === 'SUPER_ADMIN',
            'customer' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'email_verified_at' => $user->email_verified_at,
                'google_linked' => filled($user->google_sub),
                'has_password' => filled($user->password),
                'membership_tier_code' => $user->membership_tier_code,
                'membership_mode' => $user->membership_mode,
                'membership_assignment' => $user->membership_mode === 'MANUAL'
                    ? ($user->membership_override_code ?: $user->membership_tier_code)
                    : 'AUTO',
                'leaderboard_opt_in' => (bool) $user->leaderboard_opt_in,
                'last_active_at' => $user->last_active_at,
                'created_at' => $user->created_at,
            ],
            'membershipProfile' => $membershipProfile,
            'membershipTiers' => DB::table('membership_tiers')
                ->where('is_active', true)->orderBy('rank')->get(['code', 'rank']),
            'balanceIdr' => (int) ($wallet?->balance_idr ?? 0),
            'orders' => $orders,
            'ledger' => $ledger,
            'topups' => $topups,
            'tickets' => $tickets,
            'savedAccounts' => $savedAccounts,
            'summary' => [
                'orders' => DB::table('orders')->where('user_id', $userId)->count(),
                'successful_orders' => DB::table('orders')->where('user_id', $userId)->where('status', 'SUCCESS')->count(),
                'lifetime_spend_idr' => (int) DB::table('orders')->where('user_id', $userId)
                    ->whereIn('status', ['PAID', 'PROCESSING', 'SUCCESS'])->sum('total_idr'),
                'topups' => DB::table('wallet_topups')->where('user_id', $userId)->count(),
                'tickets' => DB::table('support_tickets')->where('user_id', $userId)->count(),
                'saved_accounts' => DB::table('saved_game_accounts')->where('user_id', $userId)->count(),
            ],
            'deletable' => $deletable,
        ]);
    }

    public function cleanupSettings(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'inactivity_days' => ['required', 'integer', 'min:7', 'max:365'],
        ]);

        $service = app(CustomerCleanupService::class);
        $before = $service->settings();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'customers.cleanup'],
            [
                'value' => json_encode([...$before, ...$data], JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $audit->record(
            $request,
            'customer.cleanup.settings_updated',
            'system_setting',
            'customers.cleanup',
            $before,
            $data,
        );

        return back()->with('status', 'Pengaturan pembersihan akun disimpan.');
    }

    public function cleanup(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $count = app(CustomerCleanupService::class)->run(true);
        $audit->record(
            $request,
            'customer.cleanup.executed',
            'user',
            'empty-inactive',
            null,
            ['deleted' => $count],
        );

        return back()->with('status', $count.' akun kosong dinonaktifkan dan data pribadinya dihapus.');
    }

    public function destroy(Request $request, int $userId, AdminAuditService $audit): RedirectResponse
    {
        $user = User::query()->whereNull('deleted_at')->findOrFail($userId);
        app(CustomerAccountDeletion::class)->delete($user);
        $audit->record(
            $request,
            'customer.empty_account.deleted',
            'user',
            $userId,
            null,
            ['deleted' => true],
        );

        return redirect('/admin/customers')
            ->with('status', 'Akun kosong dihapus. Akun dengan saldo atau riwayat tetap dilindungi.');
    }

    public function adjustWallet(Request $request, int $userId, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'amount_idr' => ['required', 'integer', 'min:-1000000000', 'max:1000000000', 'not_in:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ]);

        DB::transaction(function () use ($request, $userId, $data, $audit): void {
            User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($userId);
            $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
            abort_unless($wallet, 404);

            if (DB::table('wallet_ledger')->where('idempotency_key', $data['idempotency_key'])->exists()) {
                return;
            }

            $beforeBalance = (int) $wallet->balance_idr;
            $afterBalance = $beforeBalance + (int) $data['amount_idr'];
            if ($afterBalance < 0) {
                throw ValidationException::withMessages([
                    'amount_idr' => 'Saldo tidak boleh menjadi negatif.',
                ]);
            }

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance_idr' => $afterBalance,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            DB::table('wallet_ledger')->insert([
                'wallet_id' => $wallet->id,
                'amount_idr' => (int) $data['amount_idr'],
                'balance_before_idr' => $beforeBalance,
                'balance_after_idr' => $afterBalance,
                'source' => 'ADMIN_ADJUSTMENT',
                'reference_type' => 'CUSTOMER',
                'reference_id' => substr($userId.':'.$data['idempotency_key'], 0, 100),
                'actor_type' => 'admin_user',
                'actor_id' => (string) $request->user('admin')->id,
                'idempotency_key' => $data['idempotency_key'],
                'created_at' => now(),
            ]);
            $audit->record(
                $request,
                'customer.wallet.adjusted',
                'user',
                $userId,
                ['balance_idr' => $beforeBalance],
                [
                    'balance_idr' => $afterBalance,
                    'amount_idr' => (int) $data['amount_idr'],
                    'reason' => $data['reason'],
                ],
            );
        }, 3);

        return back()->with('status', 'Saldo pelanggan berhasil disesuaikan.');
    }

    public function updateMembership(
        Request $request,
        int $userId,
        AdminAuditService $audit,
        MembershipService $membership,
    ): RedirectResponse {
        $data = $request->validate([
            'membership_tier_code' => ['required', 'string', 'max:20'],
        ]);
        $value = strtoupper(trim((string) $data['membership_tier_code']));
        if ($value !== 'AUTO'
            && !DB::table('membership_tiers')->where('code', $value)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'membership_tier_code' => 'Tier membership tidak valid.',
            ]);
        }

        $user = User::query()->whereNull('deleted_at')->findOrFail($userId);
        $before = $user->only([
            'membership_tier_code',
            'membership_mode',
            'membership_override_code',
            'membership_progress_bonus_idr',
        ]);
        $membership->setMode($user, $value);
        $after = $user->fresh()->only([
            'membership_tier_code',
            'membership_mode',
            'membership_override_code',
            'membership_progress_bonus_idr',
        ]);
        $audit->record(
            $request,
            'customer.membership.updated',
            'user',
            $userId,
            $before,
            $after,
        );

        return back()->with('status', 'Membership pelanggan diperbarui.');
    }
}
