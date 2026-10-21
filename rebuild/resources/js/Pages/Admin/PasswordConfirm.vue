<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });
const submit = () => form.post('/admin/password/confirm', {
    onFinish: () => form.reset('password'),
});
</script>
<template>
<Head title="Konfirmasi Kata Sandi" />
<main class="lf-admin lf-admin-auth min-h-screen p-4 text-slate-100">
<div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-[980px] place-items-center">
<form class="w-full max-w-[390px] rounded-xl border border-slate-700 bg-slate-900 p-6 shadow-xl" @submit.prevent="submit">
<div class="mb-5 flex items-center gap-3">
<span class="lf-admin-logo">LF</span>
<div>
<h1 class="m-0 text-[18px] font-semibold text-slate-100">Konfirmasi Keamanan</h1>
<p class="m-0 text-xs text-slate-400">Masukkan kata sandi untuk melanjutkan tindakan sensitif.</p>
</div>
</div>
<div class="grid gap-3">
<label class="text-sm font-semibold text-slate-200">Kata sandi
<Input v-model="form.password" type="password" autocomplete="current-password" required autofocus class="mt-1 h-10 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-base text-slate-100 outline-none focus-visible:ring-2 focus-visible:ring-primary" />
</label>
<p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-300">{{ error }}</p>
<Button class="min-h-10 rounded-md bg-primary text-base font-semibold text-primary-foreground" :disabled="form.processing">Konfirmasi</Button>
</div>
</form>
</div>
</main>
</template>
