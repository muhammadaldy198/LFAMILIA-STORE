<?php

use App\Models\AdminUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

Artisan::command('lfamilia:bootstrap-super-admin', function (): int {
    if (AdminUser::where('role', 'SUPER_ADMIN')->exists()) {
        $this->error('Super Admin sudah ada. Kelola akses berikutnya dari panel.');

        return 1;
    }

    $name = $this->ask('Nama Super Admin');
    $email = strtolower(trim((string) $this->ask('Email Super Admin')));
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    $confirmation = $this->secret('Ulangi kata sandi');

    $data = Validator::make([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', Rule::unique('admin_users', 'email')],
        'password' => ['required', 'string', 'min:12', 'confirmed'],
    ])->validate();

    AdminUser::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password']),
        'role' => 'SUPER_ADMIN',
        'is_active' => true,
    ]);

    $this->info('Super Admin dibuat.');

    return 0;
})->purpose('Bootstrap the first Super Admin without storing credentials in Git');
