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
    <main class="lf-admin-auth min-h-screen bg-[#07172d] p-4 text-slate-900">
        <div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-[980px] place-items-center">
            <form class="w-full max-w-[420px] rounded-xl border border-slate-200 bg-white p-6 shadow-lg" @submit.prevent="submit">
                <h1 class="mb-2 text-xl font-bold text-[#0c1c3b]">Buat kata sandi admin baru</h1>
                <p class="mb-5 text-sm text-slate-600">Gunakan minimal 12 karakter untuk melindungi akun admin.</p>

                <div class="grid gap-4">
                    <label class="text-sm font-semibold text-slate-700">
                        Email admin
                        <Input v-model="form.email" name="email" type="email" autocomplete="username" required
                            class="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-base" />
                    </label>
                    <label class="text-sm font-semibold text-slate-700">
                        Kata sandi baru
                        <Input v-model="form.password" name="password" type="password" autocomplete="new-password" required minlength="12"
                            class="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-base" />
                    </label>
                    <label class="text-sm font-semibold text-slate-700">
                        Ulangi kata sandi baru
                        <Input v-model="form.password_confirmation" name="password_confirmation" type="password"
                            autocomplete="new-password" required minlength="12"
                            class="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-base" />
                    </label>

                    <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-600">
                        {{ error }}
                    </p>

                    <Button type="submit" class="h-10 w-full rounded-md bg-[#1769e8] text-base font-semibold text-white"
                        :disabled="form.processing">
                        {{ form.processing ? 'Menyimpan…' : 'Simpan kata sandi baru' }}
                    </Button>
                </div>
                <div class="mt-4 flex flex-wrap justify-between gap-3 text-sm">
                    <Link href="/admin/forgot-password" class="font-medium text-[#1769e8] hover:underline">
                        Minta tautan baru
                    </Link>
                    <Link href="/admin/login" class="font-medium text-[#1769e8] hover:underline">
                        Kembali ke login
                    </Link>
                </div>
            </form>
        </div>
    </main>
</template>
