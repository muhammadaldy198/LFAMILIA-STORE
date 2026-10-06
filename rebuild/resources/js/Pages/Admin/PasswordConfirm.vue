<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const intended = computed(() => page.props.intended || '/admin/panel');
const form = useForm({ password: '', intended: intended.value });
const submit = () => form.post('/admin/password/confirm', {
    onFinish: () => form.reset('password'),
});
</script>
<template>
<Head title="Konfirmasi Kata Sandi" />
<main class="lf-admin-auth min-h-screen bg-[#07172d] p-4 text-slate-900">
<div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-[980px] place-items-center">
<form class="w-full max-w-[390px] rounded-xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="submit">
<div class="mb-5 flex items-center gap-3">
<span class="lf-admin-logo">LF</span>
<div>
<h1 class="m-0 text-[18px] font-semibold text-[#0c1c3b]">Konfirmasi Keamanan</h1>
<p class="m-0 text-xs text-slate-500">Masukkan kata sandi untuk melanjutkan tindakan sensitif.</p>
</div>
</div>
<div class="grid gap-3">
<label class="text-sm font-semibold text-slate-600">Kata sandi
<Input v-model="form.password" type="password" autocomplete="current-password" required autofocus class="mt-1 h-9 w-full rounded-md border border-slate-200 px-3 text-base outline-none focus:border-[#1769e8]" />
</label>
<p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-600">{{ error }}</p>
<Button class="h-9 rounded-md bg-[#1769e8] text-base font-semibold text-white" :disabled="form.processing">Konfirmasi</Button>
</div>
</form>
</div>
</main>
</template>
