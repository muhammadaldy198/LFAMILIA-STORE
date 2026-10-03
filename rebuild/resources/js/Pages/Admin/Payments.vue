<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    isSuperAdmin: Boolean,
    gateways: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    routes: { type: Array, default: () => [] },
    transactions: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    walletSettings: { type: Object, default: () => ({}) },
    manualQrisAsset: Object,
    refundReviews: { type: Array, default: () => [] },
    manualPayments: { type: Array, default: () => [] },
    pageSettings: { type: Object, default: () => ({}) },
    callbackUrls: { type: Object, default: () => ({}) },
});

const tab = ref('methods');
const tabs = [
    ['methods', 'Metode Pembayaran'],
    ['page', 'Tampilan Halaman'],
    ['transactions', 'Transaksi Pembayaran'],
];

const filters = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    source: props.filters?.source || '',
    channel: Number(props.filters?.channel || 0),
    per_page: Number(props.filters?.per_page || 25),
});

const gateways = reactive(props.gateways.map((item) => ({ ...item })));
watch(() => props.gateways, (items) => {
    gateways.splice(0, gateways.length, ...items.map((item) => ({ ...item })));
}, { deep: true });

const routes = reactive(props.routes.map((item) => ({ ...item })));
watch(() => props.routes, (items) => {
    routes.splice(0, routes.length, ...items.map((item) => ({ ...item })));
}, { deep: true });

const walletSettings = reactive({
    minimum_topup_idr: Number(props.walletSettings?.minimum_topup_idr || 10000),
    topup_enabled: props.walletSettings?.topup_enabled !== false,
});
watch(() => props.walletSettings, (value) => {
    walletSettings.minimum_topup_idr = Number(value?.minimum_topup_idr || 10000);
    walletSettings.topup_enabled = value?.topup_enabled !== false;
}, { deep: true });

const paymentPage = reactive({
    accentColor: props.pageSettings?.accentColor || '#b9ff35',
    headerImageUrl: props.pageSettings?.headerImageUrl || '',
    eyebrow: props.pageSettings?.eyebrow || 'LFAMILIA PAYMENT',
    pendingTitle: props.pageSettings?.pendingTitle || 'Selesaikan pembayaran',
    paidTitle: props.pageSettings?.paidTitle || 'Pembayaran berhasil',
    failedTitle: props.pageSettings?.failedTitle || 'Pembayaran tidak aktif',
    subtitle: props.pageSettings?.subtitle || '',
    invoiceNoticeTitle: props.pageSettings?.invoiceNoticeTitle || 'Simpan invoice sebelum membayar',
    invoiceNoticeText: props.pageSettings?.invoiceNoticeText || '',
    pendingStatusText: props.pageSettings?.pendingStatusText || '',
    paidStatusText: props.pageSettings?.paidStatusText || '',
    failedStatusText: props.pageSettings?.failedStatusText || '',
    payButtonText: props.pageSettings?.payButtonText || 'Bayar Sekarang',
    checkStatusButtonText: props.pageSettings?.checkStatusButtonText || 'Cek status',
    checkInvoiceButtonText: props.pageSettings?.checkInvoiceButtonText || 'Cek invoice',
    supportText: props.pageSettings?.supportText || 'Butuh bantuan pembayaran?',
    supportUrl: props.pageSettings?.supportUrl || '/contact',
    showStoreBrand: props.pageSettings?.showStoreBrand !== false,
    showInvoiceNotice: props.pageSettings?.showInvoiceNotice !== false,
    showOrderSummary: props.pageSettings?.showOrderSummary !== false,
    showStatusBox: props.pageSettings?.showStatusBox !== false,
    showSupport: props.pageSettings?.showSupport !== false,
});
watch(() => props.pageSettings, (value) => Object.assign(paymentPage, value || {}), { deep: true });

const channelEditor = ref(null);
const channelLogoFile = ref(null);
const channelLogoPreview = ref('');
const selectedTransaction = ref(null);
const showAdvancedRouting = ref(false);
const saving = ref('');

const methodLabel = (value) => ({
    QRIS: 'QRIS',
    VIRTUAL_ACCOUNT: 'Virtual Account',
    EWALLET: 'E-Wallet',
    RETAIL: 'Gerai Retail',
    WALLET: 'Saldo',
    OTHER: 'Lainnya',
}[value] || value);

const statusLabel = (value) => ({
    CREATING: 'Menyiapkan',
    SENDING: 'Mengirim permintaan',
    PENDING: 'Menunggu',
    UNKNOWN: 'Belum pasti',
    PAID: 'Dibayar',
    FAILED: 'Gagal',
    EXPIRED: 'Kedaluwarsa',
    CANCELLED: 'Dibatalkan',
    REJECTED: 'Ditolak',
    REFUNDED: 'Dikembalikan',
}[value] || value);

const statusVariant = (value) => {
    if (value === 'PAID') return 'secondary';
    if (['FAILED', 'REJECTED'].includes(value)) return 'destructive';
    return 'outline';
};

const healthLabel = (value) => ({
    HEALTHY: 'Sehat',
    UNTESTED: 'Belum diuji',
    STALE: 'Perlu diuji ulang',
    DOWN: 'Bermasalah',
    NOT_CONFIGURED: 'Belum dikonfigurasi',
    INTERNAL: 'Internal',
}[value] || 'Belum diketahui');

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : '—';

const successRate = () => {
    const total = Number(props.summary?.total || 0);
    return total ? Math.round((Number(props.summary?.paid || 0) / total) * 100) : 0;
};

function searchTransactions() {
    router.get('/admin/payments', {
        q: filters.q || undefined,
        status: filters.status || undefined,
        source: filters.source || undefined,
        channel: filters.channel || undefined,
        per_page: filters.per_page,
    }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => { tab.value = 'transactions'; },
    });
}

function resetTransactions() {
    Object.assign(filters, { q: '', status: '', source: '', channel: 0, per_page: 25 });
    searchTransactions();
}

function openCreateChannel() {
    channelEditor.value = {
        id: null,
        code: '',
        method: 'VIRTUAL_ACCOUNT',
        name: '',
        description: '',
        fee_flat_idr: 0,
        fee_percent: 0,
        supports_order: true,
        supports_wallet_topup: true,
        sort_order: props.channels.length
            ? Math.max(...props.channels.map((item) => Number(item.sort_order || 0))) + 10
            : 10,
        is_active: false,
    };
    channelLogoFile.value = null;
    channelLogoPreview.value = '';
}

function openEditChannel(channel) {
    channelEditor.value = {
        ...channel,
        fee_percent: Number(channel.fee_percent_bps || 0) / 100,
    };
    channelLogoFile.value = null;
    channelLogoPreview.value = channel.logo_url || '';
}

function closeChannelEditor() {
    channelEditor.value = null;
    channelLogoFile.value = null;
    channelLogoPreview.value = '';
}

function chooseChannelLogo(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        window.alert('Ukuran logo maksimal 2 MB.');
        event.target.value = '';
        return;
    }
    channelLogoFile.value = file;
    const reader = new FileReader();
    reader.onload = () => { channelLogoPreview.value = String(reader.result || ''); };
    reader.readAsDataURL(file);
}

function uploadChannelLogo(channelId, file, done = () => {}) {
    router.post('/admin/payments/channels/' + channelId + '/logo', { image: file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: done,
    });
}

function saveChannel() {
    if (!channelEditor.value || saving.value) return;
    const row = channelEditor.value;
    const payload = {
        code: String(row.code || '').trim().toLowerCase(),
        method: row.method,
        name: row.name,
        description: row.description || null,
        fee_flat_idr: Number(row.fee_flat_idr || 0),
        fee_percent_bps: Math.max(0, Math.min(9999, Math.round(Number(row.fee_percent || 0) * 100))),
        supports_order: Boolean(row.supports_order),
        supports_wallet_topup: Boolean(row.supports_wallet_topup),
        sort_order: Number(row.sort_order || 0),
        is_active: Boolean(row.is_active),
    };
    saving.value = 'channel';

    const options = {
        preserveScroll: true,
        onSuccess: (page) => {
            const saved = (page.props.channels || []).find((item) => item.code === payload.code);
            if (channelLogoFile.value && saved?.id) {
                const file = channelLogoFile.value;
                channelLogoFile.value = null;
                uploadChannelLogo(saved.id, file, closeChannelEditor);
                return;
            }
            closeChannelEditor();
        },
        onFinish: () => { saving.value = ''; },
    };

    if (row.id) {
        router.put('/admin/payments/channels/' + row.id, payload, options);
    } else {
        router.post('/admin/payments/channels', payload, options);
    }
}

function deleteChannel(channel) {
    if (!window.confirm('Hapus metode pembayaran "' + channel.name + '"?')) return;
    router.delete('/admin/payments/channels/' + channel.id, { preserveScroll: true });
}

function syncChannels() {
    router.post('/admin/payments/channels/sync', {}, { preserveScroll: true });
}

function saveGateway(gateway) {
    router.put('/admin/payments/gateways/' + gateway.id, {
        internal_name: gateway.internal_name,
        sort_order: Number(gateway.sort_order || 0),
        is_active: Boolean(gateway.is_active),
        is_maintenance: Boolean(gateway.is_maintenance),
    }, { preserveScroll: true });
}

function saveWalletSettings() {
    router.put('/admin/payments/settings', {
        minimum_topup_idr: Number(walletSettings.minimum_topup_idr || 0),
        topup_enabled: Boolean(walletSettings.topup_enabled),
    }, { preserveScroll: true });
}

function saveRoute(row) {
    router.put('/admin/payments/routes/' + row.id, {
        priority: Number(row.priority || 0),
        supports_order: Boolean(row.supports_order),
        supports_wallet_topup: Boolean(row.supports_wallet_topup),
        is_active: Boolean(row.is_active),
    }, { preserveScroll: true });
}

function uploadManualQris(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post('/admin/payments/manual-qris/image', { image: file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { event.target.value = ''; },
    });
}

function toggleManualQris() {
    router.put('/admin/payments/manual-qris', {
        is_active: !props.manualQrisAsset?.is_active,
    }, { preserveScroll: true });
}

function confirmManual(id) {
    if (!window.confirm('Konfirmasi bahwa pembayaran QRIS manual ini benar-benar sudah diterima?')) return;
    router.post('/admin/payments/manual/' + id + '/confirm', {}, { preserveScroll: true });
}

function resolveRefundReview(topup) {
    if (!window.confirm('Rekonsiliasi refund ini dari saldo pelanggan? Dana hanya dipotong jika saldo mencukupi.')) return;
    router.post('/admin/payments/refund-reviews/' + topup.id + '/resolve', {}, { preserveScroll: true });
}

function savePaymentPage() {
    router.put('/admin/payments/page-settings', { ...paymentPage }, { preserveScroll: true });
}

function uploadPaymentHeader(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post('/admin/payments/page-settings/header', { image: file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { event.target.value = ''; },
    });
}

async function copy(value) {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        window.prompt('Salin URL ini:', value);
    }
}
</script>

<template>
<Head title="Pembayaran" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pembayaran</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Kelola metode pembayaran, biaya customer, routing internal, top up saldo, tampilan halaman, dan riwayat transaksi. Nama gateway tidak ditampilkan ke customer.
                </p>
            </div>
            <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total pembayaran</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.total || 0).toLocaleString('id-ID') }}</p><p class="mt-1 text-xs text-muted-foreground">seluruh transaksi tersimpan</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Nilai berhasil</p><p class="mt-2 text-xl font-semibold">{{ money(summary.paid_amount_idr) }}</p><p class="mt-1 text-xs text-muted-foreground">{{ Number(summary.paid || 0).toLocaleString('id-ID') }} pembayaran</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Menunggu</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.pending || 0).toLocaleString('id-ID') }}</p><p class="mt-1 text-xs text-muted-foreground">termasuk status belum pasti</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Berhasil</p><p class="mt-2 text-2xl font-semibold">{{ successRate() }}%</p><p class="mt-1 text-xs text-muted-foreground">rasio transaksi dibayar</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Gagal / kedaluwarsa</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.failed || 0).toLocaleString('id-ID') }}</p><p class="mt-1 text-xs text-muted-foreground">{{ Number(summary.refunded || 0).toLocaleString('id-ID') }} dikembalikan</p></Card>
        </div>

        <nav class="flex max-w-full gap-1 overflow-x-auto rounded-lg border p-1">
            <Button v-for="[key, label] in tabs" :key="key" type="button" :variant="tab === key ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = key">{{ label }}</Button>
        </nav>

        <template v-if="tab === 'methods'">
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
                <Card class="min-w-0 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-lg font-semibold">Metode Pembayaran</h2><p class="mt-1 text-sm text-muted-foreground">Nama, deskripsi, logo, biaya, urutan, penggunaan, dan status dapat dikelola dari sini.</p></div>
                        <div v-if="isSuperAdmin" class="flex flex-wrap gap-2"><Button size="sm" variant="outline" @click="syncChannels">Pulihkan metode bawaan</Button><Button size="sm" @click="openCreateChannel">Tambah metode</Button></div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <Table>
                            <TableHeader><TableRow><TableHead>Metode</TableHead><TableHead>Jenis</TableHead><TableHead>Biaya customer</TableHead><TableHead>Kesiapan</TableHead><TableHead>Status</TableHead><TableHead class="text-right">Aksi</TableHead></TableRow></TableHeader>
                            <TableBody>
                                <TableRow v-for="channel in channels" :key="channel.id">
                                    <TableCell><div class="flex min-w-[190px] items-center gap-3"><div class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted"><img v-if="channel.logo_url" :src="channel.logo_url" :alt="channel.name" class="size-full object-contain"><span v-else class="text-xs font-semibold">{{ channel.name.slice(0, 2).toUpperCase() }}</span></div><div><strong class="text-sm">{{ channel.name }}</strong><p class="mt-0.5 max-w-[260px] text-xs text-muted-foreground">{{ channel.description || channel.code }}</p></div></div></TableCell>
                                    <TableCell>{{ methodLabel(channel.method) }}</TableCell>
                                    <TableCell><span v-if="Number(channel.fee_percent_bps || 0)">{{ (Number(channel.fee_percent_bps) / 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) }}%</span><span v-if="Number(channel.fee_percent_bps || 0) && Number(channel.fee_flat_idr || 0)"> + </span><span v-if="Number(channel.fee_flat_idr || 0)">{{ money(channel.fee_flat_idr) }}</span><span v-if="!Number(channel.fee_percent_bps || 0) && !Number(channel.fee_flat_idr || 0)">Rp0</span></TableCell>
                                    <TableCell><div class="flex flex-wrap gap-1"><Badge :variant="channel.available_order ? 'secondary' : 'outline'">Pesanan {{ channel.available_order ? 'siap' : 'belum siap' }}</Badge><Badge v-if="channel.supports_wallet_topup" :variant="channel.available_topup ? 'secondary' : 'outline'">Top up {{ channel.available_topup ? 'siap' : 'belum siap' }}</Badge></div></TableCell>
                                    <TableCell><Badge :variant="channel.is_active ? 'secondary' : 'outline'">{{ channel.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></TableCell>
                                    <TableCell class="text-right"><div v-if="isSuperAdmin" class="flex justify-end gap-2"><Button size="sm" variant="outline" @click="openEditChannel(channel)">Edit</Button><Button size="sm" variant="outline" @click="deleteChannel(channel)">Hapus</Button></div><span v-else class="text-xs text-muted-foreground">Lihat saja</span></TableCell>
                                </TableRow>
                                <TableRow v-if="!channels.length"><TableCell colspan="6" class="py-10 text-center text-muted-foreground">Belum ada metode pembayaran.</TableCell></TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </Card>

                <div class="space-y-4">
                    <Card class="p-4">
                        <div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">Top Up Saldo</h2><p class="mt-1 text-xs text-muted-foreground">Customer hanya melihat metode yang routing top up-nya aktif.</p></div><label class="flex items-center gap-2 text-sm"><input v-model="walletSettings.topup_enabled" type="checkbox" class="size-4" :disabled="!isSuperAdmin">Aktif</label></div>
                        <label class="mt-4 block space-y-1"><span class="text-sm font-medium">Minimum top up</span><Input v-model.number="walletSettings.minimum_topup_idr" type="number" min="1" max="100000000" :disabled="!isSuperAdmin" /></label>
                        <Button v-if="isSuperAdmin" class="mt-3 w-full" size="sm" @click="saveWalletSettings">Simpan top up saldo</Button><p v-else class="mt-3 text-xs text-muted-foreground">Pengaturan top up hanya dapat diubah Super Admin.</p>
                    </Card>

                    <Card class="p-4">
                        <h2 class="font-semibold">QRIS Manual</h2><p class="mt-1 text-xs text-muted-foreground">QRIS manual membutuhkan gambar aktif dan konfirmasi Admin sebelum pesanan diproses.</p>
                        <div class="mt-3 overflow-hidden rounded-md border bg-white p-2"><img v-if="manualQrisAsset?.image_url" :src="manualQrisAsset.image_url" alt="QRIS manual" class="mx-auto max-h-48 object-contain"><div v-else class="grid h-32 place-items-center text-sm text-slate-500">Gambar belum diunggah</div></div>
                        <label class="mt-3 block text-sm"><span class="mb-1 block font-medium">Ganti gambar</span><input type="file" accept="image/png,image/jpeg,image/webp" class="block w-full text-xs" @change="uploadManualQris"></label>
                        <Button class="mt-3 w-full" size="sm" :variant="manualQrisAsset?.is_active ? 'outline' : 'default'" @click="toggleManualQris">{{ manualQrisAsset?.is_active ? 'Nonaktifkan QRIS manual' : 'Aktifkan QRIS manual' }}</Button>
                    </Card>

                    <Card class="p-4">
                        <h2 class="font-semibold">URL Notifikasi Gateway</h2><p class="mt-1 text-xs text-muted-foreground">URL untuk dashboard gateway. Kredensial tetap di Integrasi.</p>
                        <div class="mt-3 space-y-2">
                            <button type="button" class="w-full rounded-md border p-3 text-left" @click="copy(callbackUrls.midtrans)"><span class="block text-xs font-medium">Midtrans</span><span class="mt-1 block break-all text-[11px] text-muted-foreground">{{ callbackUrls.midtrans }}</span></button>
                            <button type="button" class="w-full rounded-md border p-3 text-left" @click="copy(callbackUrls.doku)"><span class="block text-xs font-medium">DOKU</span><span class="mt-1 block break-all text-[11px] text-muted-foreground">{{ callbackUrls.doku }}</span></button>
                        </div>
                    </Card>
                </div>
            </div>

            <Card v-if="channelEditor" class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ channelEditor.id ? 'Edit Metode Pembayaran' : 'Tambah Metode Pembayaran' }}</h2><p class="mt-1 text-sm text-muted-foreground">Biaya di bawah dibebankan ke customer.</p></div><Button size="sm" variant="ghost" @click="closeChannelEditor">Tutup</Button></div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label class="space-y-1"><span class="text-sm font-medium">Nama tampilan</span><Input v-model="channelEditor.name" maxlength="100" /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Kode metode</span><Input v-model="channelEditor.code" maxlength="60" placeholder="contoh: qris" /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Jenis</span><select v-model="channelEditor.method" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="QRIS">QRIS</option><option value="VIRTUAL_ACCOUNT">Virtual Account</option><option value="EWALLET">E-Wallet</option><option value="RETAIL">Gerai Retail</option><option value="WALLET">Saldo</option><option value="OTHER">Lainnya</option></select></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Urutan</span><Input v-model.number="channelEditor.sort_order" type="number" min="0" max="9999" /></label>
                    <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Deskripsi customer</span><Input v-model="channelEditor.description" maxlength="160" /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Biaya persen (%)</span><Input v-model.number="channelEditor.fee_percent" type="number" min="0" max="99.99" step="0.01" /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Biaya tetap (Rp)</span><Input v-model.number="channelEditor.fee_flat_idr" type="number" min="0" max="100000000" /></label>
                    <div class="space-y-2 rounded-md border p-3"><span class="text-sm font-medium">Digunakan untuk</span><label class="flex items-center gap-2 text-sm"><input v-model="channelEditor.supports_order" type="checkbox" class="size-4">Pembayaran pesanan</label><label class="flex items-center gap-2 text-sm"><input v-model="channelEditor.supports_wallet_topup" type="checkbox" class="size-4">Top up saldo</label></div>
                    <div class="space-y-2 rounded-md border p-3"><span class="text-sm font-medium">Status</span><label class="flex items-center gap-2 text-sm"><input v-model="channelEditor.is_active" type="checkbox" class="size-4">Metode aktif</label><p class="text-xs text-muted-foreground">Tetap hanya tampil bila routing dan gateway siap.</p></div>
                    <div class="space-y-2 rounded-md border p-3 md:col-span-2"><span class="text-sm font-medium">Logo metode</span><div class="flex items-center gap-3"><div class="grid size-14 place-items-center overflow-hidden rounded-md border bg-muted"><img v-if="channelLogoPreview" :src="channelLogoPreview" alt="" class="size-full object-contain"><span v-else class="text-xs text-muted-foreground">Logo</span></div><input type="file" accept="image/png,image/jpeg,image/webp" class="min-w-0 text-xs" @change="chooseChannelLogo"></div></div>
                </div>
                <div class="mt-4 flex justify-end gap-2"><Button variant="outline" @click="closeChannelEditor">Batal</Button><Button :disabled="saving === 'channel'" @click="saveChannel">{{ saving === 'channel' ? 'Menyimpan…' : 'Simpan metode' }}</Button></div>
            </Card>

            <Card class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">Gateway Pembayaran</h2><p class="mt-1 text-sm text-muted-foreground">Status, maintenance, nama internal, dan urutan dikelola di sini. Secret tetap hanya di Integrasi.</p></div><Button v-if="isSuperAdmin" size="sm" variant="outline" as-child><Link href="/admin/integrations">Buka Integrasi</Link></Button></div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div v-for="gateway in gateways" :key="gateway.id" class="rounded-md border p-3">
                        <div class="flex items-start justify-between gap-2"><Badge :variant="['HEALTHY','INTERNAL'].includes(gateway.health?.status) ? 'secondary' : 'outline'">{{ healthLabel(gateway.health?.status) }}</Badge><span class="text-[11px] text-muted-foreground">{{ gateway.code }}</span></div>
                        <p class="mt-2 min-h-8 text-xs text-muted-foreground">{{ gateway.health?.message }}</p>
                        <label class="mt-3 block space-y-1"><span class="text-xs font-medium">Nama internal</span><Input v-model="gateway.internal_name" maxlength="100" :disabled="!isSuperAdmin" /></label>
                        <label class="mt-3 block space-y-1"><span class="text-xs font-medium">Urutan</span><Input v-model.number="gateway.sort_order" type="number" min="0" max="9999" :disabled="!isSuperAdmin" /></label>
                        <div class="mt-3 space-y-2 text-sm"><label class="flex items-center gap-2"><input v-model="gateway.is_active" type="checkbox" class="size-4" :disabled="!isSuperAdmin">Aktif</label><label class="flex items-center gap-2"><input v-model="gateway.is_maintenance" type="checkbox" class="size-4" :disabled="!isSuperAdmin">Maintenance</label></div>
                        <p v-if="!gateway.credential_configured && gateway.kind === 'EXTERNAL'" class="mt-2 text-xs text-amber-600">Kredensial belum aktif.</p>
                        <Button v-if="isSuperAdmin" class="mt-3 w-full" size="sm" variant="outline" @click="saveGateway(gateway)">Simpan gateway</Button>
                    </div>
                </div>
            </Card>

            <Card class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">Routing Pembayaran</h2><p class="mt-1 text-sm text-muted-foreground">Customer memilih metode. Sistem menentukan gateway berdasarkan routing aktif dan urutan gateway.</p></div><Button v-if="isSuperAdmin" size="sm" variant="outline" @click="showAdvancedRouting = !showAdvancedRouting">{{ showAdvancedRouting ? 'Tutup pengaturan' : 'Atur routing' }}</Button></div>
                <div class="mt-4 overflow-x-auto">
                    <Table><TableHeader><TableRow><TableHead>Metode</TableHead><TableHead>Gateway</TableHead><TableHead>Pesanan</TableHead><TableHead>Top up</TableHead><TableHead>Urutan Gateway</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                    <TableBody><TableRow v-for="row in routes" :key="row.id"><TableCell>{{ row.channel_name }}</TableCell><TableCell>{{ row.gateway_name }}</TableCell><TableCell>{{ row.supports_order ? 'Ya' : 'Tidak' }}</TableCell><TableCell>{{ row.supports_wallet_topup ? 'Ya' : 'Tidak' }}</TableCell><TableCell>{{ row.priority }}</TableCell><TableCell><Badge :variant="row.is_active ? 'secondary' : 'outline'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></TableCell></TableRow><TableRow v-if="!routes.length"><TableCell colspan="6" class="py-8 text-center text-muted-foreground">Belum ada routing pembayaran.</TableCell></TableRow></TableBody></Table>
                </div>

                <div v-if="showAdvancedRouting && isSuperAdmin" class="mt-4 space-y-4 border-t pt-4">
                    <p class="rounded-md border p-3 text-sm text-muted-foreground">Endpoint, kode channel provider, signature, dan struktur request/response ditentukan oleh source code. Panel ini hanya mengatur penggunaan routing yang sudah didukung aplikasi.</p>
                    <div class="space-y-3">
                        <div v-for="row in routes" :key="'edit-' + row.id" class="grid gap-3 rounded-md border p-3 md:grid-cols-2 xl:grid-cols-6">
                            <div class="xl:col-span-2"><p class="text-sm font-medium">{{ row.channel_name }}</p><p class="text-xs text-muted-foreground">{{ row.gateway_name }}</p></div>
                            <label class="space-y-1"><span class="text-xs font-medium">Urutan Gateway</span><Input v-model.number="row.priority" type="number" min="0" max="9999" /><span class="block text-[11px] text-muted-foreground">Angka lebih kecil digunakan lebih dahulu. Contoh: 10 sebelum 20.</span></label>
                            <div class="space-y-2 text-sm"><label class="flex items-center gap-2"><input v-model="row.supports_order" type="checkbox" class="size-4">Pesanan</label><label class="flex items-center gap-2"><input v-model="row.supports_wallet_topup" type="checkbox" class="size-4">Top up</label></div>
                            <div class="space-y-2 text-sm"><label class="flex items-center gap-2"><input v-model="row.is_active" type="checkbox" class="size-4">Aktif</label></div>
                            <div class="flex items-end justify-end"><Button size="sm" variant="outline" @click="saveRoute(row)">Simpan</Button></div>
                        </div>
                        <p v-if="!routes.length" class="rounded-md border p-3 text-sm text-muted-foreground">Belum ada routing yang didukung source code.</p>
                    </div>
                </div>
            </Card>
        </template>

        <template v-else-if="tab === 'page'">
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_390px]">
                <Card class="p-4">
                    <h2 class="text-lg font-semibold">Editor Halaman Pembayaran</h2><p class="mt-1 text-sm text-muted-foreground">Teks, warna, banner, instruksi, dan elemen customer dapat diedit tanpa mengubah kode.</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <label class="space-y-1"><span class="text-sm font-medium">Teks kecil di atas judul</span><Input v-model="paymentPage.eyebrow" maxlength="60" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Warna utama</span><div class="flex gap-2"><input v-model="paymentPage.accentColor" type="color" class="h-10 w-12 rounded-md border p-1"><Input v-model="paymentPage.accentColor" maxlength="7" /></div></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Judul menunggu</span><Input v-model="paymentPage.pendingTitle" maxlength="100" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Judul berhasil</span><Input v-model="paymentPage.paidTitle" maxlength="100" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Judul gagal</span><Input v-model="paymentPage.failedTitle" maxlength="100" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Teks tombol bayar</span><Input v-model="paymentPage.payButtonText" maxlength="80" /></label>
                        <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Instruksi utama</span><Textarea v-model="paymentPage.subtitle" maxlength="240" rows="3" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Judul pemberitahuan invoice</span><Input v-model="paymentPage.invoiceNoticeTitle" maxlength="120" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Teks tombol cek invoice</span><Input v-model="paymentPage.checkInvoiceButtonText" maxlength="80" /></label>
                        <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Isi pemberitahuan invoice</span><Textarea v-model="paymentPage.invoiceNoticeText" maxlength="500" rows="3" /></label>
                        <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Pesan status menunggu</span><Textarea v-model="paymentPage.pendingStatusText" maxlength="300" rows="2" /></label>
                        <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Pesan status berhasil</span><Textarea v-model="paymentPage.paidStatusText" maxlength="300" rows="2" /></label>
                        <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Pesan status gagal</span><Textarea v-model="paymentPage.failedStatusText" maxlength="300" rows="2" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Teks tombol cek status</span><Input v-model="paymentPage.checkStatusButtonText" maxlength="80" /></label>
                        <label class="space-y-1"><span class="text-sm font-medium">Teks bantuan</span><Input v-model="paymentPage.supportText" maxlength="120" /></label>
                        <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">URL bantuan</span><Input v-model="paymentPage.supportUrl" maxlength="500" /></label>
                        <div class="grid gap-2 rounded-md border p-3 md:col-span-2 sm:grid-cols-2">
                            <label class="flex items-center gap-2 text-sm"><input v-model="paymentPage.showStoreBrand" type="checkbox" class="size-4">Tampilkan identitas toko</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="paymentPage.showOrderSummary" type="checkbox" class="size-4">Tampilkan ringkasan pesanan</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="paymentPage.showInvoiceNotice" type="checkbox" class="size-4">Tampilkan pemberitahuan invoice</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="paymentPage.showStatusBox" type="checkbox" class="size-4">Tampilkan status pembayaran</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="paymentPage.showSupport" type="checkbox" class="size-4">Tampilkan bantuan</label>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <label class="text-sm"><span class="mb-1 block font-medium">Banner pembayaran</span><input type="file" accept="image/png,image/jpeg,image/webp" class="block text-xs" @change="uploadPaymentHeader"></label>
                        <Button @click="savePaymentPage">Simpan tampilan</Button>
                    </div>
                </Card>

                <Card class="overflow-hidden">
                    <div v-if="paymentPage.headerImageUrl" class="h-28 overflow-hidden bg-muted"><img :src="paymentPage.headerImageUrl" alt="Preview banner pembayaran" class="size-full object-cover"></div>
                    <div v-else class="grid h-28 place-items-center bg-muted text-sm font-semibold">Preview Banner</div>
                    <div class="p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ paymentPage.eyebrow }}</p>
                        <h3 class="mt-2 text-xl font-semibold">{{ paymentPage.pendingTitle }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ paymentPage.subtitle }}</p>
                        <div v-if="paymentPage.showOrderSummary" class="mt-4 rounded-md border p-3 text-sm"><div class="flex justify-between"><span>Contoh pesanan</span><strong>Rp20.000</strong></div></div>
                        <div v-if="paymentPage.showStatusBox" class="mt-3 rounded-md border p-3 text-sm">{{ paymentPage.pendingStatusText }}</div>
                        <button type="button" class="mt-3 h-10 w-full rounded-md font-semibold text-slate-950" :style="{ backgroundColor: paymentPage.accentColor }">{{ paymentPage.payButtonText }}</button>
                        <p v-if="paymentPage.showSupport" class="mt-3 text-center text-xs text-muted-foreground">{{ paymentPage.supportText }}</p>
                    </div>
                </Card>
            </div>
        </template>

        <template v-else>
            <Card class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">Transaksi Pembayaran</h2><p class="mt-1 text-sm text-muted-foreground">Riwayat pembayaran pesanan dan top up saldo. Data payload rahasia tidak ditampilkan.</p></div></div>
                <form class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="searchTransactions">
                    <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Cari</span><Input v-model="filters.q" maxlength="100" placeholder="Invoice, referensi, nama, email, atau WhatsApp" /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Status</span><select v-model="filters.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="">Semua status</option><option value="CREATING">Menyiapkan</option><option value="SENDING">Mengirim permintaan</option><option value="PENDING">Menunggu</option><option value="UNKNOWN">Belum pasti</option><option value="PAID">Dibayar</option><option value="FAILED">Gagal</option><option value="EXPIRED">Kedaluwarsa</option><option value="CANCELLED">Dibatalkan</option><option value="REJECTED">Ditolak</option><option value="REFUNDED">Dikembalikan</option></select></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Sumber</span><select v-model="filters.source" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="">Semua</option><option value="order">Pesanan</option><option value="topup">Top up saldo</option></select></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Metode</span><select v-model.number="filters.channel" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"><option :value="0">Semua metode</option><option v-for="channel in channels" :key="channel.id" :value="channel.id">{{ channel.name }}</option></select></label>
                    <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5"><Button type="submit" size="sm">Terapkan</Button><Button type="button" size="sm" variant="outline" @click="resetTransactions">Reset</Button></div>
                </form>

                <div class="mt-4 overflow-x-auto">
                    <Table><TableHeader><TableRow><TableHead>Referensi</TableHead><TableHead>Customer</TableHead><TableHead>Metode</TableHead><TableHead>Total</TableHead><TableHead>Status</TableHead><TableHead>Waktu</TableHead><TableHead class="text-right">Aksi</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="row in transactions.data" :key="row.id">
                            <TableCell><strong class="text-sm">{{ row.order_number || row.merchant_reference || ('#' + row.id) }}</strong><p class="mt-0.5 text-xs text-muted-foreground">{{ row.source === 'ORDER' ? 'Pesanan' : 'Top up saldo' }}</p></TableCell>
                            <TableCell><span class="text-sm">{{ row.customer_name }}</span><p class="mt-0.5 text-xs text-muted-foreground">{{ row.customer_email || row.customer_phone || '—' }}</p></TableCell>
                            <TableCell><span class="text-sm">{{ row.channel_name }}</span></TableCell>
                            <TableCell>{{ money(row.amount_idr) }}</TableCell>
                            <TableCell><Badge :variant="statusVariant(row.status)">{{ statusLabel(row.status) }}</Badge></TableCell>
                            <TableCell>{{ date(row.created_at) }}</TableCell>
                            <TableCell class="text-right"><Button size="sm" variant="outline" @click="selectedTransaction = row">Detail</Button></TableCell>
                        </TableRow>
                        <TableRow v-if="!transactions.data?.length"><TableCell colspan="7" class="py-10 text-center text-muted-foreground">Belum ada transaksi yang sesuai filter.</TableCell></TableRow>
                    </TableBody></Table>
                </div>
                <div v-if="transactions.links?.length > 3" class="mt-4 flex flex-wrap gap-1"><Link v-for="link in transactions.links" :key="link.label" :href="link.url || '#'" preserve-scroll :class="['rounded-md border px-3 py-1.5 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-background', !link.url ? 'pointer-events-none opacity-40' : '']"><span v-html="link.label" /></Link></div>
            </Card>

            <Card v-if="refundReviews.length" class="mt-4 p-4">
                <h2 class="text-lg font-semibold">Refund Top Up Perlu Rekonsiliasi</h2>
                <p class="mt-1 text-sm text-muted-foreground">Gateway sudah mengembalikan pembayaran, tetapi saldo yang pernah dikreditkan sudah terpakai. Belanja dengan saldo dibatasi sampai kewajiban ini selesai.</p>
                <div class="mt-4 space-y-2">
                    <div v-for="topup in refundReviews" :key="'refund-' + topup.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                        <div>
                            <strong class="text-sm">{{ topup.customer_name }}</strong>
                            <p class="text-xs text-muted-foreground">{{ topup.customer_email }} · Top up #{{ topup.id }}</p>
                            <p class="mt-1 text-xs">Refund: {{ money(topup.amount_idr) }} · Saldo saat ini: {{ money(topup.balance_idr) }}</p>
                        </div>
                        <Button
                            v-if="isSuperAdmin"
                            size="sm"
                            :disabled="Number(topup.balance_idr) < Number(topup.amount_idr)"
                            @click="resolveRefundReview(topup)"
                        >
                            {{ Number(topup.balance_idr) >= Number(topup.amount_idr) ? 'Selesaikan refund' : 'Saldo belum cukup' }}
                        </Button>
                    </div>
                </div>
            </Card>

            <Card v-if="manualPayments.length" class="mt-4 p-4">
                <h2 class="text-lg font-semibold">QRIS Manual Menunggu Konfirmasi</h2><p class="mt-1 text-sm text-muted-foreground">Konfirmasi hanya setelah dana benar-benar diterima.</p>
                <div class="mt-4 space-y-2">
                    <div v-for="payment in manualPayments" :key="payment.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3"><div><strong class="text-sm">{{ payment.order_number }}</strong><p class="text-xs text-muted-foreground">{{ money(payment.amount_idr) }} · {{ date(payment.created_at) }}</p></div><Button size="sm" @click="confirmManual(payment.id)">Konfirmasi diterima</Button></div>
                </div>
            </Card>
        </template>

        <Card v-if="selectedTransaction" class="fixed inset-x-3 bottom-3 z-50 mx-auto max-w-xl border shadow-lg sm:inset-x-auto sm:right-5 sm:w-[520px]">
            <div class="p-4">
                <div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">Detail Pembayaran</h2><p class="mt-1 text-xs text-muted-foreground">{{ selectedTransaction.merchant_reference || selectedTransaction.order_number }}</p></div><Button size="sm" variant="ghost" @click="selectedTransaction = null">Tutup</Button></div>
                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-md border p-3"><span class="text-xs text-muted-foreground">Customer</span><strong class="mt-1 block">{{ selectedTransaction.customer_name }}</strong></div>
                    <div class="rounded-md border p-3"><span class="text-xs text-muted-foreground">Total</span><strong class="mt-1 block">{{ money(selectedTransaction.amount_idr) }}</strong></div>
                    <div class="rounded-md border p-3"><span class="text-xs text-muted-foreground">Metode</span><strong class="mt-1 block">{{ selectedTransaction.channel_name }}</strong></div>
                    <div class="rounded-md border p-3"><span class="text-xs text-muted-foreground">Gateway internal</span><strong class="mt-1 block">{{ selectedTransaction.gateway_name }}</strong></div>
                    <div class="rounded-md border p-3"><span class="text-xs text-muted-foreground">Status</span><strong class="mt-1 block">{{ statusLabel(selectedTransaction.status) }}</strong></div>
                    <div class="rounded-md border p-3"><span class="text-xs text-muted-foreground">Callback diterima</span><strong class="mt-1 block">{{ selectedTransaction.callback_count }}</strong></div>
                    <div class="col-span-2 rounded-md border p-3"><span class="text-xs text-muted-foreground">Referensi eksternal</span><strong class="mt-1 block break-all">{{ selectedTransaction.external_reference || '—' }}</strong></div>
                </div>
            </div>
        </Card>
    </div>
</AdminShell>
</template>
