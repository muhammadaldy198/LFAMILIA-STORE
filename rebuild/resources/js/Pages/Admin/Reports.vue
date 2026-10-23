<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { num, rupiah, tone, label } from '../../lib/adminFormat';

const props = defineProps({
    canViewFinance: Boolean,
    filters: { type: Object, default: () => ({}) },
    metrics: { type: Object, default: () => ({}) },
    daily: { type: Array, default: () => [] },
    topProducts: { type: Array, default: () => [] },
    topCategories: { type: Array, default: () => [] },
    providerReport: { type: Array, default: () => [] },
    orderStatuses: { type: Array, default: () => [] },
    paymentStatuses: { type: Array, default: () => [] },
    fulfillmentStatuses: { type: Array, default: () => [] },
});

const form = reactive({
    range: props.filters?.range || '30d',
    from: props.filters?.from || '',
    to: props.filters?.to || '',
});
const tab = ref('sales');
const tabs = [['sales', 'Penjualan'], ['products', 'Produk'], ['operations', 'Operasional']];

const submit = () => {
    const data = { range: form.range };
    if (form.range === 'custom') { data.from = form.from; data.to = form.to; }
    router.get('/admin/reports', data, { preserveState: true, preserveScroll: true });
};

const rangeLabel = computed(() => ({
    today: 'Hari ini', '7d': '7 hari terakhir', '30d': '30 hari terakhir',
    '90d': '90 hari terakhir', all: 'Semua data', custom: 'Rentang khusus',
}[form.range] || 'Rentang laporan'));

const periodCards = computed(() => {
    const m = props.metrics || {};
    const cards = [
        ['Pesanan', num(m.orders_total), 'seluruh pesanan pada periode'],
        ['Berhasil', num(m.success_total), 'pesanan selesai'],
        ['Menunggu pembayaran', num(m.pending_payment_total), 'belum dibayar'],
        ['Menunggu diproses', num(m.pending_fulfillment_total), 'sudah dibayar'],
        ['Gagal', num(m.failed_total), 'pengiriman gagal'],
        ['Kedaluwarsa', num(m.expired_total), 'tidak dibayar'],
        ['Dibatalkan', num(m.cancelled_total), 'dibatalkan pelanggan atau admin'],
        ['Masalah penyedia', num(m.provider_errors), 'percobaan kirim bermasalah'],
    ];
    if (props.canViewFinance) {
        cards.push(
            ['Omzet', rupiah(m.paid_revenue_idr), 'pesanan yang sudah dibayar'],
            ['Laba kotor', rupiah(m.gross_profit_idr), 'omzet dikurangi modal dan biaya'],
            ['Diskon diberikan', rupiah(m.discount_idr), 'voucher dan tingkat member'],
        );
    }
    return cards;
});

const chartMax = computed(() => Math.max(1, ...props.daily.map(row => Number(row.orders_count || 0))));
const barHeight = row => Math.max(3, Math.round((Number(row.orders_count || 0) / chartMax.value) * 100)) + '%';

// ---------- unduh CSV mengikuti tab aktif ----------
const csvCell = value => {
    const text = String(value ?? '');
    const safe = /^\s*[=+@-]/.test(text) ? "'" + text : text;
    return '"' + safe.replaceAll('"', '""') + '"';
};

const exportReport = () => {
    let rows;
    if (tab.value === 'products') {
        rows = [
            ['Jenis', 'Nama', 'Pesanan', 'Berhasil', ...(props.canViewFinance ? ['Omzet', 'Laba kotor'] : [])],
            ...props.topProducts.map(row => ['Produk', row.name, row.total_orders, row.success_orders, ...(props.canViewFinance ? [row.revenue_idr, row.profit_idr] : [])]),
            ...props.topCategories.map(row => ['Kategori', row.name, row.total_orders, row.success_orders, ...(props.canViewFinance ? [row.revenue_idr, ''] : [])]),
        ];
    } else if (tab.value === 'operations') {
        rows = [
            ['Penyedia layanan', 'Kode', 'Proses', 'Berhasil', 'Perlu diperiksa', 'Masalah', 'Persentase masalah'],
            ...props.providerReport.map(row => [row.name, row.code, row.attempts_count, row.success_count, row.pending_count, row.errors_count, row.error_rate]),
        ];
    } else {
        rows = [
            props.canViewFinance
                ? ['Tanggal', 'Pesanan', 'Berhasil', 'Gagal', 'Omzet', 'Laba kotor', 'Diskon', 'Biaya pembayaran']
                : ['Tanggal', 'Pesanan', 'Berhasil', 'Gagal'],
            ...props.daily.map(row => [row.day, row.orders_count, row.success_count, row.failed_count,
                ...(props.canViewFinance ? [row.revenue_idr, row.profit_idr, row.discount_idr, row.fee_idr] : [])]),
        ];
    }
    const applied = props.filters || {};
    const range = applied.range === 'custom' ? `${applied.from}_${applied.to}` : (applied.range || '30d');
    const csv = String.fromCharCode(0xFEFF) + rows.map(row => row.map(csvCell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `laporan-${tab.value}-${range}.csv`;
    link.click();
    URL.revokeObjectURL(url);
    void fetch('/admin/reports/export-audit', {
        method: 'POST',
        keepalive: true,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ section: tab.value, range: applied.range || '30d', from: applied.from, to: applied.to }),
    });
};

const maxProviderError = computed(() => Math.max(1, ...props.providerReport.map(row => Number(row.errors_count || 0))));
</script>

<template>
    <Head title="Laporan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Laporan</h1>
                <p>Analisis penjualan, produk, dan operasional toko pada periode yang dipilih. Nilai uang hanya terlihat oleh Super Admin.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
                <button type="button" class="adm-btn primary" @click="exportReport">Unduh laporan</button>
            </div>
        </header>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="submit">
                <label class="adm-field narrow">
                    <span>Periode</span>
                    <select v-model="form.range" class="adm-select">
                        <option value="today">Hari ini</option>
                        <option value="7d">7 hari</option>
                        <option value="30d">30 hari</option>
                        <option value="90d">90 hari</option>
                        <option value="all">Semua data</option>
                        <option value="custom">Rentang khusus</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Dari</span>
                    <input v-model="form.from" type="date" class="adm-input" :disabled="form.range !== 'custom'" :required="form.range === 'custom'">
                </label>
                <label class="adm-field narrow">
                    <span>Sampai</span>
                    <input v-model="form.to" type="date" class="adm-input" :disabled="form.range !== 'custom'" :required="form.range === 'custom'">
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary">Tampilkan</button>
                </div>
            </form>
            <p class="adm-toolbar">
                Data {{ filters.from }} sampai {{ filters.to }} (WIB) · {{ rangeLabel }} ·
                Unduhan mengikuti tab aktif dengan nama laporan-&lt;tab&gt;-&lt;periode&gt;.csv
            </p>
        </section>

        <section class="adm-stats" aria-label="Angka periode">
            <div v-for="[title, value, note] in periodCards" :key="title" class="adm-stat">
                <span class="lb">{{ title }}</span>
                <strong class="vl">{{ value }}</strong>
                <span class="nt">{{ note }}</span>
            </div>
        </section>

        <section v-if="canViewFinance" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Saldo pelanggan saat ini</h2>
                </div>
            </div>
            <div class="adm-card-body" style="display:flex;gap:16px;flex-wrap:wrap;align-items:baseline">
                <strong style="font-size:22px">{{ rupiah(metrics.wallet_liability_idr) }}</strong>
                <span class="adm-note">Top up masuk periode ini: {{ rupiah(metrics.paid_topup_idr) }}</span>
            </div>
        </section>

        <div class="adm-tabs" role="tablist" aria-label="Bagian laporan">
            <button v-for="[key, text] in tabs" :key="key" type="button" role="tab" :aria-selected="tab === key" @click="tab = key">{{ text }}</button>
        </div>

        <!-- Penjualan -->
        <template v-if="tab === 'sales'">
            <section class="adm-card">
                <div class="adm-card-head">
                    <div><h2>Pesanan per hari</h2></div>
                </div>
                <div class="adm-card-body">
                    <div class="adm-chart">
                        <div v-for="row in daily" :key="row.day" class="adm-chart-bar" :style="{ height: barHeight(row) }" :title="row.day + ': ' + row.orders_count + ' pesanan'">
                            <span>{{ row.orders_count }}</span>
                        </div>
                    </div>
                    <p v-if="!daily.length" class="adm-note">Belum ada pesanan pada periode ini.</p>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head"><div><h2>Rincian harian</h2></div></div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th>Tanggal</th><th style="text-align:right">Pesanan</th><th style="text-align:right">Berhasil</th>
                                <th style="text-align:right">Gagal</th>
                                <template v-if="canViewFinance"><th style="text-align:right">Omzet</th><th style="text-align:right">Laba kotor</th><th style="text-align:right">Diskon</th><th style="text-align:right">Biaya pembayaran</th></template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in daily" :key="row.day">
                                <td>{{ row.day }}</td>
                                <td style="text-align:right">{{ num(row.orders_count) }}</td>
                                <td style="text-align:right">{{ num(row.success_count) }}</td>
                                <td style="text-align:right">{{ num(row.failed_count) }}</td>
                                <template v-if="canViewFinance">
                                    <td style="text-align:right">{{ rupiah(row.revenue_idr) }}</td>
                                    <td style="text-align:right">{{ rupiah(row.profit_idr) }}</td>
                                    <td style="text-align:right">{{ rupiah(row.discount_idr) }}</td>
                                    <td style="text-align:right">{{ rupiah(row.fee_idr) }}</td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!daily.length" class="adm-empty">Belum ada data harian.</p>
            </section>
        </template>

        <!-- Produk -->
        <template v-else-if="tab === 'products'">
            <section class="adm-card">
                <div class="adm-card-head"><div><h2>Produk terlaris</h2></div></div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr><th>Produk</th><th style="text-align:right">Pesanan</th><th style="text-align:right">Berhasil</th><template v-if="canViewFinance"><th style="text-align:right">Omzet</th><th style="text-align:right">Laba kotor</th></template></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in topProducts" :key="row.name">
                                <td>{{ row.name }}</td>
                                <td style="text-align:right">{{ num(row.total_orders) }}</td>
                                <td style="text-align:right">{{ num(row.success_orders) }}</td>
                                <template v-if="canViewFinance">
                                    <td style="text-align:right">{{ rupiah(row.revenue_idr) }}</td>
                                    <td style="text-align:right">{{ rupiah(row.profit_idr) }}</td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!topProducts.length" class="adm-empty">Belum ada produk terjual pada periode ini.</p>
            </section>

            <section class="adm-card">
                <div class="adm-card-head"><div><h2>Kategori terlaris</h2></div></div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr><th>Kategori</th><th style="text-align:right">Pesanan</th><th style="text-align:right">Berhasil</th><th v-if="canViewFinance" style="text-align:right">Omzet</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in topCategories" :key="row.name">
                                <td>{{ row.name }}</td>
                                <td style="text-align:right">{{ num(row.total_orders) }}</td>
                                <td style="text-align:right">{{ num(row.success_orders) }}</td>
                                <td v-if="canViewFinance" style="text-align:right">{{ rupiah(row.revenue_idr) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!topCategories.length" class="adm-empty">Belum ada kategori terjual pada periode ini.</p>
            </section>
        </template>

        <!-- Operasional -->
        <template v-else>
            <section class="adm-card">
                <div class="adm-card-head"><div><h2>Kinerja penyedia</h2></div></div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr><th>Penyedia</th><th>Kode</th><th style="text-align:right">Proses</th><th style="text-align:right">Berhasil</th><th style="text-align:right">Perlu diperiksa</th><th style="text-align:right">Masalah</th><th>Persentase masalah</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in providerReport" :key="row.code">
                                <td>{{ row.name }}</td>
                                <td class="mono">{{ row.code }}</td>
                                <td style="text-align:right">{{ num(row.attempts_count) }}</td>
                                <td style="text-align:right">{{ num(row.success_count) }}</td>
                                <td style="text-align:right">{{ num(row.pending_count) }}</td>
                                <td style="text-align:right" :style="{ color: Number(row.errors_count) ? 'var(--a-danger)' : 'inherit' }">{{ num(row.errors_count) }}</td>
                                <td>
                                    <div class="adm-meter"><span :style="{ width: Math.min(100, (Number(row.errors_count || 0) / maxProviderError) * 100) + '%' }" /></div>
                                    <span class="adm-note">{{ row.error_rate }}%</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!providerReport.length" class="adm-empty">Belum ada percobaan pengiriman pada periode ini.</p>
            </section>

            <section class="adm-card">
                <div class="adm-card-head"><div><h2>Status pesanan</h2></div></div>
                <div class="adm-card-body" style="display:grid;gap:6px">
                    <div v-for="row in orderStatuses" :key="row.status" class="adm-list-row">
                        <span class="adm-tag" :class="tone(row.status)">{{ label(row.status) }}</span>
                        <strong>{{ num(row.total) }}</strong>
                    </div>
                    <p v-if="!orderStatuses.length" class="adm-note">Belum ada pesanan.</p>
                </div>
            </section>

            <section class="adm-split">
                <div class="adm-card">
                    <div class="adm-card-head"><div><h2>Status pembayaran</h2></div></div>
                    <div class="adm-card-body" style="display:grid;gap:6px">
                        <div v-for="row in paymentStatuses" :key="row.status" class="adm-list-row">
                            <span class="adm-tag" :class="tone(row.status)">{{ label(row.status) }}</span>
                            <strong>{{ num(row.total) }}</strong>
                        </div>
                        <p v-if="!paymentStatuses.length" class="adm-note">Belum ada pembayaran.</p>
                    </div>
                </div>
                <div class="adm-card">
                    <div class="adm-card-head"><div><h2>Status pengiriman</h2></div></div>
                    <div class="adm-card-body" style="display:grid;gap:6px">
                        <div v-for="row in fulfillmentStatuses" :key="row.status" class="adm-list-row">
                            <span class="adm-tag" :class="tone(row.status)">{{ label(row.status) }}</span>
                            <strong>{{ num(row.total) }}</strong>
                        </div>
                        <p v-if="!fulfillmentStatuses.length" class="adm-note">Belum ada percobaan pengiriman.</p>
                    </div>
                </div>
            </section>
        </template>
    </AdminLayout>
</template>
