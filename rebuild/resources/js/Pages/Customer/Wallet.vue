<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
import { rupiah } from '../../lib/money';

defineProps({ balanceIdr: Number, entries: Object });
</script>

<template>
    <Head title="Saldo" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">Saldo</h1>
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-6">
            <span class="text-slate-400">Saldo tersedia</span>
            <strong class="mt-2 block text-3xl text-cyan-300">{{ rupiah(balanceIdr) }}</strong>
        </div>
        <h2 class="text-xl font-semibold">Riwayat saldo</h2>
        <p v-if="!entries.data.length" class="text-slate-400">Belum ada mutasi saldo.</p>
        <div v-else class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full min-w-[500px] text-left text-sm">
                <thead class="bg-slate-900 text-slate-400"><tr><th class="p-3">Tanggal</th><th class="p-3">Jenis</th><th class="p-3">Perubahan</th><th class="p-3">Saldo akhir</th></tr></thead>
                <tbody><tr v-for="entry in entries.data" :key="entry.id" class="border-t border-slate-800"><td class="p-3">{{ entry.created_at }}</td><td class="p-3">{{ entry.source }}</td><td class="p-3">{{ rupiah(entry.amount_idr) }}</td><td class="p-3">{{ rupiah(entry.balance_after_idr) }}</td></tr></tbody>
            </table>
        </div>
        <nav aria-label="Halaman riwayat saldo" class="flex flex-wrap gap-2"><Link v-for="link in entries.links" :key="link.label" :href="link.url || '#'" class="rounded-md px-3 py-2 text-sm" :class="link.active ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800 text-slate-200'" :aria-disabled="!link.url" v-html="link.label" /></nav>
    </AccountShell>
</template>
