<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\CustomerPresentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPresentationController
{
    public function index(CustomerPresentationService $presentation): Response
    {
        return Inertia::render('Admin/Presentation', [
            'registry' => $presentation->registry(),
            'fields' => $presentation->fields(),
            'sections' => config('customer_presentation.sections'),
            'presentation' => $presentation->saved(),
        ]);
    }

    public function update(Request $request, CustomerPresentationService $presentation, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'array', 'max:1000'],
            'typography' => ['required', 'array:mobile,desktop'],
            'typography.mobile' => ['required', 'array'],
            'typography.desktop' => ['required', 'array'],
            'sections' => ['required', 'array'],
        ]);
        $registry = $presentation->registry();
        foreach ($data['text'] as $key => $value) {
            if (! isset($registry[$key]) || (! is_null($value) && (! is_string($value) || mb_strlen($value) > 5000))) {
                throw ValidationException::withMessages(['text' => 'Teks tidak valid atau terlalu panjang.']);
            }
        }
        foreach (['mobile', 'desktop'] as $viewport) {
            foreach ($data['typography'][$viewport] as $field => $value) {
                $definition = $presentation->fields()[$field] ?? null;
                if (! $definition || ! is_numeric($value) || (int) $value != $value
                    || $value < $definition['min'] || $value > $definition['max']
                    || (isset($definition['step']) && (int) $value % $definition['step'] !== 0)) {
                    throw ValidationException::withMessages(['typography' => 'Ukuran atau ketebalan font tidak valid.']);
                }
            }
        }
        foreach ($data['sections'] as $key => $value) {
            if (! array_key_exists($key, config('customer_presentation.sections')) || ! is_bool($value)) {
                throw ValidationException::withMessages(['sections' => 'Pilihan bagian halaman tidak valid.']);
            }
        }
        $before = $presentation->saved();
        $after = $presentation->sanitize($data);
        DB::table('system_settings')->updateOrInsert(['key' => 'store.customer_presentation'], [
            'value' => json_encode($after, JSON_THROW_ON_ERROR),
            'updated_by_admin_id' => $request->user('admin')->id,
            'updated_at' => now(),
        ]);
        $audit->record($request, 'content.presentation.updated', 'system_setting', 'store.customer_presentation', $before, $after);

        return back()->with('status', 'Teks dan tampilan customer berhasil disimpan.');
    }
}
