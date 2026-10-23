<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { num, wibDateTime } from '../../lib/adminFormat';

const props = defineProps({
    notifications: { type: Object, default: () => ({ data: [], links: [] }) },
});

const page = usePage();
const base = page.props.adminPanel?.base_path || '/admin';
const menu = computed(() => page.props.adminPanel?.menu || []);
const canOrders = computed(() => menu.value.some(item => item.href === '/admin/orders'));
const canSupport = computed(() => menu.value.some(item => item.href === '/admin/support'));
const rows = computed(() => (Array.isArray(props.notifications?.data) ? props.notifications.data : []));
const unreadCount = computed(() => rows.value.filter(item => !item.read_at).length);

const severityLabel = value => ({
    INFO: 'Informasi', WARNING: 'Peringatan', ERROR: 'Galat', CRITICAL: 'Penting',
}[String(value || '').toUpperCase()] || 'Pemberitahuan');

const severityTone = value => ({
    INFO: 'plain', WARNING: 'warn', ERROR: 'bad', CRITICAL: 'bad',
}[String(value || '').toUpperCase()] || 'plain');

const notificationHref = item => {
    const id = Number(item.target_id);
    if (!Number.isSafeInteger(id) || id < 1) return null;
    if (item.target_type === 'order' && canOrders.value) return base + '/orders/' + id;
    if (item.target_type === 'support_ticket' && canSupport.value) return base + '/support?ticket=' + id;
    if (item.target_type === 'fulfillment_attempt') {
        // Controller mengirim kolom `data` (JSON), bukan `metadata`.
        let payload = item.data;
        if (typeof payload === 'string') {
            try { payload = JSON.parse(payload); } catch { payload = null; }
        }
        const relatedOrderId = Number(payload?.order_id);
        if (Number.isSafeInteger(relatedOrderId) && relatedOrderId > 0 && canOrders.value) return base + '/orders/' + relatedOrderId;
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
</script>

<template>
    <Head title="Notifikasi" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Notifikasi</h1>
                <p>Pembaruan pesanan, pembayaran, layanan pelanggan, dan sambungan toko.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" :disabled="!unreadCount" @click="router.post(base + '/notifications/read-all')">
                    Tandai semua dibaca<template v-if="unreadCount"> ({{ num(unreadCount) }})</template>
                </button>
            </div>
        </header>

        <section class="adm-card">
            <div class="adm-card-body" style="display:grid;gap:0">
                <div v-for="item in rows" :key="item.id" class="adm-list-row" :class="{ unread: !item.read_at }">
                    <div style="min-width:0">
                        <strong>
                            <button v-if="notificationHref(item)" type="button" class="adm-btn link" style="padding:0" @click="openNotification(item)">{{ item.title }}</button>
                            <template v-else>{{ item.title }}</template>
                        </strong>
                        <div class="adm-note" style="margin-top:2px">{{ item.message }}</div>
                        <div class="adm-note">{{ wibDateTime(item.created_at) }}</div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
                        <span class="adm-tag" :class="severityTone(item.severity)">{{ severityLabel(item.severity) }}</span>
                        <span class="adm-tag" :class="item.read_at ? 'plain' : 'info'">{{ item.read_at ? 'Sudah dibaca' : 'Belum dibaca' }}</span>
                        <button v-if="!item.read_at" type="button" class="adm-btn sm" @click="router.post(base + '/notifications/' + item.id + '/read')">Tandai dibaca</button>
                    </div>
                </div>
                <p v-if="!rows.length" class="adm-note">Belum ada notifikasi. Pembaruan pesanan dan toko akan muncul di sini.</p>
            </div>
            <div class="adm-pager">
                <span>{{ notifications.from || 0 }}–{{ notifications.to || 0 }} dari {{ num(notifications.total) }} notifikasi</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!notifications.prev_page_url" @click="router.get(notifications.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!notifications.next_page_url" @click="router.get(notifications.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>
    </AdminLayout>
</template>
