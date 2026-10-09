<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminProviderController
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'provider' => max(0, (int) $request->query('provider', 0)),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true)
                ? (string) $request->query('status') : '',
            'per_page' => in_array((int) $request->query('per_page', 25), [10, 25, 50, 100], true)
                ? (int) $request->query('per_page', 25) : 25,
        ];

        $mappingStats = DB::table('provider_mappings')
            ->select('provider_id')
            ->selectRaw('COUNT(*) as mapping_count')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_mapping_count')
            ->groupBy('provider_id');

        $attemptStats = DB::table('fulfillment_attempts')
            ->select('provider_id')
            ->selectRaw('MAX(created_at) as last_attempt_at')
            ->selectRaw("SUM(CASE WHEN status = 'SUCCESS' AND created_at >= ? THEN 1 ELSE 0 END) as success_24h", [now()->subDay()])
            ->selectRaw("SUM(CASE WHEN status IN ('PENDING','UNKNOWN','SENDING') THEN 1 ELSE 0 END) as attention_count")
            ->groupBy('provider_id');

        $digiflazzActive = DB::table('integration_credentials')
            ->where('code', 'digiflazz')->where('is_active', true)->exists();
        $digiflazzHealth = DB::table('system_settings')
            ->where('key', 'integration.health.digiflazz')->value('value');

        $providers = DB::table('providers')
            ->leftJoinSub($mappingStats, 'mapping_stats', 'mapping_stats.provider_id', '=', 'providers.id')
            ->leftJoinSub($attemptStats, 'attempt_stats', 'attempt_stats.provider_id', '=', 'providers.id')
            ->orderBy('providers.sort_order')->orderBy('providers.id')
            ->get([
                'providers.*',
                DB::raw('COALESCE(mapping_stats.mapping_count, 0) as mapping_count'),
                DB::raw('COALESCE(mapping_stats.active_mapping_count, 0) as active_mapping_count'),
                'attempt_stats.last_attempt_at',
                DB::raw('COALESCE(attempt_stats.success_24h, 0) as success_24h'),
                DB::raw('COALESCE(attempt_stats.attention_count, 0) as attention_count'),
            ])
            ->map(function (object $provider) use ($digiflazzActive, $digiflazzHealth): array {
                return [
                    'id' => (int) $provider->id,
                    'code' => (string) $provider->code,
                    'display_name' => (string) ($provider->display_name ?: $provider->code),
                    'description' => $provider->description,
                    'fulfillment_mode' => (string) $provider->fulfillment_mode,
                    'is_active' => (bool) $provider->is_active,
                    'sort_order' => (int) $provider->sort_order,
                    'mapping_count' => (int) $provider->mapping_count,
                    'active_mapping_count' => (int) $provider->active_mapping_count,
                    'last_attempt_at' => $provider->last_attempt_at,
                    'success_24h' => (int) $provider->success_24h,
                    'attention_count' => (int) $provider->attention_count,
                    'health' => $this->health((string) $provider->code, (bool) $provider->is_active, $digiflazzActive, $digiflazzHealth),
                ];
            })->values();

        // Rank across the full package, before search filters and pagination. The
        // provider workspace must never mistake a raw priority 10 for position 10.
        $sourcePositions = DB::table('provider_mappings')
            ->where('is_active', true)
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY product_package_id ORDER BY priority, cost_idr, id) as source_position');

        $mappings = DB::table('provider_mappings as mappings')
            ->leftJoinSub($sourcePositions, 'source_positions', 'source_positions.id', '=', 'mappings.id')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages as packages', 'packages.id', '=', 'mappings.product_package_id')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('products.name', 'like', $like)
                        ->orWhere('packages.name', 'like', $like)
                        ->orWhere('packages.code', 'like', $like)
                        ->orWhere('mappings.external_sku', 'like', $like)
                        ->orWhere('providers.code', 'like', $like)
                        ->orWhere('providers.display_name', 'like', $like);
                });
            })
            ->when($filters['provider'] > 0, fn ($query) => $query->where('providers.id', $filters['provider']))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('mappings.is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('mappings.is_active', false))
            ->orderBy('products.name')
            ->orderByRaw('packages.nominal_value IS NULL')
            ->orderBy('packages.nominal_value')
            ->orderBy('packages.sort_order')
            ->orderBy('mappings.priority')
            ->select([
                'mappings.id',
                'mappings.external_sku',
                'mappings.cost_idr',
                'mappings.max_price_idr',
                'mappings.priority',
                'source_positions.source_position',
                'mappings.is_active',
                'providers.id as provider_id',
                'providers.code as provider_code',
                'providers.display_name as provider_name',
                'providers.is_active as provider_active',
                'products.id as product_id',
                'products.name as product_name',
                'products.is_active as product_active',
                'packages.id as package_id',
                'packages.code as package_code',
                'packages.name as package_name',
                'packages.nominal_value',
                'packages.is_active as package_active',
                'categories.name as category_name',
            ])
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (object $row): array => [
                ...((array) $row),
                'cost_idr' => $row->cost_idr !== null ? (int) $row->cost_idr : null,
                'max_price_idr' => $row->max_price_idr !== null ? (int) $row->max_price_idr : null,
                'priority' => (int) $row->priority,
                'source_position' => $row->source_position !== null ? (int) $row->source_position : null,
                'nominal_value' => $row->nominal_value !== null ? (int) $row->nominal_value : null,
                'is_active' => (bool) $row->is_active,
                'provider_active' => (bool) $row->provider_active,
                'product_active' => (bool) $row->product_active,
                'package_active' => (bool) $row->package_active,
            ]);

        return Inertia::render('Admin/Providers', [
            'providers' => $providers,
            'mappings' => $mappings,
            'filters' => $filters,
            'summary' => [
                'provider_total' => DB::table('providers')->count(),
                'provider_active' => DB::table('providers')->where('is_active', true)->count(),
                'mapping_total' => DB::table('provider_mappings')->count(),
                'mapping_active' => DB::table('provider_mappings')->where('is_active', true)->count(),
                'needs_reconciliation' => DB::table('fulfillment_attempts')
                    ->whereIn('status', ['PENDING', 'UNKNOWN', 'SENDING'])->count(),
                'safe_to_failover' => DB::table('fulfillment_attempts')
                    ->where('status', 'FAILED_CONFIRMED')->where('safe_to_failover', true)->count(),
            ],
        ]);
    }

    public function update(Request $request, int $provider, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $before = DB::table('providers')->where('id', $provider)->first();
        abort_unless($before, 404);

        $after = [
            'display_name' => trim($data['display_name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) $data['is_active'],
        ];

        DB::table('providers')->where('id', $provider)->update([
            ...$after,
            'updated_at' => now(),
        ]);

        $audit->record(
            $request,
            'provider.updated',
            'provider',
            $provider,
            [
                'display_name' => $before->display_name,
                'description' => $before->description,
                'sort_order' => (int) $before->sort_order,
                'is_active' => (bool) $before->is_active,
            ],
            $after,
        );

        return back()->with('status', 'Pengaturan provider disimpan.');
    }

    private function health(string $code, bool $active, bool $digiflazzActive, mixed $digiflazzHealth): array
    {
        if (! $active) {
            return ['status' => 'INACTIVE', 'message' => 'Provider sedang dinonaktifkan.'];
        }

        if ($code !== 'DIGIFLAZZ') {
            return ['status' => 'READY', 'message' => 'Diproses langsung oleh sistem LFAMILIA.'];
        }

        if (! $digiflazzActive) {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Kredensial Digiflazz belum diaktifkan di menu Integrasi.'];
        }

        $health = is_string($digiflazzHealth) ? (json_decode($digiflazzHealth, true) ?: []) : [];
        $status = strtoupper((string) ($health['status'] ?? 'UNTESTED'));

        return [
            'status' => $status,
            'message' => match ($status) {
                'HEALTHY' => 'Koneksi terakhir dinyatakan sehat.',
                'DOWN' => 'Koneksi terakhir bermasalah.',
                'STALE' => 'Koneksi perlu diuji ulang.',
                default => 'Koneksi belum diuji.',
            },
            'tested_at' => $health['tested_at'] ?? null,
        ];
    }
}
