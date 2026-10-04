<script setup>
import { Button } from '../../Components/ui/button';

import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';

defineProps({ notifications: Object });
const page = usePage();
const base = page.props.adminPanel?.base_path || '/admin';
</script>

<template>
    <Head title="Notifikasi Admin" />
    <AdminShell>
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-3xl font-semibold">Notifikasi</h1><p class="text-sm text-slate-400">Event operasional, pembayaran, provider, support dan integrasi.</p></div>
                <Button class="rounded bg-slate-700 px-4 py-2 text-sm" @click="router.post(base + '/notifications/read-all')">Tandai semua dibaca</Button>
            </div>

            <div class="rounded-lg border bg-white px-4"><article v-for="item in notifications.data" :key="item.id" class="lf-admin-record" :class="!item.read_at ? 'border-l-2 border-l-primary pl-3' : ''">
                <div class="flex flex-wrap justify-between gap-2"><strong>{{ item.title }}</strong><span class="text-xs text-slate-500">{{ item.severity }} · {{ item.created_at }}</span></div>
                <p class="mt-2 break-words text-sm text-muted-foreground">{{ item.message }}</p>
                <Button v-if="!item.read_at" variant="outline" size="sm" class="mt-3" @click="router.post(base + '/notifications/' + item.id + '/read')">Tandai dibaca</Button>
            </article><p v-if="!notifications.data.length" class="lf-admin-empty">Belum ada notifikasi operasional.</p></div>
            <nav v-if="notifications.links?.length > 3" class="flex flex-wrap gap-2" aria-label="Halaman notifikasi"><Link v-for="link in notifications.links" :key="link.label" :href="link.url || '#'" :class="['rounded-md border px-3 py-2 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-white', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" /></nav>
        </div>
    </AdminShell>
</template>
