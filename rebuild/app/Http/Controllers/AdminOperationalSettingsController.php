<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\OperationalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminOperationalSettingsController
{
    public function index(OperationalSettings $settings): Response
    {
        return Inertia::render('Admin/OperationalSettings', [
            'fields' => OperationalSettings::FIELDS,
            'values' => $settings->all(),
        ]);
    }

    public function update(Request $request, AdminAuditService $audit, OperationalSettings $settings): RedirectResponse
    {
        $rules = [];
        foreach (OperationalSettings::FIELDS as $name => $field) {
            $rules[$name] = ['required', 'integer', 'min:'.$field['min'], 'max:'.$field['max']];
        }
        $data = $request->validate($rules);
        $before = $settings->all();

        DB::transaction(function () use ($request, $data): void {
            foreach (OperationalSettings::FIELDS as $name => $field) {
                $current = DB::table('system_settings')->where('key', $field['key'])->lockForUpdate()->first();
                $payload = [
                    'value' => json_encode((int) $data[$name], JSON_THROW_ON_ERROR),
                    'version' => (int) ($current?->version ?? 0) + 1,
                    'updated_by_admin_id' => $request->user('admin')->id,
                    'updated_at' => now(),
                ];
                if ($current) {
                    DB::table('system_settings')->where('key', $field['key'])->update($payload);
                } else {
                    DB::table('system_settings')->insert(['key' => $field['key'], ...$payload, 'created_at' => now()]);
                }
            }
        });

        $audit->record($request, 'settings.operations.updated', 'system_setting', 'operations', $before, $data);

        return back()->with('status', 'Pengaturan operasional berhasil disimpan.');
    }
}
