<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '' });
const submit = () => form.post('/admin/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Masuk Admin" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <form class="mx-auto max-w-md space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-6" @submit.prevent="submit">
            <h1 class="text-2xl font-semibold">Admin LFAMILIA</h1>
            <label class="block">Email
                <input v-model="form.email" type="email" autocomplete="username" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.email" class="text-sm text-red-300">{{ form.errors.email }}</span>
            </label>
            <label class="block">Kata sandi
                <input v-model="form.password" type="password" autocomplete="current-password" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
            </label>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Masuk</button>
        </form>
    </main>
</template>
