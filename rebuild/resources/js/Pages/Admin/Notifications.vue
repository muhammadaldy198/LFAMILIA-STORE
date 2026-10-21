<script setup>
import { Button } from '../../Components/ui/button';

import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({ notifications: { type: Object, default: () => ({ data: [], links: [] }) } });
const notifications = computed(() => ({
    ...(props.notifications || {}),
    data: Array.isArray(props.notifications?.data) ? props.notifications.data : [],
    links: Array.isArray(props.notifications?.links) ? props.notifications.links : [],
}));
const date = (value) => {
    if (!value) return 'Waktu tidak tersedia';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return 'Waktu tidak tersedia';
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta',
    }).format(parsed) + ' WIB';
};
const page = usePage();
const base = page.props.adminPanel?.base_path || '/admin';
const menu = computed(() => page.props.adminPanel?.menu || []);
const canOrders = computed(() => menu.value.some(item => item.href === '/admin/orders'));
const canSupport = computed(() => menu.value.some(item => item.href === '/admin/support'));
const notificationHref = item => {
    const id = Number(item.target_id);
    if (!Number.isSafeInteger(id) || id < 1) return null;
    if (item.target_type === 'order' && canOrders.value) return `${base}/orders/${id}`;
    if (item.target_type === 'support_ticket' && canSupport.value) return `${base}/support?ticket=${id}`;
    if (item.target_type === 'fulfillment_attempt') {
        // Controller mengirim kolom `data` (JSON), bukan `metadata`.
        let payload = item.data;
        if (typeof payload === 'string') {
            try { payload = JSON.parse(payload); } catch { payload = null; }
        }
        const relatedOrderId = Number(payload?.order_id);
        if (Number.isSafeInteger(relatedOrderId) && relatedOrderId > 0 && canOrders.value) return `${base}/orders/${relatedOrderId}`;
    }
    return null;
};
const markRead = item => {
    if (item.read_at) return Promise.resolve();
    return fetch(base + '/notifications/' + item.id + '/read', {
        method: 'POST',
        keepalive: true,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: '{}',
    }).catch(() => {});
};
const openNotification = item => {
    const href = notificationHref(item);
    if (!href) { void markRead(item); return; }
    // Tunggu POST selesai; kunjungan Inertia kedua akan membatalkan yang pertama.
    void markRead(item).finally(() => router.visit(href));
};
const notificationKeydown = (event, item) => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openNotification(item);
    }
};
</script>

<template>
    <Head title="Notifikasi Admin" />
    <AdminShell>
        <div class="space-y-5">
            <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight">Notifikasi</h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">Pembaruan pesanan, pembayaran, layanan pelanggan, dan sambungan toko.</p>
                </div>
                <Button variant="outline" class="w-full sm:w-auto" :disabled="!notifications.data.some(item => !item.read_at)" @click="router.post(base + '/notifications/read-all')">Tandai semua sudah dibaca</Button>
            </header>

            <section class="overflow-hidden rounded-lg border border-border bg-card" aria-label="Daftar notifikasi"><article v-for="item in notifications.data" :key="item.id" class="lf-admin-record px-4 py-4 sm:px-5" :class="!item.read_at ? 'bg-primary/[0.04]' : ''">
                <div class="flex items-start gap-3">
                    <span class="mt-1 flex size-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-semibold" :class="item.read_at ? 'border-border text-muted-foreground' : 'border-primary bg-primary text-primary-foreground'" :aria-label="item.read_at ? 'Sudah dibaca' : 'Belum dibaca'">{{ item.read_at ? '✓' : '!' }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                            <strong v-if="notificationHref(item)" class="min-w-0 break-words text-sm"><button type="button" class="text-left underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" @click="openNotification(item)" @keydown="notificationKeydown($event, item)">{{ item.title }}</button></strong>
                            <strong v-else class="min-w-0 break-words text-sm">{{ item.title }}</strong>
                            <time class="shrink-0 text-xs text-muted-foreground">{{ date(item.created_at) }}</time>
                        </div>
                        <p class="mt-1 break-words text-sm leading-6 text-muted-foreground">{{ item.message }}</p>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex rounded border border-border px-2 py-0.5 text-xs text-muted-foreground">{{ item.severity }}</span>
                            <Button v-if="!item.read_at" variant="ghost" size="sm" @click="router.post(base + '/notifications/' + item.id + '/read')">Tandai sudah dibaca</Button>
                            <span v-else class="text-xs text-muted-foreground">Sudah dibaca</span>
                        </div>
                    </div>
                </div>
            </article><p v-if="!notifications.data.length" class="lf-admin-empty">Belum ada notifikasi. Pembaruan pesanan dan toko akan muncul di sini.</p></section>
            <nav v-if="notifications.links?.length > 3" class="flex flex-wrap gap-2" aria-label="Halaman notifikasi"><Link v-for="link in notifications.links" :key="link.label" :href="link.url || '#'" :class="['rounded-md border px-3 py-2 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-card text-foreground', !link.url ? 'pointer-events-none opacity-40' : '']">{{ link.label }}</Link></nav>
        </div>
    </AdminShell>
</template>
