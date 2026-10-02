<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminAuditController
{
    public function index(Request $request, AdminAuditService $audit): Response
    {
        $dateToRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('date_from')) {
            $dateToRules[] = 'after_or_equal:date_from';
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'role' => ['nullable', Rule::in(['SUPER_ADMIN', 'ADMIN', 'SYSTEM'])],
            'action' => ['nullable', 'string', 'max:100'],
            'target_type' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => $dateToRules,
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ]);

        $filters = [
            'q' => mb_substr(trim((string) ($validated['q'] ?? '')), 0, 100),
            'actor_id' => isset($validated['actor_id']) ? (int) $validated['actor_id'] : null,
            'role' => (string) ($validated['role'] ?? ''),
            'action' => mb_substr(trim((string) ($validated['action'] ?? '')), 0, 100),
            'target_type' => mb_substr(trim((string) ($validated['target_type'] ?? '')), 0, 80),
            'date_from' => (string) ($validated['date_from'] ?? ''),
            'date_to' => (string) ($validated['date_to'] ?? ''),
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];

        $matchingActorIds = [];
        if ($filters['q'] !== '') {
            $matchingActorIds = AdminUser::query()
                ->where(function ($query) use ($filters): void {
                    $like = '%'.$filters['q'].'%';
                    $query->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all();
        }

        $query = DB::table('audit_logs')
            ->when($filters['q'] !== '', function ($query) use ($filters, $matchingActorIds): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($query) use ($like, $matchingActorIds): void {
                    $query->where('action', 'like', $like)
                        ->orWhere('actor_id', 'like', $like)
                        ->orWhere('target_type', 'like', $like)
                        ->orWhere('target_id', 'like', $like)
                        ->orWhere('correlation_id', 'like', $like)
                        ->orWhere('ip_address', 'like', $like);

                    if ($matchingActorIds !== []) {
                        $query->orWhereIn('actor_id', $matchingActorIds);
                    }
                });
            })
            ->when($filters['actor_id'] !== null, fn ($query) => $query->where('actor_type', 'admin_user')
                ->where('actor_id', (string) $filters['actor_id']))
            ->when($filters['role'] === 'SYSTEM', fn ($query) => $query->where(function ($query): void {
                $query->whereNull('actor_id')
                    ->orWhereNull('actor_type')
                    ->orWhere('actor_type', '!=', 'admin_user');
            }))
            ->when(in_array($filters['role'], ['SUPER_ADMIN', 'ADMIN'], true), fn ($query) => $query->where('actor_role', $filters['role']))
            ->when($filters['action'] !== '', fn ($query) => $query->where('action', $filters['action']))
            ->when($filters['target_type'] !== '', fn ($query) => $query->where('target_type', $filters['target_type']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['date_from'])->startOfDay()))
            ->when($filters['date_to'] !== '', fn ($query) => $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $filters['date_to'])->endOfDay()))
            ->orderByDesc('id');

        $logs = $query
            ->paginate($filters['per_page'], [
                'id',
                'actor_type',
                'actor_id',
                'actor_role',
                'action',
                'target_type',
                'target_id',
                'before',
                'after',
                'ip_address',
                'user_agent',
                'correlation_id',
                'created_at',
            ])
            ->withQueryString();

        $actorIds = $logs->getCollection()
            ->pluck('actor_id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $actorNames = AdminUser::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $logs->through(function (object $row) use ($actorNames, $audit): array {
            $actorId = is_numeric($row->actor_id) ? (int) $row->actor_id : null;
            $systemActor = $row->actor_type !== 'admin_user' || $actorId === null;
            $actor = ! $systemActor && $actorId !== null ? $actorNames->get($actorId) : null;

            return [
                'id' => (int) $row->id,
                'actor' => [
                    'id' => $systemActor ? null : $actorId,
                    'name' => $systemActor ? 'Sistem' : ($actor?->name ?? 'Admin #'.$actorId),
                    'email' => $systemActor ? null : $actor?->email,
                    'role' => $systemActor ? 'SYSTEM' : $row->actor_role,
                ],
                'action' => (string) $row->action,
                'action_label' => $this->actionLabel((string) $row->action),
                'target_type' => (string) $row->target_type,
                'target_label' => $this->targetLabel((string) $row->target_type),
                'target_id' => $row->target_id,
                'before_state' => $this->safeState($row->before, $audit),
                'after_state' => $this->safeState($row->after, $audit),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent ? mb_substr((string) $row->user_agent, 0, 500) : null,
                'correlation_id' => (string) $row->correlation_id,
                'created_at' => Carbon::parse($row->created_at, config('app.timezone', 'UTC'))->toIso8601String(),
            ];
        });

        $summary = [
            'total' => DB::table('audit_logs')->count(),
            'today' => DB::table('audit_logs')->where('created_at', '>=', now()->startOfDay())->count(),
            'last_7_days' => DB::table('audit_logs')->where('created_at', '>=', now()->subDays(7))->count(),
            'actors' => DB::table('audit_logs')->whereNotNull('actor_id')->distinct()->count('actor_id'),
        ];

        $actors = AdminUser::query()
            ->whereIn('role', ['SUPER_ADMIN', 'ADMIN'])
            ->orderByRaw("CASE WHEN role = 'SUPER_ADMIN' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active'])
            ->map(fn (AdminUser $admin): array => [
                'id' => (int) $admin->id,
                'name' => (string) $admin->name,
                'email' => (string) $admin->email,
                'role' => (string) $admin->role,
                'is_active' => (bool) $admin->is_active,
            ])
            ->values();

        $actions = DB::table('audit_logs')
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->limit(250)
            ->pluck('action')
            ->map(fn (string $action): array => [
                'value' => $action,
                'label' => $this->actionLabel($action),
            ])
            ->values();

        $targetTypes = DB::table('audit_logs')
            ->select('target_type')
            ->distinct()
            ->orderBy('target_type')
            ->limit(150)
            ->pluck('target_type')
            ->map(fn (string $target): array => [
                'value' => $target,
                'label' => $this->targetLabel($target),
            ])
            ->values();

        return Inertia::render('Admin/Audit', [
            'logs' => $logs,
            'filters' => $filters,
            'summary' => $summary,
            'actors' => $actors,
            'actions' => $actions,
            'targetTypes' => $targetTypes,
        ]);
    }

    private function safeState(mixed $value, AdminAuditService $audit): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $decoded = is_string($value)
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                : $value;
        } catch (Throwable) {
            return ['catatan' => 'Data audit lama tidak dapat dibaca.'];
        }

        if (is_string($decoded)) {
            return '[Nilai teks disembunyikan untuk keamanan]';
        }

        return $audit->redact($decoded);
    }

    private function actionLabel(string $action): string
    {
        $parts = array_values(array_filter(explode('.', $action), fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return 'Aktivitas Admin';
        }

        $module = [
            'admin' => 'Admin & Akses',
            'order' => 'Pesanan',
            'catalog' => 'Produk',
            'product' => 'Produk',
            'category' => 'Produk',
            'package' => 'Produk',
            'fulfillment' => 'Manual',
            'content' => 'Banner & Konten',
            'banner' => 'Banner & Konten',
            'news' => 'Banner & Konten',
            'review' => 'Banner & Konten',
            'faq' => 'Banner & Konten',
            'digiflazz' => 'Digiflazz',
            'nickname' => 'Validasi Akun',
            'nickname_game_code' => 'Validasi Akun',
            'provider' => 'Provider',
            'payment' => 'Pembayaran',
            'customer' => 'Pelanggan',
            'wallet' => 'Pelanggan',
            'voucher' => 'Promo',
            'promo' => 'Promo',
            'popular' => 'Promo',
            'support' => 'Layanan Pelanggan',
            'report' => 'Laporan',
            'settings' => 'Pengaturan',
            'membership' => 'Pengaturan',
            'configuration' => 'Pengaturan',
            'integration' => 'Integrasi',
        ][$parts[0]] ?? 'Administrasi';

        $operation = array_pop($parts);
        array_shift($parts);

        $operationLabel = [
            'created' => 'ditambahkan',
            'updated' => 'diperbarui',
            'deleted' => 'dihapus',
            'enabled' => 'diaktifkan',
            'disabled' => 'dinonaktifkan',
            'revealed' => 'ditampilkan',
            'exported' => 'diekspor',
            'adjusted' => 'disesuaikan',
            'confirmed' => 'dikonfirmasi',
            'completed' => 'diselesaikan',
            'failed' => 'ditandai gagal',
            'retried' => 'dicoba ulang',
            'reconciled' => 'direkonsiliasi',
            'synced' => 'disinkronkan',
            'imported' => 'diimpor',
            'reordered' => 'diurutkan ulang',
            'uploaded' => 'diunggah',
            'tested' => 'dites',
            'settings_updated' => 'pengaturan diperbarui',
            'status_updated' => 'status diperbarui',
            'margin_updated' => 'margin diperbarui',
            'price_updated' => 'harga diperbarui',
            'toggled' => 'diubah statusnya',
            'activated' => 'diaktifkan',
            'deactivated' => 'dinonaktifkan',
            'replied' => 'dibalas',
            'sent' => 'dikirim',
            'resolved' => 'diselesaikan',
            'closed' => 'ditutup',
        ][$operation] ?? str_replace('_', ' ', $operation);

        $subjectMap = [
            'secret' => 'kredensial',
            'channel' => 'metode pembayaran',
            'gateway' => 'gateway',
            'route' => 'routing',
            'wallet' => 'saldo',
            'tier' => 'membership',
            'membership' => 'membership',
            'monitor' => 'monitor',
            'settings' => 'pengaturan',
            'game_code' => 'kode game',
            'quick_reply' => 'balasan cepat',
            'presentation' => 'tampilan',
            'banner' => 'banner',
            'media' => 'media',
            'category' => 'kategori',
            'mapping' => 'mapping provider',
            'product' => 'produk',
            'package' => 'nominal',
            'store' => 'toko',
        ];

        $subject = collect($parts)
            ->map(fn (string $part): string => $subjectMap[$part] ?? str_replace('_', ' ', $part))
            ->filter()
            ->implode(' ');

        return trim($module.' — '.($subject !== '' ? ucfirst($subject).' ' : '').$operationLabel);
    }

    private function targetLabel(string $target): string
    {
        return [
            'admin_user' => 'Akun Admin',
            'order' => 'Pesanan',
            'product' => 'Produk',
            'product_package' => 'Nominal',
            'product_category' => 'Kategori',
            'provider' => 'Provider',
            'provider_mapping' => 'Mapping Provider',
            'payment' => 'Pembayaran',
            'payment_channel' => 'Metode Pembayaran',
            'payment_gateway' => 'Gateway Pembayaran',
            'customer' => 'Pelanggan',
            'customer_user' => 'Pelanggan',
            'wallet' => 'Saldo Pelanggan',
            'voucher' => 'Voucher',
            'support_ticket' => 'Tiket Pelanggan',
            'integration' => 'Integrasi',
            'integration_credential' => 'Integrasi',
            'settings' => 'Pengaturan',
            'system_setting' => 'Pengaturan Sistem',
            'home_banner' => 'Banner',
            'nickname_game_code' => 'Kode Game',
            'membership_tier' => 'Membership',
            'report' => 'Laporan',
        ][$target] ?? ucwords(str_replace('_', ' ', $target));
    }
}
