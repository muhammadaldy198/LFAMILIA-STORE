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

        // L4: Preload agregat sekali pakai GROUP BY
        $mappingCounts = DB::table('provider_mappings')
            ->selectRaw('provider_id, COUNT(*) as total, SUM(is_active) as active')
            ->groupBy('provider_id')->get()->keyBy('provider_id');
        $attemptStats = DB::table('fulfillment_attempts')
            ->selectRaw('provider_id, MAX(created_at) as last_at, SUM(status = "SUCCESS" AND created_at >= ?) as success_24h, SUM(status IN ("PENDING", "UNKNOWN", "SENDING")) as attention', [now()->subDay()])
            ->groupBy('provider_id')->get()->keyBy('provider_id');

        $providers = DB::table('providers')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (object $provider) use ($mappingCounts, $attemptStats): array {
                $mc = $mappingCounts->get($provider->id);
                $as = $attemptStats->get($provider->id);

                return [
                    'id' => (int) $provider->id,
                    'code' => (string) $provider->code,
                    'display_name' => (string) ($provider->display_name ?: $provider->code),
                    'description' => $provider->description,
                    'fulfillment_mode' => (string) $provider->fulfillment_mode,
                    'is_active' => (bool) $provider->is_active,
                    'sort_order' => (int) $provider->sort_order,
                    'mapping_count' => (int) ($mc->total ?? 0),
                    'active_mapping_count' => (int) ($mc->active ?? 0),
                    'last_attempt_at' => $as->last_at ?? null,
                    'success_24h' => (int) ($as->success_24h ?? 0),
                    'attention_count' => (int) ($as->attention ?? 0),
                    'health' => $this->health((string) $provider->code, (bool) $provider->is_active),
                ];
            })->values();

        $mappings = DB::table('provider_mappings as mappings')
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

    private function health(string $code, bool $active): array
    {
        if (! $active) {
            return ['status' => 'INACTIVE', 'message' => 'Provider sedang dinonaktifkan.'];
        }

        if ($code !== 'DIGIFLAZZ') {
            return ['status' => 'READY', 'message' => 'Diproses langsung oleh sistem LFAMILIA.'];
        }

        $integrationActive = DB::table('integration_credentials')
            ->where('code', 'digiflazz')->where('is_active', true)->exists();
        if (! $integrationActive) {
            return ['status' => 'NOT_CONFIGURED', 'message' => 'Kredensial Digiflazz belum diaktifkan di menu Integrasi.'];
        }

        $stored = DB::table('system_settings')
            ->where('key', 'integration.health.digiflazz')->value('value');
        $health = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
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
