<?php
namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AdminActivationController
{
    public function show(Request $request)
    {
        $token = (string) $request->query('token', '');
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 403);
        $entry = Cache::get('admin_activation:'.hash('sha256', $token));
        abort_unless(is_array($entry), 410, 'Tautan sudah kedaluwarsa atau digunakan.');
        return Inertia::render('Admin/Activate', ['token' => $token])
            ->toResponse($request)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $key = 'admin_activation:'.hash('sha256', $data['token']);
        return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $data) {
            $entry = Cache::get($key);
            abort_unless(is_array($entry), 410, 'Tautan sudah kedaluwarsa atau digunakan.');
            return DB::transaction(function () use ($entry, $key, $data) {
            $admin = AdminUser::where('id', $entry['id'])->lockForUpdate()->first();
            abort_unless($admin && $admin->is_active && $admin->role === 'SUPER_ADMIN'
                && hash_equals($entry['password_fingerprint'], hash('sha256', $admin->password)), 403);
            $admin->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
            Cache::forget($key);
            $requestSession = request()->session();
            $requestSession->invalidate();
            $requestSession->regenerateToken();
            return redirect()->route('admin.login');
            });
        });
    }
}
