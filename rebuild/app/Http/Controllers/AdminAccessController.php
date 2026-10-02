<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Services\AdminAuditService;
use App\Services\AdminPermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminAccessController
{
    public function index(Request $request, AdminPermissionService $permissions): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['SUPER_ADMIN', 'ADMIN'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $filters = [
            'q' => mb_substr(trim((string) ($filters['q'] ?? '')), 0, 100),
            'role' => (string) ($filters['role'] ?? ''),
            'status' => (string) ($filters['status'] ?? ''),
            'per_page' => (int) ($filters['per_page'] ?? 25),
        ];

        $admins = AdminUser::query()
            ->whereIn('role', ['SUPER_ADMIN', 'ADMIN'])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($filters['role'] !== '', fn ($query) => $query->where('role', $filters['role']))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByRaw("CASE WHEN role = 'SUPER_ADMIN' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (AdminUser $admin): array => [
                'id' => (int) $admin->id,
                'name' => (string) $admin->name,
                'email' => (string) $admin->email,
                'role' => (string) $admin->role,
                'permissions' => $admin->role === 'ADMIN' ? array_values($admin->permissions ?? []) : [],
                'is_active' => (bool) $admin->is_active,
                'last_login_at' => $admin->last_login_at,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ]);

        $supportedAdmins = AdminUser::query()->whereIn('role', ['SUPER_ADMIN', 'ADMIN']);

        $summary = [
            'total' => (clone $supportedAdmins)->count(),
            'super_admins' => AdminUser::where('role', 'SUPER_ADMIN')->count(),
            'admins' => AdminUser::where('role', 'ADMIN')->count(),
            'active' => (clone $supportedAdmins)->where('is_active', true)->count(),
            'inactive' => (clone $supportedAdmins)->where('is_active', false)->count(),
            'active_super_admins' => AdminUser::where('role', 'SUPER_ADMIN')->where('is_active', true)->count(),
        ];

        $activityRows = DB::table('audit_logs')
            ->where('actor_type', 'admin_user')
            ->where(function ($query): void {
                $query->where('action', 'like', 'admin.%')
                    ->orWhere('target_type', 'admin_user');
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id',
                'actor_id',
                'actor_role',
                'action',
                'target_id',
                'created_at',
            ]);

        $actorNames = AdminUser::query()
            ->whereIn('id', $activityRows->pluck('actor_id')->filter()->map(fn ($id): int => (int) $id)->unique())
            ->pluck('name', 'id');

        $recentActivities = $activityRows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'actor_id' => $row->actor_id,
            'actor_name' => $actorNames[(int) $row->actor_id] ?? 'Admin #'.$row->actor_id,
            'actor_role' => $row->actor_role,
            'action' => (string) $row->action,
            'target_id' => $row->target_id,
            'created_at' => $row->created_at,
        ])->values();

        return Inertia::render('Admin/Access', [
            'admins' => $admins,
            'permissions' => $permissions->definitions(),
            'filters' => $filters,
            'summary' => $summary,
            'recentActivities' => $recentActivities,
            'currentAdminId' => (int) $request->user('admin')->id,
        ]);
    }

    public function store(
        Request $request,
        AdminPermissionService $permissions,
        AdminAuditService $audit,
    ): RedirectResponse {
        $data = $this->validateAdmin($request, $permissions, null, true);
        $data = $this->normalizePermissions($data);

        $admin = AdminUser::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'permissions' => $data['role'] === 'ADMIN' ? $data['permissions'] : null,
            'is_active' => $data['is_active'],
        ]);

        $audit->record($request, 'admin.created', 'admin_user', $admin->id, null, $this->auditState($admin));

        return back()->with('status', 'Akun Admin berhasil ditambahkan.');
    }

    public function update(
        Request $request,
        AdminUser $admin,
        AdminPermissionService $permissions,
        AdminAuditService $audit,
    ): RedirectResponse {
        $data = $this->normalizePermissions($this->validateAdmin($request, $permissions, $admin, false));
        $currentAdminId = (int) $request->user('admin')->id;

        if ($admin->id === $currentAdminId
            && ($data['role'] !== 'SUPER_ADMIN' || ! $data['is_active'])) {
            throw ValidationException::withMessages([
                'role' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan atau diturunkan. Gunakan Super Admin lain.',
            ]);
        }

        if ($admin->role === 'SUPER_ADMIN'
            && ($data['role'] !== 'SUPER_ADMIN' || ! $data['is_active'])
            && AdminUser::where('role', 'SUPER_ADMIN')->where('is_active', true)
                ->where('id', '!=', $admin->id)->count() < 1) {
            throw ValidationException::withMessages([
                'role' => 'Super Admin aktif terakhir tidak boleh dinonaktifkan atau diturunkan.',
            ]);
        }

        $before = $this->auditState($admin);

        $admin->fill([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'role' => $data['role'],
            'permissions' => $data['role'] === 'ADMIN' ? $data['permissions'] : null,
            'is_active' => $data['is_active'],
        ]);
        if (! empty($data['password'])) {
            $admin->password = Hash::make($data['password']);
        }
        $admin->save();

        $audit->record($request, 'admin.updated', 'admin_user', $admin->id, $before, $this->auditState($admin));

        return back()->with('status', 'Akun dan hak akses berhasil diperbarui.');
    }

    public function destroy(
        Request $request,
        AdminUser $admin,
        AdminAuditService $audit,
    ): RedirectResponse {
        $currentAdminId = (int) $request->user('admin')->id;

        if ($admin->id === $currentAdminId) {
            throw ValidationException::withMessages([
                'admin' => 'Akun yang sedang digunakan tidak dapat dihapus.',
            ]);
        }

        if ($admin->role === 'SUPER_ADMIN'
            && AdminUser::where('role', 'SUPER_ADMIN')->where('is_active', true)
                ->where('id', '!=', $admin->id)->count() < 1) {
            throw ValidationException::withMessages([
                'admin' => 'Super Admin aktif terakhir tidak boleh dihapus.',
            ]);
        }

        $before = $this->auditState($admin);
        $id = $admin->id;
        $admin->delete();

        $audit->record($request, 'admin.deleted', 'admin_user', $id, $before, null);

        return redirect('/admin/access')->with('status', 'Akun Admin berhasil dihapus.');
    }

    private function validateAdmin(
        Request $request,
        AdminPermissionService $permissions,
        ?AdminUser $admin,
        bool $passwordRequired,
    ): array {
        $keys = array_keys($permissions->definitions());

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('admin_users', 'email')->ignore($admin?->id),
            ],
            'role' => ['required', Rule::in(['SUPER_ADMIN', 'ADMIN'])],
            'permissions' => ['array', 'max:'.count($keys)],
            'permissions.*' => [Rule::in($keys), 'distinct'],
            'is_active' => ['required', 'boolean'],
            'password' => [
                $passwordRequired ? 'required' : 'nullable',
                'string',
                'min:12',
                'max:128',
            ],
        ]);
    }

    private function normalizePermissions(array $data): array
    {
        if ($data['role'] === 'ADMIN') {
            $data['permissions'] = array_values(array_unique([
                ...($data['permissions'] ?? []),
                'dashboard.view',
            ]));
        } else {
            $data['permissions'] = [];
        }

        return $data;
    }

    private function auditState(AdminUser $admin): array
    {
        return [
            'id' => (int) $admin->id,
            'name' => (string) $admin->name,
            'email' => (string) $admin->email,
            'role' => (string) $admin->role,
            'permissions' => $admin->role === 'ADMIN' ? array_values($admin->permissions ?? []) : [],
            'is_active' => (bool) $admin->is_active,
            'last_login_at' => $admin->last_login_at?->toISOString(),
        ];
    }
}
