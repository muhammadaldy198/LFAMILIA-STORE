<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ phone: '' });
</script>

<template>
    <Head title="Lengkapi nomor telepon" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <form class="mx-auto max-w-md space-y-5 rounded-xl bg-slate-900 p-6" @submit.prevent="form.put('/account/phone')">
            <h1 class="text-2xl font-semibold">Lengkapi nomor telepon</h1>
            <p>Nomor telepon diperlukan sebelum memakai akun LFAMILIA.</p>
            <label class="block">Nomor telepon
                <input v-model="form.phone" type="tel" autocomplete="tel" required class="mt-1 w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.phone" class="text-sm text-red-300">{{ form.errors.phone }}</span>
            </label>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Simpan nomor</button>
        </form>
    </main>
</template>
