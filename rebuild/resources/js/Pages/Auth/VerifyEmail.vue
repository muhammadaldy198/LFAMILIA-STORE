<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';

const form = useForm({});
const page = usePage();
</script>

<template>
    <Head title="Verifikasi email" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <div class="mx-auto max-w-md space-y-5 rounded-xl bg-slate-900 p-6">
            <h1 class="text-2xl font-semibold">Verifikasi email</h1>
            <p>Tautan verifikasi telah dikirim ke email Anda. Periksa kotak masuk, lalu buka tautan tersebut.</p>
            <p v-if="page.props.status" role="status" class="text-cyan-300">{{ page.props.status }}</p>
            <form @submit.prevent="form.post('/email/verification-notification')">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Kirim ulang email</button>
            </form>
        </div>
    </main>
</template>
