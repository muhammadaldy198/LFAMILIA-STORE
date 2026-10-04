<script setup>
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
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

function exportHref() {
    const params = new URLSearchParams({ range: form.range });
    if (form.range === 'custom') {
        params.set('from', form.from);
        params.set('to', form.to);
    }
    return '/admin/reports/export?' + params.toString();
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
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Laporan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Analisis pesanan, pembayaran, produk, kategori, dan performa provider berdasarkan periode yang dipilih.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
                <Button as-child><a :href="exportHref()">Export CSV</a></Button>
            </div>
        </header>

        <Card class="p-4">
            <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="submit">
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
                    <Button type="submit">Tampilkan laporan</Button>
                </div>
                <div class="self-end text-sm text-muted-foreground">
                    {{ filters.from }} s.d. {{ filters.to }}
                </div>
            </form>
        </Card>

        <div class="lf-admin-summary ">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total pesanan</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.orders_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Berhasil</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.success_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Menunggu bayar</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.pending_payment_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Dalam proses</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.pending_fulfillment_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Gagal</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.failed_total) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Error provider</p><p class="mt-2 text-2xl font-semibold">{{ number(metrics.provider_errors) }}</p></Card>
            <Card v-if="canViewFinance" class="p-4"><p class="text-xs text-muted-foreground">Omzet dibayar</p><p class="mt-2 text-xl font-semibold">{{ money(metrics.paid_revenue_idr) }}</p></Card>
            <Card v-if="canViewFinance" class="p-4"><p class="text-xs text-muted-foreground">Laba kotor</p><p class="mt-2 text-xl font-semibold">{{ money(metrics.gross_profit_idr) }}</p></Card>
        </div>

        <div v-if="canViewFinance" class="lf-admin-summary ">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Diskon terpakai</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.discount_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Biaya pembayaran</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.payment_fee_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Top up saldo dibayar</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.paid_topup_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Saldo pelanggan saat ini</p><p class="mt-2 text-lg font-semibold">{{ money(metrics.wallet_liability_idr) }}</p><p class="mt-1 text-xs text-muted-foreground">Snapshot saat halaman dibuka</p></Card>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
            <Card class="min-w-0 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Grafik Penjualan</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ canViewFinance ? 'Omzet dibayar' : 'Jumlah pesanan' }} · {{ rangeLabel }}
                        </p>
                    </div>
                    <Badge variant="outline">{{ daily.length }} hari berisi transaksi</Badge>
                </div>

                <div v-if="daily.length" class="mt-5 overflow-x-auto pb-2">
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

            <Card class="p-4">
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
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">Fulfillment</p>
                        <div class="flex flex-wrap gap-2">
                            <Badge v-for="row in fulfillmentStatuses" :key="row.status" variant="outline">{{ statusLabel(row.status) }} · {{ number(row.total) }}</Badge>
                            <span v-if="!fulfillmentStatuses.length" class="text-sm text-muted-foreground">Tidak ada data.</span>
                        </div>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="min-w-0 p-4">
            <div>
                <h2 class="text-lg font-semibold">Produk Terlaris</h2>
                <p class="mt-1 text-sm text-muted-foreground">Urutan berdasarkan pesanan berhasil, lalu jumlah pesanan.</p>
            </div>
            <div class="mt-4 overflow-x-auto">
                <AdminResponsiveTable :mobile-columns="canViewFinance?[1,2,3,4]:[1,2,3]">
                    <TableHeader>
                        <TableRow>
                            <TableHead>#</TableHead>
                            <TableHead>Produk</TableHead>
                            <TableHead class="text-right">Pesanan</TableHead>
                            <TableHead class="text-right">Berhasil</TableHead>
                            <TableHead v-if="canViewFinance" class="text-right">Omzet</TableHead>
                            <TableHead v-if="canViewFinance" class="text-right">Laba kotor</TableHead>
                            <TableHead v-if="canViewFinance" class="text-right">Kontribusi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="(row, index) in topProducts" :key="row.id">
                            <TableCell>{{ index + 1 }}</TableCell>
                            <TableCell><strong>{{ row.name }}</strong><p class="text-xs text-muted-foreground">{{ row.slug }}</p></TableCell>
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

        <div class="lf-admin-summary ">
            <Card class="min-w-0 p-4">
                <h2 class="text-lg font-semibold">Kategori Teratas</h2>
                <div class="mt-4 overflow-x-auto">
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

            <Card class="min-w-0 p-4">
                <h2 class="text-lg font-semibold">Performa Provider</h2>
                <p class="mt-1 text-sm text-muted-foreground">Error rate dihitung dari attempt gagal/bermasalah dibanding seluruh attempt periode ini.</p>
                <div class="mt-4 overflow-x-auto">
                    <AdminResponsiveTable :mobile-columns="[0,1,2,5]">
                        <TableHeader><TableRow><TableHead>Provider</TableHead><TableHead class="text-right">Percobaan</TableHead><TableHead class="text-right">Berhasil</TableHead><TableHead class="text-right">Perlu ditangani</TableHead><TableHead class="text-right">Error</TableHead><TableHead class="text-right">Error rate</TableHead></TableRow></TableHeader>
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

        <Card class="p-4">
            <h2 class="text-lg font-semibold">Catatan Perhitungan</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Pesanan berbayar dihitung dari <strong>paid_at</strong>. Laba kotor dihitung setelah modal produk dan biaya pembayaran yang dibebankan ke customer, sebelum biaya operasional lain. Nilai finansial hanya ditampilkan kepada Super Admin.
            </p>
        </Card>
    </div>
</AdminShell>
</template>
