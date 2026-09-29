<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const turnstile = page.props.security?.turnstile ?? {};
const form = useForm({ name: '', email: '', phone: '', password: '', password_confirmation: '', turnstile_token: '' });
const submit = () => form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Daftar" />
    <main class="min-h-screen bg-slate-950 px-4 py-12 text-slate-100">
        <form class="mx-auto max-w-md space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-6" @submit.prevent="submit">
            <h1 class="text-2xl font-semibold">Daftar LFAMILIA</h1>
            <label v-for="field in [{ key: 'name', label: 'Nama', type: 'text', auto: 'name' }, { key: 'email', label: 'Email', type: 'email', auto: 'email' }, { key: 'phone', label: 'Nomor telepon', type: 'tel', auto: 'tel' }]" :key="field.key" class="block">
                {{ field.label }}
                <input v-model="form[field.key]" :type="field.type" :autocomplete="field.auto" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors[field.key]" class="text-sm text-red-300">{{ form.errors[field.key] }}</span>
            </label>
            <label class="block">Kata sandi (minimal 12 karakter)
                <input v-model="form.password" type="password" autocomplete="new-password" minlength="12" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.password" class="text-sm text-red-300">{{ form.errors.password }}</span>
            </label>
            <label class="block">Ulangi kata sandi
                <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
            </label>
            <TurnstileWidget v-if="turnstile.enabled" :site-key="turnstile.site_key" action="register" v-model="form.turnstile_token" />
            <span v-if="form.errors.turnstile_token" class="text-sm text-red-300">{{ form.errors.turnstile_token }}</span>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Daftar</button>
            <a href="/auth/google/redirect" class="block rounded-md border border-slate-600 p-3 text-center">Daftar dengan Google</a>
            <Link href="/login" class="block text-center text-sm text-cyan-300">Sudah punya akun? Masuk</Link>
        </form>
    </main>
</template>
