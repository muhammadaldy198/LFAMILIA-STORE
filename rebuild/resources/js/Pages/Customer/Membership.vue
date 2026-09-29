<script setup>
import { Head } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';

defineProps({ currentCode: String, tiers: Array });
const details = (value) => value && Object.keys(value).length ? Object.entries(value).map(([key, item]) => key + ': ' + String(item)).join(' · ') : 'Belum diatur';
</script>

<template>
    <Head title="Membership" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">Membership</h1>
        <p class="text-slate-400">Tier saat ini: <strong class="text-cyan-300">{{ currentCode }}</strong></p>
        <div class="grid gap-3 sm:grid-cols-2">
            <div v-for="tier in tiers" :key="tier.code" class="rounded-xl border bg-slate-900 p-5" :class="tier.code === currentCode ? 'border-cyan-400' : 'border-slate-800'">
                <h2 class="text-lg font-semibold">{{ tier.code }}</h2>
                <p class="mt-2 text-sm text-slate-400">Syarat: {{ details(tier.requirements) }}</p>
                <p class="mt-1 text-sm text-slate-400">Benefit: {{ details(tier.benefits) }}</p>
            </div>
        </div>
    </AccountShell>
</template>
