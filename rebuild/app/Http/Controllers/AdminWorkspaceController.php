<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\TransactionalEmailService;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use Throwable;

class AdminWorkspaceController
{
    public function orders(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'orders',
            'title' => 'Pesanan',
            'rows' => DB::table('orders')
                ->join('products', 'products.id', '=', 'orders.product_id')
                ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
                ->orderByDesc('orders.id')->limit(150)
                ->get([
                    'orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr',
                    'orders.paid_at', 'orders.created_at', 'products.name as product_name',
                    'product_packages.name as package_name',
                ]),
        ]);
    }

    public function providers(Request $request): Response
    {
        $provider = strtoupper(trim((string) $request->query('provider', '')));

        return Inertia::render('Admin/Workspace', [
            'kind' => 'providers',
            'title' => $provider === 'DIGIFLAZZ' ? 'Digiflazz' : 'Provider',
            'rows' => DB::table('providers')
                ->when($provider !== '', fn ($query) => $query->where('code', $provider))
                ->orderBy('id')->get()
                ->map(function (object $row): array {
                    return [
                        ...((array) $row),
                        'mapping_count' => DB::table('provider_mappings')->where('provider_id', $row->id)->count(),
                        'active_mapping_count' => DB::table('provider_mappings')
                            ->where('provider_id', $row->id)->where('is_active', true)->count(),
                    ];
                }),
        ]);
    }

    public function updateProvider(Request $request, int $id, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $before = DB::table('providers')->where('id', $id)->first();
        abort_unless($before, 404);

        DB::table('providers')->where('id', $id)->update([
            'is_active' => $data['is_active'],
            'updated_at' => now(),
        ]);
        $audit->record($request, 'provider.updated', 'provider', $id, (array) $before, $data);

        return back();
    }

    public function customers(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'customers',
            'title' => 'Pelanggan',
            'rows' => DB::table('users')
                ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
                ->whereNull('users.deleted_at')
                ->orderByDesc('users.id')->limit(150)
                ->get([
                    'users.id', 'users.name', 'users.email', 'users.phone',
                    'users.membership_tier_code', 'users.membership_mode',
                    'users.membership_override_code', 'users.membership_progress_bonus_idr',
                    'users.created_at', 'wallets.balance_idr',
                    DB::raw("(SELECT COALESCE(SUM(o.total_idr),0) FROM orders o WHERE o.user_id=users.id AND o.status IN ('PAID','PROCESSING','SUCCESS')) as lifetime_spend_idr"),
                ])->map(fn (object $row): array => [
                    ...((array) $row),
                    'membership_assignment' => $row->membership_mode === 'MANUAL'
                        ? ($row->membership_override_code ?: $row->membership_tier_code)
                        : 'AUTO',
                ]),
            'membershipTiers' => DB::table('membership_tiers')->where('is_active', true)->orderBy('rank')->pluck('code'),
        ]);
    }

    public function adjustWallet(Request $request, int $userId, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'amount_idr' => ['required', 'integer', 'min:-1000000000', 'max:1000000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($request, $userId, $data, $audit): void {
            $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
            abort_unless($wallet, 404);

            if (DB::table('wallet_ledger')->where('idempotency_key', $data['idempotency_key'])->exists()) {
                return;
            }

            $beforeBalance = (int) $wallet->balance_idr;
            $afterBalance = $beforeBalance + (int) $data['amount_idr'];
            if ($afterBalance < 0) {
                throw ValidationException::withMessages(['amount_idr' => 'Saldo tidak boleh menjadi negatif.']);
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
            $audit->record($request, 'customer.wallet.adjusted', 'user', $userId,
                ['balance_idr' => $beforeBalance],
                ['balance_idr' => $afterBalance, 'amount_idr' => (int) $data['amount_idr'], 'reason' => $data['reason']]
            );
        }, 3);

        return back();
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
        if ($value !== 'AUTO' && ! DB::table('membership_tiers')->where('code', $value)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['membership_tier_code' => 'Tier membership tidak valid.']);
        }

        $user = \App\Models\User::findOrFail($userId);
        $before = $user->only([
            'membership_tier_code', 'membership_mode',
            'membership_override_code', 'membership_progress_bonus_idr',
        ]);
        $membership->setMode($user, $value);
        $after = $user->fresh()->only([
            'membership_tier_code', 'membership_mode',
            'membership_override_code', 'membership_progress_bonus_idr',
        ]);
        $audit->record($request, 'customer.membership.updated', 'user', $userId, $before, $after);

        return back();
    }

    public function vouchers(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'vouchers',
            'title' => 'Promo & Voucher',
            'rows' => DB::table('vouchers')->orderByDesc('id')->limit(150)->get(),
        ]);
    }

    public function storeVoucher(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->voucherData($request);
        $id = DB::table('vouchers')->insertGetId([
            ...$data,
            'code' => strtoupper($data['code']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record($request, 'voucher.created', 'voucher', $id, null, $data);

        return back();
    }

    public function updateVoucher(Request $request, int $id, AdminAuditService $audit): RedirectResponse
    {
        $before = DB::table('vouchers')->where('id', $id)->first();
        abort_unless($before, 404);
        $data = $this->voucherData($request, $id);
        DB::table('vouchers')->where('id', $id)->update([
            ...$data,
            'code' => strtoupper($data['code']),
            'updated_at' => now(),
        ]);
        $audit->record($request, 'voucher.updated', 'voucher', $id, (array) $before, $data);

        return back();
    }

    public function support(): Response
    {
        $rows = DB::table('support_tickets as tickets')
            ->join('users', 'users.id', '=', 'tickets.user_id')
            ->leftJoin('orders', 'orders.id', '=', 'tickets.order_id')
            ->orderByDesc('tickets.id')->limit(150)
            ->get([
                'tickets.id', 'tickets.user_id', 'tickets.subject', 'tickets.message', 'tickets.status',
                'tickets.created_at', 'users.name as customer_name', 'users.email',
                'orders.order_number',
            ])->map(function (object $ticket): array {
                return [
                    ...((array) $ticket),
                    'messages' => DB::table('support_ticket_messages as messages')
                        ->leftJoin('users', 'users.id', '=', 'messages.user_id')
                        ->leftJoin('admin_users', 'admin_users.id', '=', 'messages.admin_user_id')
                        ->where('messages.support_ticket_id', $ticket->id)
                        ->orderBy('messages.id')
                        ->get([
                            'messages.id', 'messages.sender_type', 'messages.message', 'messages.created_at',
                            'users.name as customer_name', 'admin_users.name as admin_name',
                        ])->values(),
                ];
            });

        return Inertia::render('Admin/Workspace', [
            'kind' => 'support',
            'title' => 'Layanan Pelanggan',
            'rows' => $rows,
        ]);
    }

    public function updateSupport(
        Request $request,
        int $id,
        AdminAuditService $audit,
        TransactionalEmailService $emails,
    ): RedirectResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in(['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'])],
            'reply' => ['nullable', 'string', 'max:5000'],
        ]);
        $before = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($before, 404);

        DB::transaction(function () use ($request, $id, $data): void {
            DB::table('support_tickets')->where('id', $id)->update([
                'status' => $data['status'],
                'updated_at' => now(),
            ]);
            $reply = trim((string) ($data['reply'] ?? ''));
            if ($reply !== '') {
                DB::table('support_ticket_messages')->insert([
                    'support_ticket_id' => $id,
                    'sender_type' => 'ADMIN',
                    'user_id' => null,
                    'admin_user_id' => $request->user('admin')->id,
                    'message' => $reply,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $reply = trim((string) ($data['reply'] ?? ''));
        if ($reply !== '') {
            $email = DB::table('support_tickets as tickets')
                ->join('users', 'users.id', '=', 'tickets.user_id')
                ->where('tickets.id', $id)->value('users.email');
            if (is_string($email) && $email !== '') {
                $emails->queue(
                    $email,
                    'Balasan tiket LFAMILIA #'.$id,
                    'Tim LFAMILIA membalas tiket #'.$id.': '.$reply
                );
            }
        }

        $audit->record($request, 'support.updated', 'support_ticket', $id, (array) $before, [
            'status' => $data['status'],
            'replied' => $reply !== '',
        ]);

        return back();
    }

    public function reports(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'reports',
            'title' => 'Laporan',
            'report' => [
                'orders_total' => DB::table('orders')->count(),
                'success_total' => DB::table('orders')->where('status', 'SUCCESS')->count(),
                'revenue_total' => (int) DB::table('orders')->whereIn('status', ['PAID', 'PROCESSING', 'SUCCESS'])->sum('total_idr'),
                'wallet_liability' => (int) DB::table('wallets')->sum('balance_idr'),
                'provider_errors' => DB::table('fulfillment_attempts')
                    ->whereIn('status', ['UNKNOWN', 'FAILED_CONFIRMED', 'BLOCKED', 'MANUAL_FAILED'])->count(),
            ],
            'topProducts' => DB::table('orders')
                ->join('products', 'products.id', '=', 'orders.product_id')
                ->where('orders.status', 'SUCCESS')
                ->groupBy('products.id', 'products.name')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(10)
                ->get(['products.name', DB::raw('COUNT(*) as orders_count'), DB::raw('SUM(orders.total_idr) as revenue_idr')]),
        ]);
    }

    public function settings(): Response
    {
        $keys = [
            'store.name', 'store.tagline', 'store.support_whatsapp', 'store.instagram_url',
            'store.email', 'store.discord_url', 'store.support_url', 'store.business_hours',
        ];
        $settings = DB::table('system_settings')->whereIn('key', $keys)->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true));

        return Inertia::render('Admin/Workspace', [
            'kind' => 'settings',
            'title' => 'Pengaturan',
            'settings' => collect($keys)->mapWithKeys(fn (string $key): array => [
                $key => $settings[$key] ?? '',
            ]),
            'tiers' => DB::table('membership_tiers')->orderBy('rank')->get(),
        ]);
    }

    public function updateSettings(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['nullable', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'support_whatsapp' => ['nullable', 'string', 'max:100'],
            'instagram_url' => ['nullable', 'url:http,https', 'max:500'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'discord_url' => ['nullable', 'url:http,https', 'max:500'],
            'support_url' => ['nullable', 'url:http,https', 'max:500'],
            'business_hours' => ['nullable', 'string', 'max:500'],
        ]);
        $mapping = [
            'store.name' => $data['store_name'] ?? '',
            'store.tagline' => $data['tagline'] ?? '',
            'store.support_whatsapp' => $data['support_whatsapp'] ?? '',
            'store.instagram_url' => $data['instagram_url'] ?? '',
            'store.email' => $data['email'] ?? '',
            'store.discord_url' => $data['discord_url'] ?? '',
            'store.support_url' => $data['support_url'] ?? '',
            'store.business_hours' => $data['business_hours'] ?? '',
        ];

        foreach ($mapping as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
        $audit->record($request, 'settings.updated', 'system_setting', 'store', null, $mapping);

        return back();
    }

    public function updateTier(Request $request, string $code, AdminAuditService $audit): RedirectResponse
    {
        $tier = DB::table('membership_tiers')->where('code', $code)->first();
        abort_unless($tier, 404);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'benefits' => ['nullable', 'string', 'max:10000'],
        ]);

        $requirements = $this->jsonObject($data['requirements'] ?? null, 'requirements');
        $benefits = $this->jsonObject($data['benefits'] ?? null, 'benefits');
        DB::table('membership_tiers')->where('code', $code)->update([
            'is_active' => $data['is_active'],
            'requirements' => $requirements === null ? null : json_encode($requirements, JSON_THROW_ON_ERROR),
            'benefits' => $benefits === null ? null : json_encode($benefits, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $audit->record($request, 'membership_tier.updated', 'membership_tier', $code, (array) $tier, [
            'is_active' => $data['is_active'],
            'requirements' => $requirements,
            'benefits' => $benefits,
        ]);

        return back();
    }

    public function audit(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'audit',
            'title' => 'Audit Log',
            'rows' => DB::table('audit_logs')->orderByDesc('id')->paginate(50),
        ]);
    }

    public function health(): Response
    {
        $checks = [[
            'name' => 'Laravel',
            'status' => 'HEALTHY',
            'message' => 'Laravel '.app()->version().' berjalan.',
        ]];

        $queueHeartbeat = DB::table('system_settings')->where('key', 'system.queue_worker_heartbeat')->value('value');
        $queueHeartbeatAt = json_decode((string) $queueHeartbeat, true);
        $healthyQueue = is_string($queueHeartbeatAt)
            && now()->diffInMinutes(Carbon::parse($queueHeartbeatAt), true) <= 3;
        $checks[] = [
            'name' => 'Queue',
            'status' => $healthyQueue ? 'HEALTHY' : 'DEGRADED',
            'message' => $healthyQueue
                ? 'Worker queue '.config('queue.default').' aktif. Heartbeat '.$queueHeartbeatAt
                : 'Worker queue belum memberi heartbeat dalam 3 menit terakhir.',
        ];

        try {
            DB::select('SELECT 1');
            $checks[] = ['name' => 'MySQL', 'status' => 'HEALTHY', 'message' => 'Database dapat diakses.'];
        } catch (Throwable) {
            $checks[] = ['name' => 'MySQL', 'status' => 'DOWN', 'message' => 'Database tidak dapat diakses.'];
        }

        try {
            Redis::connection()->ping();
            $checks[] = ['name' => 'Redis', 'status' => 'HEALTHY', 'message' => 'Redis dapat diakses.'];
        } catch (Throwable) {
            $checks[] = ['name' => 'Redis', 'status' => 'DOWN', 'message' => 'Redis tidak dapat diakses.'];
        }

        $free = @disk_free_space(storage_path());
        $checks[] = [
            'name' => 'Storage',
            'status' => is_numeric($free) && $free > 512 * 1024 * 1024 ? 'HEALTHY' : 'DEGRADED',
            'message' => is_numeric($free) ? 'Free '.round($free / 1024 / 1024).' MB' : 'Kapasitas tidak dapat dibaca.',
        ];

        $heartbeat = DB::table('system_settings')->where('key', 'system.scheduler_heartbeat')->value('value');
        $heartbeatAt = json_decode((string) $heartbeat, true);
        $healthyScheduler = is_string($heartbeatAt)
            && now()->diffInMinutes(Carbon::parse($heartbeatAt), true) <= 3;
        $checks[] = [
            'name' => 'Scheduler',
            'status' => $healthyScheduler ? 'HEALTHY' : 'DEGRADED',
            'message' => $heartbeatAt ?: 'Heartbeat belum tercatat.',
        ];

        foreach (['digiflazz', 'kokinpay', 'midtrans', 'doku', 'resend'] as $code) {
            $active = DB::table('integration_credentials')->where('code', $code)->where('is_active', true)->exists();
            $stored = DB::table('system_settings')->where('key', 'integration.health.'.$code)->value('value');
            $health = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
            $status = $active ? (string) ($health['status'] ?? 'DEGRADED') : 'NOT_CONFIGURED';
            $message = $active
                ? (string) ($health['message'] ?? 'Credential aktif; Tes Koneksi belum dijalankan.')
                : 'Belum aktif.';

            if (in_array($code, ['midtrans', 'doku'], true)) {
                $gateway = DB::table('payment_gateways')
                    ->where('code', strtoupper($code))->first();
                if ($gateway?->is_maintenance) {
                    $status = 'MAINTENANCE';
                    $message = 'Gateway sedang maintenance.';
                }
            }

            $checks[] = [
                'name' => strtoupper($code),
                'status' => $status,
                'message' => $message,
            ];
        }

        return Inertia::render('Admin/Workspace', [
            'kind' => 'health',
            'title' => 'System Health',
            'checks' => $checks,
        ]);
    }

    private function jsonObject(?string $json, string $field): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([$field => 'Harus berupa JSON valid.']);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'Harus berupa JSON object/array.']);
        }

        return $decoded;
    }

    private function voucherData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('vouchers', 'code')->ignore($ignoreId)],
            'discount_type' => ['required', Rule::in(['FIXED', 'PERCENT'])],
            'discount_value' => ['required', 'integer', 'min:1'],
            'minimum_total_idr' => ['required', 'integer', 'min:0'],
            'total_quota' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
