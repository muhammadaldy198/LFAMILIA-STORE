<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';

defineProps({ metrics: Object, notifications: Array });
</script>

<template>
    <Head title="Panel Admin" />
    <AdminShell>
        <div class="space-y-6">
            <div>
                <h1 class="text-3xl font-semibold">Dashboard</h1>
                <p class="mt-1 text-sm text-slate-400">Ringkasan operasional LFAMILIA STORE.</p>
            </div>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="(value, key) in metrics" :key="key" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500">{{ key.replaceAll('_', ' ') }}</div>
                    <div class="mt-2 text-2xl font-semibold">{{ key.includes('revenue') || key.includes('wallet') ? 'Rp' + Number(value).toLocaleString('id-ID') : value }}</div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">Notifikasi terbaru</h2>
                    <a href="/admin/notifications" class="text-sm text-cyan-300">Lihat semua</a>
                </div>
                <p v-if="!notifications.length" class="mt-3 text-sm text-slate-400">Belum ada notifikasi.</p>
                <div v-for="item in notifications" :key="item.id" class="mt-3 rounded-lg border border-slate-800 p-3">
                    <div class="flex gap-2"><strong>{{ item.title }}</strong><span class="text-xs text-slate-500">{{ item.severity }}</span></div>
                    <p class="mt-1 text-sm text-slate-300">{{ item.message }}</p>
                </div>
            </section>

            <button type="button" class="rounded-md bg-slate-700 px-4 py-2 text-sm" @click="router.post('/admin/logout')">Keluar</button>
        </div>
    </AdminShell>
</template>
