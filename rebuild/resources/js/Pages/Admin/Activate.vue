<script setup>
import { Head, useForm } from '@inertiajs/vue3';
const props = defineProps({ token: String });
const form = useForm({ token: props.token, password: '', password_confirmation: '' });
const submit = () => form.post('/admin/activate', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>
<template>
<Head title="Tentukan password Admin" />
<main class="min-h-screen bg-[#07172d] px-4 py-12">
<form class="mx-auto grid max-w-sm gap-4 rounded-lg bg-white p-6 text-slate-900" @submit.prevent="submit">
<h1 class="text-2xl font-bold">Aktivasi Super Admin</h1>
<p class="text-sm">Tentukan kata sandimu sendiri, minimal 12 karakter. Setelah disimpan, masuk menggunakan email adminmu.</p>
<label class="grid gap-2 text-sm font-semibold">Kata sandi baru<input v-model="form.password" required minlength="12" maxlength="128" autocomplete="new-password" type="password" class="h-10 rounded-md border px-3 text-base"></label>
<label class="grid gap-2 text-sm font-semibold">Ulangi kata sandi<input v-model="form.password_confirmation" required minlength="12" maxlength="128" autocomplete="new-password" type="password" class="h-10 rounded-md border px-3 text-base"></label>
<p v-if="Object.keys(form.errors).length" role="alert" class="text-sm text-red-600">{{ Object.values(form.errors)[0] }}</p>
<button :disabled="form.processing" class="min-h-10 rounded-md bg-[#1769e8] px-4 py-2 font-semibold text-white">Simpan kata sandi</button>
</form>
</main>
</template>
