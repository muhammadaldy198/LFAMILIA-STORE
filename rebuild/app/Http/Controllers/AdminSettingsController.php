<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminSettingsController
{
    private const STORE_KEYS = [
        'store.name',
        'store.tagline',
        'store.support_whatsapp',
        'store.instagram_url',
        'store.email',
        'store.discord_url',
        'store.support_url',
        'store.business_hours',
        'store.legal_name',
        'store.registration_id',
        'store.address',
    ];

    public function index(Request $request): Response
    {
        $stored = DB::table('system_settings')
            ->whereIn('key', self::STORE_KEYS)
            ->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true));

        $tiers = DB::table('membership_tiers')->orderBy('rank')->get()
            ->map(function (object $tier): array {
                $requirements = $this->jsonArray($tier->requirements);
                $benefits = $this->jsonArray($tier->benefits);

                return [
                    'code' => (string) $tier->code,
                    'rank' => (int) $tier->rank,
                    'is_active' => (bool) $tier->is_active,
                    'minimum_spend_idr' => max(0, (int) ($requirements['minimum_spend_idr'] ?? 0)),
                    'discount_percent' => round(
                        max(0, min(10000, (int) ($benefits['discount_bps'] ?? 0))) / 100,
                        2
                    ),
                    'benefit_notes' => (string) ($benefits['benefit_notes'] ?? ''),
                    'has_legacy_requirements' => count(array_diff_key($requirements, ['minimum_spend_idr' => true])) > 0,
                    'has_legacy_benefits' => count(array_diff_key($benefits, [
                        'discount_bps' => true,
                        'benefit_notes' => true,
                    ])) > 0,
                ];
            })->values();

        return Inertia::render('Admin/Settings', [
            'settings' => collect(self::STORE_KEYS)->mapWithKeys(fn (string $key): array => [
                $key => $stored[$key] ?? '',
            ]),
            'tiers' => $tiers,
            'canExport' => $request->user('admin')->role === 'SUPER_ADMIN',
            'summary' => [
                'active_tiers' => $tiers->where('is_active', true)->count(),
                'total_tiers' => $tiers->count(),
                'contacts_configured' => collect([
                    $stored['store.support_whatsapp'] ?? '',
                    $stored['store.email'] ?? '',
                    $stored['store.instagram_url'] ?? '',
                    $stored['store.discord_url'] ?? '',
                ])->filter(fn ($value): bool => trim((string) $value) !== '')->count(),
                'merchant_identity_complete' => collect([
                    $stored['store.legal_name'] ?? '',
                    $stored['store.registration_id'] ?? '',
                    $stored['store.address'] ?? '',
                ])->every(fn ($value): bool => trim((string) $value) !== ''),
            ],
        ]);
    }

    public function updateStore(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'min:2', 'max:80'],
            'tagline' => ['required', 'string', 'min:3', 'max:160'],
            'support_whatsapp' => [
                'nullable',
                'string',
                'max:32',
                'regex:/^\+?[0-9][0-9\s\-]{7,30}$/',
            ],
            'instagram_url' => ['nullable', 'url:http,https', 'max:500'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'discord_url' => ['nullable', 'url:http,https', 'max:500'],
            'support_url' => [
                'nullable',
                'string',
                'max:500',
                'regex:/^(\/(?!\/)|https?:\/\/)/i',
            ],
            'business_hours' => ['required', 'string', 'min:3', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_id' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
        ], [
            'support_whatsapp.regex' => 'Nomor WhatsApp hanya boleh berisi angka, spasi, tanda +, atau tanda -.',
            'support_url.regex' => 'Tautan bantuan harus berupa path internal atau URL HTTP/HTTPS.',
        ]);

        $mapping = [
            'store.name' => trim($data['store_name']),
            'store.tagline' => trim($data['tagline']),
            'store.support_whatsapp' => trim((string) ($data['support_whatsapp'] ?? '')),
            'store.instagram_url' => trim((string) ($data['instagram_url'] ?? '')),
            'store.email' => strtolower(trim((string) ($data['email'] ?? ''))),
            'store.discord_url' => trim((string) ($data['discord_url'] ?? '')),
            'store.support_url' => trim((string) ($data['support_url'] ?? '')),
            'store.business_hours' => trim($data['business_hours']),
            'store.legal_name' => trim((string) ($data['legal_name'] ?? '')),
            'store.registration_id' => trim((string) ($data['registration_id'] ?? '')),
            'store.address' => trim((string) ($data['address'] ?? '')),
        ];

        $before = DB::table('system_settings')
            ->whereIn('key', array_keys($mapping))
            ->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true))
            ->all();

        DB::transaction(function () use ($request, $mapping): void {
            foreach ($mapping as $key => $value) {
                $current = DB::table('system_settings')->where('key', $key)->lockForUpdate()->first();
                if ($current) {
                    DB::table('system_settings')->where('key', $key)->update([
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'version' => ((int) $current->version) + 1,
                        'updated_by_admin_id' => $request->user('admin')->id,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('system_settings')->insert([
                        'key' => $key,
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'version' => 1,
                        'updated_by_admin_id' => $request->user('admin')->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $audit->record($request, 'settings.store.updated', 'system_setting', 'store', $before, $mapping);

        return back()->with('status', 'Pengaturan toko berhasil disimpan.');
    }

    public function updateTier(
        Request $request,
        string $code,
        AdminAuditService $audit,
    ): RedirectResponse {
        $tier = DB::table('membership_tiers')->where('code', strtoupper($code))->first();
        abort_unless($tier, 404);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'minimum_spend_idr' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'benefit_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = [
            'code' => (string) $tier->code,
            'rank' => (int) $tier->rank,
            'is_active' => (bool) $tier->is_active,
            'requirements' => $this->jsonArray($tier->requirements),
            'benefits' => $this->jsonArray($tier->benefits),
        ];

        $requirements = $before['requirements'];
        $benefits = $before['benefits'];

        $requirements['minimum_spend_idr'] = (int) $data['minimum_spend_idr'];
        $benefits['discount_bps'] = (int) round(((float) $data['discount_percent']) * 100);

        $notes = trim((string) ($data['benefit_notes'] ?? ''));
        if ($notes === '') {
            unset($benefits['benefit_notes']);
        } else {
            $benefits['benefit_notes'] = $notes;
        }

        DB::table('membership_tiers')->where('id', $tier->id)->update([
            'is_active' => $data['is_active'],
            'requirements' => json_encode($requirements, JSON_THROW_ON_ERROR),
            'benefits' => json_encode($benefits, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $after = [
            'code' => (string) $tier->code,
            'rank' => (int) $tier->rank,
            'is_active' => (bool) $data['is_active'],
            'requirements' => $requirements,
            'benefits' => $benefits,
        ];

        $audit->record(
            $request,
            'membership_tier.updated',
            'membership_tier',
            (string) $tier->code,
            $before,
            $after
        );

        return back()->with('status', 'Pengaturan membership '.$tier->code.' berhasil disimpan.');
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
                $rows = DB::table($table)
                    ->orderBy($table === 'content_pages' ? 'key' : 'id')
                    ->get($columns);
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

    /**
     * @return array<string, mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
