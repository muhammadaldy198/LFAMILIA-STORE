<script setup>
import { Head, router } from '@inertiajs/vue3';

defineProps({ customer: Object });
</script>

<template>
    <Head title="Akun" />
    <main class="min-h-screen bg-slate-950 px-6 py-16 text-slate-100">
        <div class="mx-auto max-w-2xl space-y-5">
            <h1 class="text-3xl font-semibold">Akun LFAMILIA</h1>
            <p>{{ customer.name }} · {{ customer.email }}</p>
            <p>Nomor telepon: {{ customer.phone }}</p>
            <p>Membership: {{ customer.membership_tier_code }}</p>
            <button type="button" class="rounded-md bg-slate-700 p-3 focus:outline-cyan-300" @click="router.post('/logout')">Keluar</button>
        </div>
    </main>
</template>
