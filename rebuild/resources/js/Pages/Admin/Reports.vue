<script setup>
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

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
const section = ref('sales');
const visible = (name) => section.value === name;
const sections = [
    { id: 'sales', label: 'Penjualan' },
    { id: 'products', label: 'Produk' },
    { id: 'operations', label: 'Operasional' },
];

function csvCell(value) {
    const text = String(value ?? '');
    const safe = /^[=+@-]/.test(text) ? "'" + text : text;
    return '"' + safe.replaceAll('"', '""') + '"';
}

function exportReport() {
    let rows;
    if (section.value === 'products') {
        rows = [
            ['Jenis', 'Nama', 'Pesanan', 'Berhasil', ...(props.canViewFinance ? ['Omzet', 'Laba kotor'] : [])],
            ...props.topProducts.map(row => ['Produk', row.name, row.total_orders, row.success_orders, ...(props.canViewFinance ? [row.revenue_idr, row.profit_idr] : [])]),
            ...props.topCategories.map(row => ['Kategori', row.name, row.total_orders, row.success_orders, ...(props.canViewFinance ? [row.revenue_idr, ''] : [])]),
        ];
    } else if (section.value === 'operations') {
        rows = [
            ['Penyedia layanan', 'Kode', 'Proses', 'Berhasil', 'Perlu diperiksa', 'Masalah', 'Persentase masalah'],
            ...props.providerReport.map(row => [row.name, row.code, row.attempts_count, row.success_count, row.pending_count, row.errors_count, row.error_rate]),
        ];
    } else {
        rows = [
            props.canViewFinance ? ['Tanggal', 'Pesanan', 'Berhasil', 'Gagal', 'Omzet', 'Laba kotor', 'Diskon', 'Biaya pembayaran'] : ['Tanggal', 'Pesanan', 'Berhasil', 'Gagal'],
            ...props.daily.map(row => [row.day, row.orders_count, row.success_count, row.failed_count, ...(props.canViewFinance ? [row.revenue_idr, row.profit_idr, row.discount_idr, row.fee_idr] : [])]),
        ];
    }
    const range = form.range === 'custom' ? `${form.from}-${form.to}` : form.range;
    const csv = String.fromCharCode(0xFEFF) + rows.map(row => row.map(csvCell).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `laporan-${section.value}-${range}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const number = (value) => Number(value || 0).toLocaleString('id-ID');
const percent = (value) => Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%';

const rangeLabel = computed(() => ({
    today: 'Hari ini',
    '7d': '7 hari terakhir',
    '30d': '30 hari terakhir',
    '90d': '90 hari terakhir',
    all: 'Semua data',
    custom: 'Rentang khusus',
}[form.range] || 'Rentang laporan'));

const chartMaximum = computed(() => {
    const values = props.daily.map((row) => props.canViewFinance
        ? Number(row.revenue_idr || 0)
        : Number(row.orders_count || 0));
    return Math.max(1, ...values);
});

function submit() {
    const data = { range: form.range };
    if (form.range === 'custom') {
        data.from = form.from;
        data.to = form.to;
    }
    router.get('/admin/reports', data, { preserveState: true, preserveScroll: true });
}

function barHeight(row) {
    const value = props.canViewFinance ? Number(row.revenue_idr || 0) : Number(row.orders_count || 0);
    return Math.max(3, Math.round((value / chartMaximum.value) * 100)) + '%';
}

function statusLabel(status) {
    return ({
        PENDING_PAYMENT: 'Menunggu pembayaran',
        PAID: 'Dibayar',
        PROCESSING: 'Diproses',
        SUCCESS: 'Selesai',
        FAILED: 'Gagal',
        EXPIRED: 'Kedaluwarsa',
        CANCELLED: 'Dibatalkan',
        CREATING: 'Membuat transaksi',
        PENDING: 'Menunggu',
        UNKNOWN: 'Perlu rekonsiliasi',
        REJECTED: 'Ditolak',
        REFUNDED: 'Dikembalikan',
        CREATED: 'Dibuat',
        SENDING: 'Mengirim',
        FAILED_CONFIRMED: 'Gagal terkonfirmasi',
        BLOCKED: 'Diblokir',
        MANUAL_PENDING: 'Menunggu manual',
        MANUAL_FAILED: 'Manual gagal',
    }[status] || status);
}
</script>

<template>
<Head title="Laporan" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-col gap-4 border-b border-border pb-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">Laporan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Analisis pesanan, pembayaran, produk, kategori, dan performa provider berdasarkan periode yang dipilih.
                </p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Button class="w-full sm:w-auto" variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
                <Button class="w-full sm:w-auto" type="button" @click="exportReport">Unduh CSV</Button>
            </div>
        </header>

        <Card class="min-w-0 p-4 sm:p-5">
            <form class="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="submit">
                <label class="space-y-1">
                    <span class="text-sm font-medium">Periode</span>
                    <select v-model="form.range" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="today">Hari ini</option>
                        <option value="7d">7 hari</option>
                        <option value="30d">30 hari</option>
                        <option value="90d">90 hari</option>
                        <option value="all">Semua data</option>
                        <option value="custom">Rentang khusus</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Dari</span>
                    <Input v-model="form.from" type="date" :disabled="form.range !== 'custom'" :required="form.range === 'custom'" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Sampai</span>
                    <Input v-model="form.to" type="date" :disabled="form.range !== 'custom'" :required="form.range === 'custom'" />
                </label>
                <div class="self-end">
                    <Button class="w-full sm:w-auto" type="submit">Tampilkan laporan</Button>
                </div>
                <div class="self-end text-sm text-muted-foreground">
                    {{ filters.from }} s.d. {{ filters.to }}
                </div>
            </form>
        </Card>

        <nav class="lf-admin-tabs" aria-label="Bagian laporan">
            <Button v-for="item in sections" :key="item.id" type="button" :variant="section === item.id ? 'secondary' : 'ghost'" :aria-current="section === item.id ? 'page' : undefined" @click="section = item.id">{{ item.label }}</Button>
        </nav>

        <div v-show="visible('sales')" class="lf-admin-summary">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total pesanan</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.orders_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Berhasil</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.success_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Menunggu bayar</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.pending_payment_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Dalam proses</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.pending_fulfillment_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Gagal</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.failed_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Error provider</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.provider_errors) }}</p></Card>
            <Card v-if="canViewFinance" class="p-4"><p class="text-xs text-muted-foreground">Omzet dibayar</p><p class="mt-2 text-xl font-semibold">{{ money(metrics.paid_revenue_idr) }}</p></Card>
            <Card v-if="canViewFinance" class="p-4"><p class="text-xs text-muted-foreground">Laba kotor</p><p class="mt-2 text-xl font-semibold">{{ money(metrics.gross_profit_idr) }}</p></Card>
        </div>

        <div v-if="canViewFinance" v-show="visible('sales')" class="lf-admin-summary">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Diskon terpakai</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.discount_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Biaya pembayaran</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.payment_fee_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Top up saldo dibayar</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.paid_topup_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Saldo pelanggan saat ini</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.wallet_liability_idr) }}</p><p class="mt-1 text-xs text-muted-foreground">Snapshot saat halaman dibuka</p></Card>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
            <Card v-show="visible('sales')" class="min-w-0 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Grafik Penjualan</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ canViewFinance ? 'Omzet dibayar' : 'Jumlah pesanan' }} · {{ rangeLabel }}
                        </p>
                    </div>
                    <Badge variant="outline">{{ daily.length }} hari berisi transaksi</Badge>
                </div>

                <div v-if="daily.length" class="mt-5 overflow-x-auto pb-2" role="region" aria-label="Grafik aktivitas harian, geser mendatar untuk melihat semua hari" tabindex="0">
                    <div class="flex h-60 min-w-max items-end gap-2 border-b border-l px-3 pt-4">
                        <div v-for="row in daily" :key="row.day" class="flex h-full w-8 shrink-0 flex-col justify-end">
                            <div
                                class="w-full rounded-t bg-primary"
                                :style="{ height: barHeight(row) }"
                                :title="row.day + ' · ' + (canViewFinance ? money(row.revenue_idr) : number(row.orders_count) + ' pesanan')"
                            />
                            <span class="mt-2 -rotate-45 whitespace-nowrap text-[10px] text-muted-foreground">{{ row.day.slice(5) }}</span>
                        </div>
                    </div>
                </div>
                <p v-else class="py-12 text-center text-sm text-muted-foreground">Belum ada transaksi dalam periode ini.</p>
            </Card>

            <Card v-show="visible('operations')" class="p-4">
                <h2 class="text-lg font-semibold">Status Operasional</h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">Pesanan</p>
                        <div class="flex flex-wrap gap-2">
                            <Badge v-for="row in orderStatuses" :key="row.status" variant="outline">{{ statusLabel(row.status) }} · {{ number(row.total) }}</Badge>
                            <span v-if="!orderStatuses.length" class="text-sm text-muted-foreground">Tidak ada data.</span>
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">Pembayaran</p>
                        <div class="flex flex-wrap gap-2">
                            <Badge v-for="row in paymentStatuses" :key="row.status" variant="outline">{{ statusLabel(row.status) }} · {{ number(row.total) }}</Badge>
                            <span v-if="!paymentStatuses.length" class="text-sm text-muted-foreground">Tidak ada data.</span>
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">Penanganan pesanan</p>
                        <div class="flex flex-wrap gap-2">
                            <Badge v-for="row in fulfillmentStatuses" :key="row.status" variant="outline">{{ statusLabel(row.status) }} · {{ number(row.total) }}</Badge>
                            <span v-if="!fulfillmentStatuses.length" class="text-sm text-muted-foreground">Tidak ada data.</span>
                        </div>
                    </div>
                </div>
            </Card>
        </div>

        <Card v-show="visible('products')" class="min-w-0 p-4">
            <div>
                <h2 class="text-lg font-semibold">Produk Terlaris</h2>
                <p class="mt-1 text-sm text-muted-foreground">Urutan berdasarkan pesanan berhasil, lalu jumlah pesanan.</p>
            </div>
            <div class="mt-4 overflow-x-auto" role="region" aria-label="Laporan produk terlaris" tabindex="0">
                <AdminResponsiveTable :mobile-columns="canViewFinance?[1,2,3,4]:[1,2,3]">
                    <TableHeader>
                        <TableRow>
                            <TableHead>#</TableHead>
                            <TableHead>Produk</TableHead>
                            <TableHead class="text-right">Pesanan</TableHead>
                            <TableHead class="text-right">Berhasil</TableHead>
                            <TableHead v-if="canViewFinance" class="text-right">Omzet</TableHead>
                            <TableHead v-if="canViewFinance" class="text-right">Laba kotor</TableHead>
                            <TableHead v-if="canViewFinance" class="text-right">Bagian dari omzet (%)</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="(row, index) in topProducts" :key="row.id">
                            <TableCell>{{ index + 1 }}</TableCell>
                            <TableCell class="font-medium">{{ row.name }}</TableCell>
                            <TableCell class="text-right">{{ number(row.total_orders) }}</TableCell>
                            <TableCell class="text-right">{{ number(row.success_orders) }}</TableCell>
                            <TableCell v-if="canViewFinance" class="text-right">{{ money(row.revenue_idr) }}</TableCell>
                            <TableCell v-if="canViewFinance" class="text-right">{{ money(row.profit_idr) }}</TableCell>
                            <TableCell v-if="canViewFinance" class="text-right">{{ metrics.paid_revenue_idr ? percent((Number(row.revenue_idr || 0) / Number(metrics.paid_revenue_idr)) * 100) : '0%' }}</TableCell>
                        </TableRow>
                        <TableRow v-if="!topProducts.length"><TableCell :colspan="canViewFinance ? 7 : 4" class="py-10 text-center text-muted-foreground">Belum ada data produk.</TableCell></TableRow>
                    </TableBody>
                </AdminResponsiveTable>
            </div>
        </Card>

        <div v-show="visible('products')" class="lf-admin-summary">
            <Card class="min-w-0 p-4">
                <h2 class="text-lg font-semibold">Kategori Teratas</h2>
                <div class="mt-4 overflow-x-auto" role="region" aria-label="Laporan kategori teratas" tabindex="0">
                    <AdminResponsiveTable :mobile-columns="canViewFinance?[0,1,3]:[0,1,2]">
                        <TableHeader><TableRow><TableHead>Kategori</TableHead><TableHead class="text-right">Pesanan</TableHead><TableHead class="text-right">Berhasil</TableHead><TableHead v-if="canViewFinance" class="text-right">Omzet</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="row in topCategories" :key="row.id">
                                <TableCell class="font-medium">{{ row.name }}</TableCell>
                                <TableCell class="text-right">{{ number(row.total_orders) }}</TableCell>
                                <TableCell class="text-right">{{ number(row.success_orders) }}</TableCell>
                                <TableCell v-if="canViewFinance" class="text-right">{{ money(row.revenue_idr) }}</TableCell>
                            </TableRow>
                            <TableRow v-if="!topCategories.length"><TableCell :colspan="canViewFinance ? 4 : 3" class="py-10 text-center text-muted-foreground">Belum ada data kategori.</TableCell></TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>
            </Card>

            <Card v-show="visible('operations')" class="min-w-0 p-4">
                <h2 class="text-lg font-semibold">Kinerja penyedia layanan</h2>
                <p class="mt-1 text-sm text-muted-foreground">Persentase masalah membandingkan proses yang statusnya belum pasti, gagal, atau terblokir dengan seluruh proses pada periode ini.</p>
                <div class="mt-4 overflow-x-auto" role="region" aria-label="Kinerja penyedia layanan" tabindex="0">
                    <AdminResponsiveTable :mobile-columns="[0,1,2,5]">
                        <TableHeader><TableRow><TableHead>Penyedia layanan</TableHead><TableHead class="text-right">Proses</TableHead><TableHead class="text-right">Berhasil</TableHead><TableHead class="text-right">Perlu diperiksa</TableHead><TableHead class="text-right">Gagal, belum pasti, atau terblokir</TableHead><TableHead class="text-right">Persentase masalah</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="row in providerReport" :key="row.id + '-' + row.code">
                                <TableCell><strong>{{ row.name }}</strong><p class="text-xs text-muted-foreground">{{ row.code }}</p></TableCell>
                                <TableCell class="text-right">{{ number(row.attempts_count) }}</TableCell>
                                <TableCell class="text-right">{{ number(row.success_count) }}</TableCell>
                                <TableCell class="text-right">{{ number(row.pending_count) }}</TableCell>
                                <TableCell class="text-right">{{ number(row.errors_count) }}</TableCell>
                                <TableCell class="text-right">{{ percent(row.error_rate) }}</TableCell>
                            </TableRow>
                            <TableRow v-if="!providerReport.length"><TableCell colspan="6" class="py-10 text-center text-muted-foreground">Belum ada aktivitas provider.</TableCell></TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>
            </Card>
        </div>

        <Card v-show="visible('sales')" class="p-4">
            <h2 class="text-lg font-semibold">Catatan Perhitungan</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Omzet dihitung dari pesanan yang sudah dibayar. Laba kotor memperhitungkan modal produk dan biaya pembayaran yang dibebankan kepada pelanggan, sebelum biaya operasional lain. Nilai keuangan hanya dapat dilihat oleh Super Admin.
            </p>
        </Card>
    </div>
</AdminShell>
</template>
