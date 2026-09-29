<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ email: String, token: String });
const form = useForm({ email: props.email ?? '', token: props.token ?? '', password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Kata sandi baru" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <form class="mx-auto max-w-md space-y-5 rounded-xl bg-slate-900 p-6" @submit.prevent="submit">
            <h1 class="text-2xl font-semibold">Kata sandi baru</h1>
            <label class="block">Email
                <input v-model="form.email" type="email" autocomplete="email" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.email" class="text-sm text-red-300">{{ form.errors.email }}</span>
            </label>
            <label class="block">Kata sandi baru
                <input v-model="form.password" type="password" autocomplete="new-password" minlength="12" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.password" class="text-sm text-red-300">{{ form.errors.password }}</span>
            </label>
            <label class="block">Ulangi kata sandi
                <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
            </label>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Simpan</button>
        </form>
    </main>
</template>
