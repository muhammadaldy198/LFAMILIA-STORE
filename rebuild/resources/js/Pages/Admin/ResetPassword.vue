<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';

const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, required: true },
});

const form = useForm({
    email: props.email,
    token: props.token,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    if (form.processing) return;
    form.post('/admin/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Reset Kata Sandi Admin" />
    <main class="lf-admin-auth lf-admin" style="min-height:100svh;display:grid;place-items:center;padding:16px;">
        <div style="width:100%;max-width:400px;">
            <form
                style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:24px;display:grid;gap:16px;"
                @submit.prevent="submit"
            >
                <div>
                    <div style="font-size:17px;font-weight:700;margin-bottom:4px;">Buat kata sandi baru</div>
                    <div style="font-size:12px;color:var(--muted-foreground);">Gunakan minimal 12 karakter untuk melindungi akun admin.</div>
                </div>

                <label style="display:grid;gap:6px;font-size:12px;font-weight:600;">
                    Email admin
                    <Input v-model="form.email" name="email" type="email" autocomplete="username" required />
                    <span v-if="form.errors.email" role="alert" style="color:var(--destructive);font-weight:400;font-size:11px;">{{ form.errors.email }}</span>
                </label>

                <label style="display:grid;gap:6px;font-size:12px;font-weight:600;">
                    Kata sandi baru
                    <Input v-model="form.password" name="password" type="password" autocomplete="new-password" required minlength="12" />
                    <span v-if="form.errors.password" role="alert" style="color:var(--destructive);font-weight:400;font-size:11px;">{{ form.errors.password }}</span>
                </label>

                <label style="display:grid;gap:6px;font-size:12px;font-weight:600;">
                    Ulangi kata sandi baru
                    <Input v-model="form.password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" />
                    <span v-if="form.errors.password_confirmation" role="alert" style="color:var(--destructive);font-weight:400;font-size:11px;">{{ form.errors.password_confirmation }}</span>
                </label>

                <p v-for="(error, key) in form.errors" v-show="!['email','password','password_confirmation'].includes(key)" :key="key" role="alert" style="color:var(--destructive);font-size:12px;margin:0;">{{ error }}</p>

                <Button type="submit" :disabled="form.processing" style="width:100%;font-weight:600;">
                    {{ form.processing ? 'Menyimpan…' : 'Simpan kata sandi baru' }}
                </Button>

                <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <Link href="/admin/forgot-password" style="font-size:12px;color:var(--ring);text-decoration:none;">Minta tautan baru</Link>
                    <Link href="/admin/login" style="font-size:12px;color:var(--ring);text-decoration:none;">Kembali ke login</Link>
                </div>
            </form>
        </div>
    </main>
</template>
