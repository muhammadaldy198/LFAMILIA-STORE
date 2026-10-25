<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\AdminChoiceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminChoiceSettingsController
{
    public function index(AdminChoiceSettings $choices): Response
    {
        return Inertia::render('Admin/ChoiceSettings', ['choices' => $choices->all()]);
    }

    public function update(Request $request, AdminChoiceSettings $choices, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'cancellations' => ['required', 'array', 'min:1', 'max:25'],
            'cancellations.*.code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'cancellations.*.label' => ['required', 'string', 'max:80'],
            'cancellations.*.active' => ['required', 'boolean'],
            'support' => ['required', 'array', 'min:1', 'max:25'],
            'support.*.code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,29}$/'],
            'support.*.label' => ['required', 'string', 'max:80'],
            'support.*.active' => ['required', 'boolean'],
        ]);

        $before = $choices->all();
        $clean = [];
        foreach (array_keys(AdminChoiceSettings::DEFAULTS) as $section) {
            $codes = [];
            foreach ($data[$section] as $entry) {
                if (isset($codes[$entry['code']])) {
                    throw ValidationException::withMessages([$section => 'Kode kategori harus unik.']);
                }
                $codes[$entry['code']] = true;
                $clean[$section][] = [
                    'code' => $entry['code'],
                    'label' => trim($entry['label']),
                    'active' => (bool) $entry['active'],
                ];
            }
            foreach ($before[$section] as $existing) {
                if (! isset($codes[$existing['code']])) {
                    throw ValidationException::withMessages([$section => 'Kategori lama tidak boleh dihapus. Nonaktifkan pilihan yang tidak digunakan.']);
                }
            }
            // The "other" cancellation and general support codes remain valid.
            $required = $section === 'cancellations' ? 'lainnya' : 'GENERAL';
            $protected = collect($clean[$section])->firstWhere('code', $required);
            if ($protected === null || $protected['active'] !== true) {
                throw ValidationException::withMessages([$section => 'Kode '.$required.' harus tetap aktif.']);
            }
        }

        DB::transaction(function () use ($request, $clean): void {
            $current = DB::table('system_settings')->where('key', 'store.admin_choices')->lockForUpdate()->first();
            $values = [
                'value' => json_encode($clean, JSON_THROW_ON_ERROR),
                'version' => (int) ($current?->version ?? 0) + 1,
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
            ];
            if ($current) {
                DB::table('system_settings')->where('key', 'store.admin_choices')->update($values);
            } else {
                DB::table('system_settings')->insert(['key' => 'store.admin_choices', ...$values, 'created_at' => now()]);
            }
        });
        $audit->record($request, 'settings.admin_choices.updated', 'system_setting', 'store.admin_choices', $before, $clean);

        return back()->with('status', 'Alasan dan kategori tersimpan.');
    }
}
