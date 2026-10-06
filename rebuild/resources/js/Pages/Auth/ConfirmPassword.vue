<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });
const submit = () => form.post('/user/confirm-password', {
    onFinish: () => form.reset('password'),
});
</script>
<template>
<Head title="Konfirmasi Kata Sandi" />
<main class="min-h-screen flex items-center justify-center bg-slate-50 p-4">
<form class="w-full max-w-sm rounded-xl border bg-white p-6 shadow-sm" @submit.prevent="submit">
<h1 class="text-lg font-semibold">Konfirmasi Kata Sandi</h1>
<p class="mt-1 text-sm text-slate-500">Masukkan kata sandi untuk melanjutkan.</p>
<div class="mt-4 grid gap-3">
<label class="text-sm font-medium">Kata sandi
<input v-model="form.password" type="password" autocomplete="current-password" required autofocus
    class="mt-1 h-10 w-full rounded-md border px-3" />
</label>
<p v-if="form.errors.password" class="text-sm text-red-600">{{ form.errors.password }}</p>
<button type="submit" :disabled="form.processing"
    class="h-10 rounded-md bg-blue-600 font-semibold text-white disabled:opacity-50">
    Konfirmasi
</button>
</div>
</form>
</main>
</template>
