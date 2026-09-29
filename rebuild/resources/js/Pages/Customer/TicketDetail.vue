<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';

defineProps({ ticket: Object });
</script>

<template>
    <Head title="Detail tiket" />
    <AccountShell>
        <Link href="/account/tickets" class="text-sm text-cyan-300">← Semua tiket</Link>
        <h1 class="text-3xl font-semibold">Tiket #{{ ticket.id }}</h1>
        <div class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="text-lg font-semibold">{{ ticket.subject }}</h2>
            <p class="text-sm text-slate-400">{{ ticket.status }} · {{ ticket.created_at }}</p>
            <p class="whitespace-pre-wrap">{{ ticket.message }}</p>
        </div>
    </AccountShell>
</template>
