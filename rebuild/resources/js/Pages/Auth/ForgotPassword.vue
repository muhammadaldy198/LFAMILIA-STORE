<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const form = useForm({ email: '' });
const page = usePage();
</script>

<template>
    <Head title="Lupa kata sandi" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <form class="mx-auto max-w-md space-y-5 rounded-xl bg-slate-900 p-6" @submit.prevent="form.post('/forgot-password')">
            <h1 class="text-2xl font-semibold">Atur ulang kata sandi</h1>
            <p v-if="page.props.status" role="status" class="text-cyan-300">{{ page.props.status }}</p>
            <label class="block">Email
                <input v-model="form.email" type="email" autocomplete="email" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.email" class="text-sm text-red-300">{{ form.errors.email }}</span>
            </label>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Kirim tautan reset</button>
            <Link href="/login" class="block text-center text-cyan-300">Kembali ke masuk</Link>
        </form>
    </main>
</template>
