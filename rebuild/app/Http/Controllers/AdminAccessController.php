<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Services\AdminAuditService;
use App\Services\AdminPermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminAccessController
{
    public function index(AdminPermissionService $permissions): Response
    {
        return Inertia::render('Admin/Access', [
            'admins' => AdminUser::orderBy('id')->get()
                ->map(fn (AdminUser $admin): array => [
                    ...$admin->only('id', 'name', 'email', 'role', 'is_active', 'last_login_at'),
                    'permissions' => $admin->permissions ?? [],
                ]),
            'permissions' => $permissions->definitions(),
        ]);
    }

    public function store(
        Request $request,
        AdminPermissionService $permissions,
        AdminAuditService $audit,
    ): RedirectResponse {
        $data = $this->validateAdmin($request, $permissions, null, true);
        if ($data['role'] === 'ADMIN') {
            $data['permissions'] = array_values(array_unique([...$data['permissions'], 'dashboard.view']));
        }

        $admin = AdminUser::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'permissions' => $data['role'] === 'ADMIN' ? $data['permissions'] : null,
            'is_active' => $data['is_active'],
        ]);
        $audit->record($request, 'admin.created', 'admin_user', $admin->id, null, $admin->toArray());

        return back();
    }

    public function update(
        Request $request,
        AdminUser $admin,
        AdminPermissionService $permissions,
        AdminAuditService $audit,
    ): RedirectResponse {
        $data = $this->validateAdmin($request, $permissions, $admin, false);
        $before = $admin->toArray();
        if ($data['role'] === 'ADMIN') {
            $data['permissions'] = array_values(array_unique([...$data['permissions'], 'dashboard.view']));
        }

        if ($admin->role === 'SUPER_ADMIN'
            && ($data['role'] !== 'SUPER_ADMIN' || ! $data['is_active'])
            && AdminUser::where('role', 'SUPER_ADMIN')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'Super Admin aktif terakhir tidak boleh dinonaktifkan atau diturunkan.',
            ]);
        }

        $admin->fill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'permissions' => $data['role'] === 'ADMIN' ? $data['permissions'] : null,
            'is_active' => $data['is_active'],
        ]);
        if (! empty($data['password'])) {
            $admin->password = Hash::make($data['password']);
        }
        $admin->save();

        $audit->record($request, 'admin.updated', 'admin_user', $admin->id, $before, $admin->toArray());

        return back();
    }

    private function validateAdmin(
        Request $request,
        AdminPermissionService $permissions,
        ?AdminUser $admin,
        bool $passwordRequired,
    ): array {
        $keys = array_keys($permissions->definitions());

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255',
                Rule::unique('admin_users', 'email')->ignore($admin?->id)],
            'role' => ['required', Rule::in(['SUPER_ADMIN', 'ADMIN'])],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($keys)],
            'is_active' => ['required', 'boolean'],
            'password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'min:12', 'max:255'],
        ]);
    }
}
