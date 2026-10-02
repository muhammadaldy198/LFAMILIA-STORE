<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\TransactionalEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use Throwable;

class AdminWorkspaceController
{
    public function support(): Response
    {
        $rows = DB::table('support_tickets as tickets')
            ->leftJoin('users', 'users.id', '=', 'tickets.user_id')
            ->leftJoin('orders', 'orders.id', '=', 'tickets.order_id')
            ->orderByDesc('tickets.id')->limit(150)
            ->get([
                'tickets.id', 'tickets.user_id', 'tickets.subject', 'tickets.message', 'tickets.status',
                'tickets.created_at', 'users.name as customer_name', 'users.email',
                'orders.order_number', 'orders.guest_email',
            ])->map(function (object $ticket): array {
                return [
                    ...((array) $ticket),
                    'customer_name' => $ticket->customer_name ?? 'Guest',
                    'email' => $ticket->email ?? $ticket->guest_email,
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
            'quickReplies' => json_decode((string) DB::table('system_settings')->where('key', 'support.quick_replies')->value('value'), true) ?? [],
            'kind' => 'support',
            'title' => 'Layanan Pelanggan',
            'rows' => $rows,
        ]);
    }

    public function quickReplies(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate(['replies' => ['present', 'array', 'max:30'], 'replies.*' => ['required', 'string', 'max:1000']]);
        $before = DB::table('system_settings')->where('key', 'support.quick_replies')->value('value');
        DB::table('system_settings')->updateOrInsert(['key' => 'support.quick_replies'], [
            'value' => json_encode(array_values($data['replies'])), 'updated_at' => now(), 'created_at' => now(),
            'updated_by_admin_id' => $request->user('admin')->id,
        ]);
        $audit->record($request, 'support.quick_replies.updated', 'system_setting', 'support.quick_replies', $before ? json_decode($before, true) : null, $data['replies']);

        return back()->with('status', 'Balasan cepat disimpan.');
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
                ->leftJoin('users', 'users.id', '=', 'tickets.user_id')
                ->leftJoin('orders', 'orders.id', '=', 'tickets.order_id')
                ->where('tickets.id', $id)->value(DB::raw('COALESCE(users.email, orders.guest_email)'));
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

    public function reports(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(6)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $orders = fn () => DB::table('orders')->whereBetween('orders.created_at', [$from, $to]);

        return Inertia::render('Admin/Workspace', [
            'kind' => 'reports', 'title' => 'Laporan',
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'report' => [
                'orders_total' => $orders()->count(),
                'success_total' => $orders()->where('status', 'SUCCESS')->count(),
                'revenue_total' => (int) $orders()->whereIn('status', ['PAID', 'PROCESSING', 'SUCCESS'])->sum('total_idr'),
                'wallet_liability' => (int) DB::table('wallets')->sum('balance_idr'),
                'provider_errors' => DB::table('fulfillment_attempts')->whereBetween('created_at', [$from, $to])
                    ->whereIn('status', ['UNKNOWN', 'FAILED_CONFIRMED', 'BLOCKED', 'MANUAL_FAILED'])->count(),
            ],
            'topProducts' => $orders()->join('products', 'products.id', '=', 'orders.product_id')->where('orders.status', 'SUCCESS')
                ->groupBy('products.id', 'products.name')->orderByDesc(DB::raw('COUNT(*)'))->limit(10)
                ->get(['products.name', DB::raw('COUNT(*) as orders_count'), DB::raw('SUM(orders.total_idr) as revenue_idr')]),
            'dailyReport' => $orders()->selectRaw("DATE(created_at) as day, COUNT(*) as orders_count, SUM(CASE WHEN status IN ('PAID','PROCESSING','SUCCESS') THEN total_idr ELSE 0 END) as revenue_idr")
                ->groupByRaw('DATE(created_at)')->orderBy('day')->get(),
            'providerReport' => DB::table('fulfillment_attempts as attempts')->join('providers', 'providers.id', '=', 'attempts.provider_id')
                ->whereBetween('attempts.created_at', [$from, $to])->groupBy('providers.id', 'providers.code')
                ->get(['providers.code', DB::raw('COUNT(*) as attempts_count'),
                    DB::raw("SUM(CASE WHEN attempts.status IN ('UNKNOWN','FAILED_CONFIRMED','BLOCKED','MANUAL_FAILED') THEN 1 ELSE 0 END) as errors_count")]),
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

    public function exportConfiguration(Request $request, AdminAuditService $audit): JsonResponse
    {
        $tables = [
            'system_settings',
            'membership_tiers',
            'categories',
            'products',
            'product_packages',
            'providers',
            'provider_mappings',
            'payment_gateways',
            'payment_channels',
            'payment_routes',
            'vouchers',
            'home_banners',
            'faq_entries',
            'news_articles',
            'content_pages',
            'site_popups',
            'store_assets',
        ];

        $payload = [
            'schema' => 'lfamilia-safe-config-v1',
            'exported_at' => now()->toIso8601String(),
            'app_version' => config('app.version'),
            'data' => [],
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = collect(Schema::getColumnListing($table))
                ->reject(fn (string $column): bool => (bool) preg_match(
                    '/password|secret|token|credential|api[_-]?key|private|signature|cipher|hash/i',
                    $column
                ))
                ->values()->all();

            if ($table === 'system_settings') {
                $rows = DB::table($table)
                    ->where('key', 'not like', 'integration.%')
                    ->where('key', 'not like', '%secret%')
                    ->where('key', 'not like', '%password%')
                    ->where('key', 'not like', '%token%')
                    ->where('key', 'not like', '%api_key%')
                    ->orderBy('key')
                    ->get($columns);
            } else {
                $rows = DB::table($table)->orderBy($table === 'content_pages' ? 'key' : 'id')->get($columns);
            }

            $payload['data'][$table] = $rows;
        }

        $audit->record(
            $request,
            'configuration.exported',
            'configuration',
            'safe-json',
            null,
            ['tables' => array_keys($payload['data'])]
        );

        return response()->json(
            $payload,
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="lfamilia-config-'.now()->format('Ymd-His').'.json"',
                'Cache-Control' => 'no-store, private',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
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
            'support_url' => ['nullable', 'string', 'max:500', 'regex:/^(\/(?!\/)|https?:\/\/)/i'],
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

}
