<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const turnstile = page.props.security?.turnstile ?? {};
const form = useForm({ email: '', password: '', remember: false, turnstile_token: '' });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Masuk" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <form class="mx-auto max-w-md space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-6" @submit.prevent="submit">
            <h1 class="text-2xl font-semibold">Masuk LFAMILIA</h1>
            <label class="block">Email
                <input v-model="form.email" type="email" autocomplete="email" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.email" class="text-sm text-red-300">{{ form.errors.email }}</span>
            </label>
            <label class="block">Kata sandi
                <input v-model="form.password" type="password" autocomplete="current-password" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.password" class="text-sm text-red-300">{{ form.errors.password }}</span>
            </label>
            <label class="flex items-center gap-2"><input v-model="form.remember" type="checkbox"> Ingat saya</label>
            <TurnstileWidget v-if="turnstile.enabled" :site-key="turnstile.site_key" v-model="form.turnstile_token" />
            <span v-if="form.errors.turnstile_token" class="text-sm text-red-300">{{ form.errors.turnstile_token }}</span>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Masuk</button>
            <a href="/auth/google/redirect" class="block rounded-md border border-slate-600 p-3 text-center">Masuk dengan Google</a>
            <div class="flex justify-between text-sm text-cyan-300">
                <Link href="/register">Daftar</Link>
                <Link href="/forgot-password">Lupa kata sandi?</Link>
            </div>
        </form>
    </main>
</template>
