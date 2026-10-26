<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { label, num, rupiah, tone, wibDateTime, wibTime, shortDate } from '../../lib/adminFormat';

const props = defineProps({
    range: { type: String, default: '7d' },
    generatedAt: String,
    canViewFinance: Boolean,
    canOrders: Boolean,
    canNotifications: Boolean,
    metrics: { type: Object, default: () => ({}) },
    manualOverdueMinutes: { type: Number, default: 60 },
    chart: { type: Array, default: () => [] },
    recentOrders: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
    integrations: { type: Array, default: () => [] },
    digiflazzBalance: { type: Object, default: () => ({}) },
    lastSyncedAt: String,
    notifications: { type: Array, default: () => [] },
});

const m = computed(() => props.metrics || {});
const chart = computed(() => (Array.isArray(props.chart) ? props.chart : []));
const peak = computed(() => Math.max(1, ...chart.value.map(row => Number(row.orders || 0))));
const busiest = computed(() => chart.value.reduce((best, row) => (Number(row.orders) > Number(best?.orders ?? -1) ? row : best), null));
const totalRevenue = computed(() => chart.value.reduce((sum, row) => sum + Number(row.revenue_idr || 0), 0));
const totalOrders = computed(() => chart.value.reduce((sum, row) => sum + Number(row.orders || 0), 0));

const boxes = computed(() => [
    { key: 'revenue', label: 'Omzet Pesanan Hari Ini', value: m.value.revenue_today == null ? null : rupiah(m.value.revenue_today), note: 'Pesanan dibuat hari ini yang sudah dibayar' },
    { key: 'orders', label: 'Pesanan Hari Ini', value: m.value.orders_today == null ? null : num(m.value.orders_today), note: 'Semua status' },
    { key: 'products', label: 'Produk Aktif', value: m.value.active_products == null ? null : num(m.value.active_products), note: 'Status aktif; belum tentu tersedia di toko' },
    { key: 'digiflazz', label: 'Saldo Digiflazz', value: props.digiflazzBalance?.amount_idr == null ? null : rupiah(props.digiflazzBalance.amount_idr), note: props.digiflazzBalance?.status === 'DOWN' ? 'Tidak dapat diperiksa' : 'Diperiksa dari penyedia' },
    { key: 'paid', label: 'Pesanan Selesai', value: m.value.success_period == null ? null : num(m.value.success_period), note: 'Pesanan dibuat pada periode ini yang kini berstatus selesai' },
    { key: 'wallet', label: 'Saldo Wallet', value: m.value.wallet_balance == null ? null : rupiah(m.value.wallet_balance), note: 'Total saldo pelanggan' },
    { key: 'tickets', label: 'Tiket Terbuka', value: m.value.open_tickets == null ? null : num(m.value.open_tickets), note: 'Belum selesai' },
    { key: 'fulfillment', label: 'Pending Fulfillment', value: m.value.pending_fulfillment == null ? null : num(m.value.pending_fulfillment), note: 'Belum terkirim' },
]);

const ranges = [
    { value: 'today', label: 'Hari ini' },
    { value: '7d', label: '7 hari' },
    { value: '30d', label: '30 hari' },
    { value: '90d', label: '90 hari' },
];

const loading = ref(false);
const clock = ref(wibDateTime(new Date().toISOString()));
let ticker;
onMounted(() => { ticker = setInterval(() => { clock.value = wibDateTime(new Date().toISOString()); }, 30000); });
onUnmounted(() => clearInterval(ticker));

const setRange = event => router.get('/admin/panel', { range: event.target.value }, { preserveState: true, preserveScroll: true, replace: true });
const reload = () => router.reload({ only: ['metrics', 'chart', 'recentOrders', 'activities', 'integrations', 'notifications', 'digiflazzBalance', 'generatedAt'] });

const activityFilter = ref('');
const activityKinds = computed(() => [...new Set(props.activities.map(row => row.action))]);
const activities = computed(() => (activityFilter.value ? props.activities.filter(row => row.action === activityFilter.value) : props.activities));

const unread = computed(() => props.notifications.filter(row => !row.read_at));

const markRead = async (row) => {
    if (row.read_at) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    await fetch(`/admin/notifications/${row.id}/read`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: '{}',
    });
    row.read_at = new Date().toISOString();
};
</script>

<template>
    <Head title="Dashboard" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Dashboard</h1>
                <p>Ringkasan operasional toko.</p>
            </div>
            <div class="adm-head-actions">
                <div class="adm-clock">
                    <strong>{{ clock }}</strong>
                    <span>Diperbarui {{ wibTime(generatedAt) }}</span>
                </div>
                <button type="button" class="adm-btn" :disabled="loading" @click="loading = true; reload()">
                    {{ loading ? 'Memuat…' : 'Muat ulang' }}
                </button>
            </div>
        </header>

        <section v-if="Number(m.manual_overdue || 0) > 0" class="adm-card" style="border-left:4px solid #b3261e">
            <div class="adm-card-body">
                <strong>{{ num(m.manual_overdue) }} pesanan manual lewat {{ manualOverdueMinutes }} menit jam layanan.</strong>
                <p class="adm-note">Lewat batas waktu pelayanan. <a href="/admin/fulfillment">Buka antrean pemenuhan</a>.</p>
            </div>
        </section>

        <section class="adm-stats" aria-label="Angka utama">
            <div v-for="box in boxes" :key="box.key" class="adm-stat">
                <span class="lb">{{ box.label }}</span>
                <strong class="vl">{{ box.value ?? '—' }}</strong>
                <span class="nt">{{ box.note }}</span>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Pesanan dan omzet</h2>
                    <p>{{ num(totalOrders) }} pesanan · {{ canViewFinance ? rupiah(totalRevenue) + ' dari pesanan yang sudah dibayar' : 'omzet hanya untuk Super Admin' }}</p>
                </div>
                <select class="adm-select" style="width:auto" :value="range" aria-label="Periode grafik" @change="setRange">
                    <option v-for="item in ranges" :key="item.value" :value="item.value">{{ item.label }}</option>
                </select>
            </div>
            <div class="adm-card-body">
                <p v-if="chart.length" class="adm-note" style="margin-bottom:6px">Tinggi batang = jumlah pesanan. Puncak {{ num(peak) }} pesanan.</p>
                <div v-if="chart.length" class="adm-chart" role="img" :aria-label="`Grafik jumlah pesanan ${chart.length} hari terakhir`">
                    <div v-for="row in chart" :key="row.day" class="adm-chart-bar" :title="`${shortDate(row.day)} · ${num(row.orders)} pesanan · ${rupiah(row.revenue_idr)}`">
                        <i :style="{ height: Math.max(2, Math.round((Number(row.orders || 0) / peak) * 130)) + 'px' }" />
                    </div>
                </div>
                <p v-else class="adm-empty">Belum ada data untuk periode ini.</p>
                <div v-if="chart.length" class="adm-chart-axis" aria-hidden="true">
                    <span v-for="row in chart" :key="row.day">{{ shortDate(row.day) }}</span>
                </div>
                <p v-if="busiest && Number(busiest.orders) > 0" class="adm-note" style="margin-top:8px">
                    Puncak pesanan: {{ shortDate(busiest.day) }} dengan {{ num(busiest.orders) }} pesanan.
                </p>
            </div>
        </section>

        <div class="adm-split">
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2>Pesanan terbaru</h2>
                    <Link v-if="canOrders" href="/admin/orders" class="adm-btn sm">Buka semua pesanan</Link>
                </div>
                <div class="adm-card-body tight">
                    <div v-if="recentOrders.length" class="adm-scroll">
                        <table class="adm-table">
                            <thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Status</th><th class="num">Total</th></tr></thead>
                            <tbody>
                                <tr v-for="row in recentOrders" :key="row.id">
                                    <td>
                                        <Link :href="`/admin/orders/${row.id}`" class="mono">{{ row.order_number }}</Link>
                                        <p class="dim">{{ wibTime(row.created_at) }}</p>
                                    </td>
                                    <td>
                                        {{ row.buyer_name }}
                                        <p class="dim">{{ row.product_name }} · {{ row.package_name }}</p>
                                    </td>
                                    <td><span class="adm-tag" :class="tone(row.status)">{{ label(row.status) }}</span></td>
                                    <td class="num">{{ row.total_idr == null ? '—' : rupiah(row.total_idr) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="adm-empty">Belum ada pesanan pada periode ini.</p>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <h2>Aktivitas terbaru</h2>
                    <select v-if="activityKinds.length" v-model="activityFilter" class="adm-select" style="width:auto" aria-label="Saring aktivitas">
                        <option value="">Semua</option>
                        <option v-for="kind in activityKinds" :key="kind" :value="kind">{{ kind }}</option>
                    </select>
                </div>
                <div class="adm-card-body">
                    <div v-if="activities.length" class="adm-list">
                        <div v-for="row in activities" :key="row.id" class="adm-list-row">
                            <strong>{{ row.action }}</strong>
                            <span>{{ row.target }} · {{ wibDateTime(row.created_at) }}</span>
                        </div>
                    </div>
                    <p v-else class="adm-empty">Belum ada aktivitas tercatat.</p>
                </div>
            </section>
        </div>

        <div class="adm-split">
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2>Status integrasi</h2>
                    <Link href="/admin/integrations" class="adm-btn sm">Kelola</Link>
                </div>
                <div class="adm-card-body tight">
                    <div v-if="integrations.length" class="adm-scroll">
                        <table class="adm-table">
                            <thead><tr><th>Integrasi</th><th>Status</th><th>Terakhir diuji</th></tr></thead>
                            <tbody>
                                <tr v-for="row in integrations" :key="row.code">
                                    <td>{{ row.name }}<p class="dim">{{ row.code }}</p></td>
                                    <td><span class="adm-tag" :class="tone(row.status)">{{ row.status === 'UNTESTED' ? 'Belum diuji' : row.status === 'NOT_CONFIGURED' ? 'Belum diatur' : row.status === 'CONFIGURED' ? 'Siap' : row.status === 'HEALTHY' ? 'Normal' : row.status === 'DEGRADED' ? 'Terbatas' : row.status === 'DOWN' ? 'Gangguan' : row.status }}</span></td>
                                    <td>{{ row.tested_at ? wibDateTime(row.tested_at) : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="adm-empty">Belum ada integrasi yang dapat ditampilkan.</p>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <h2>Notifikasi</h2>
                    <Link v-if="canNotifications" href="/admin/notifications" class="adm-btn sm">Lihat semua</Link>
                </div>
                <div class="adm-card-body">
                    <div v-if="notifications.length" class="adm-list">
                        <div v-for="row in notifications.slice(0, 5)" :key="row.id" class="adm-list-row">
                            <strong>{{ row.title }}</strong>
                            <span>{{ row.message }}</span>
                            <span>{{ wibDateTime(row.created_at) }}<template v-if="row.read_at"> · sudah dibaca</template></span>
                            <button v-if="!row.read_at" type="button" class="adm-btn link" style="margin-top:3px" @click="markRead(row)">Tandai dibaca</button>
                        </div>
                    </div>
                    <p v-else class="adm-empty">Tidak ada notifikasi.</p>
                    <p v-if="unread.length" class="adm-note" style="margin-top:8px">{{ unread.length }} notifikasi belum dibaca.</p>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
