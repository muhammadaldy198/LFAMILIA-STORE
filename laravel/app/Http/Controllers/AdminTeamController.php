<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminTeamController extends Controller
{
    public function index(Request $request, AdminAuthService $auth): JsonResponse
    {
        try {
            $auth->require($request, 'owner');

            $users = DB::table('admin_users')
                ->orderBy('role')
                ->orderBy('name')
                ->get(['id', 'email as username', 'name', 'role', 'is_active', 'created_at', 'updated_at'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'username' => (string) $row->username,
                    'name' => (string) $row->name,
                    'role' => (string) $row->role,
                    'isActive' => (bool) $row->is_active,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])
                ->all();

            return response()->json(['users' => $users], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function save(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $auth->require($request, 'owner');

            $input = $request->validate([
                'id' => ['nullable', 'integer', 'min:1'],
                'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9._-]+$/'],
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'role' => ['required', 'in:super_admin,admin,staff'],
                'isActive' => ['required', 'boolean'],
                'password' => ['nullable', 'string', 'max:72'],
            ]);

            $username = $auth->normalizeId($input['username']);
            $password = (string) ($input['password'] ?? '');

            if (!empty($input['id'])) {
                $id = (int) $input['id'];
                $current = DB::table('admin_users')->where('id', $id)->first([
                    'id', 'email', 'role', 'is_active',
                ]);
                if (!$current) {
                    throw new RuntimeException('Akun panel tidak ditemukan.');
                }

                if ($current->role === 'super_admin'
                    && (bool) $current->is_active
                    && ($input['role'] !== 'super_admin' || !$input['isActive'])) {
                    $this->assertAnotherOwner($id);
                }

                $duplicate = DB::table('admin_users')
                    ->whereRaw('LOWER(email) = ?', [$username])
                    ->where('id', '<>', $id)
                    ->exists();
                if ($duplicate) {
                    throw new RuntimeException('ID login sudah digunakan.');
                }

                DB::transaction(function () use ($auth, $current, $username, $input, $password, $id): void {
                    $auth->updateCredential(
                        (string) $current->email,
                        $username,
                        $input['name'],
                        $password !== '' ? $password : null,
                        (bool) $input['isActive'],
                    );

                    DB::table('admin_users')->where('id', $id)->update([
                        'email' => $username,
                        'name' => trim($input['name']),
                        'role' => $input['role'],
                        'is_active' => $input['isActive'] ? 1 : 0,
                        'updated_at' => now(),
                    ]);
                }, 3);

                return response()->json(['ok' => true, 'id' => $id]);
            }

            if (mb_strlen($password) < 10) {
                throw new RuntimeException('Password wajib diisi minimal 10 karakter untuk akun baru.');
            }
            if (DB::table('admin_users')->whereRaw('LOWER(email) = ?', [$username])->exists()) {
                throw new RuntimeException('ID login sudah digunakan.');
            }

            $id = DB::transaction(function () use ($auth, $username, $input, $password): int {
                $auth->createCredential(
                    $username,
                    $input['name'],
                    $password,
                    (bool) $input['isActive'],
                );

                return (int) DB::table('admin_users')->insertGetId([
                    'email' => $username,
                    'name' => trim($input['name']),
                    'role' => $input['role'],
                    'is_active' => $input['isActive'] ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }, 3);

            return response()->json(['ok' => true, 'id' => $id], 201);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'Sesi panel')
                || str_contains($error->getMessage(), 'Akses panel')) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Akun panel gagal disimpan.',
            ], 400);
        }
    }

    public function destroy(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $auth->require($request, 'owner');
            $id = (int) $request->query('id', 0);
            if ($id < 1) {
                throw new RuntimeException('ID login tidak valid.');
            }

            $row = DB::table('admin_users')->where('id', $id)->first(['email', 'role']);
            if (!$row) {
                throw new RuntimeException('Akun panel tidak ditemukan.');
            }
            if ($row->role === 'super_admin') {
                $this->assertAnotherOwner($id);
            }

            DB::transaction(function () use ($auth, $row, $id): void {
                $auth->deleteCredential((string) $row->email);
                DB::table('admin_users')->where('id', $id)->delete();
            }, 3);

            return response()->json(['ok' => true]);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'Sesi panel')
                || str_contains($error->getMessage(), 'Akses panel')) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    private function assertAnotherOwner(int $excludedId): void
    {
        $count = DB::table('admin_users')
            ->where('role', 'super_admin')
            ->where('is_active', 1)
            ->where('id', '<>', $excludedId)
            ->count();

        if ($count < 1) {
            throw new RuntimeException('Toko harus memiliki setidaknya satu Super Admin aktif.');
        }
    }

    private function accessError(RuntimeException $error): JsonResponse
    {
        $status = str_contains($error->getMessage(), 'Sesi panel') ? 401 : 403;

        return response()->json(['error' => $error->getMessage()], $status, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
