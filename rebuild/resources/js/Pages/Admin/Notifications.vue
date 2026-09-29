<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';

defineProps({ notifications: Object });
</script>

<template>
    <Head title="Notifikasi Admin" />
    <AdminShell>
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-3xl font-semibold">Notifikasi</h1><p class="text-sm text-slate-400">Event operasional, pembayaran, provider, support dan integrasi.</p></div>
                <button class="rounded bg-slate-700 px-4 py-2 text-sm" @click="router.post('/admin/notifications/read-all')">Tandai semua dibaca</button>
            </div>

            <div v-for="item in notifications.data" :key="item.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4" :class="!item.read_at ? 'ring-1 ring-cyan-500/30' : ''">
                <div class="flex flex-wrap justify-between gap-2"><strong>{{ item.title }}</strong><span class="text-xs text-slate-500">{{ item.severity }} · {{ item.created_at }}</span></div>
                <p class="mt-2 text-sm text-slate-300">{{ item.message }}</p>
                <button v-if="!item.read_at" class="mt-3 text-xs text-cyan-300" @click="router.post('/admin/notifications/' + item.id + '/read')">Tandai dibaca</button>
            </div>
        </div>
    </AdminShell>
</template>
