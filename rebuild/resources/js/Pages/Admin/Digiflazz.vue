<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Card } from '../../Components/ui/card';
import { Badge } from '../../Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    items: { type: Object, default: () => ({ data: [] }) },
    filters: Object,
    categories: Array,
    brands: Array,
    products: Array,
    summary: Object,
    connection: Object,
    lastSyncedAt: String,
    catalogSync: Object,
    mappingCount: Number,
    providerActive: Boolean,
    autoSync: Boolean,
    monitorSettings: Object,
    recentTransactions: { type: Array, default: () => [] },
    canSeeBalance: Boolean,
});

const items = computed(() => ({
    ...(props.items || {}),
    data: Array.isArray(props.items?.data) ? props.items.data : [],
    total: props.items?.total ?? 0,
    current_page: props.items?.current_page ?? 1,
    last_page: props.items?.last_page ?? 1,
}));
const recentTransactions = computed(() => Array.isArray(props.recentTransactions) ? props.recentTransactions : []);
const filters = reactive({
    q: props.filters?.q || '',
    category: props.filters?.category || '',
    brand: props.filters?.brand || '',
    product: props.filters?.product || '',
    health: props.filters?.health || '',
    scope: props.filters?.scope || 'all',
    per_page: props.filters?.per_page || 25,
});
const autoSyncForm = useForm({
    enabled: props.autoSync,
    sync_interval_minutes: Number(props.monitorSettings?.sync_interval_minutes || 15),
    low_stock_threshold: Number(props.monitorSettings?.low_stock_threshold || 5),
    price_warning_percent: Number(props.monitorSettings?.price_warning_percent || 3),
});
const syncForm = useForm({});

const money = value => value == null ? '—' : 'Rp' + Number(value).toLocaleString('id-ID');
const date = value => value
    ? new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value)) + ' WIB'
    : 'Belum pernah';

const healthLabel = value => ({
    healthy: 'Normal',
    warning: 'Peringatan',
    critical: 'Kritis',
}[value] || 'Belum diperiksa');

const healthVariant = value => value === 'critical'
    ? 'destructive'
    : value === 'warning' ? 'outline' : 'secondary';

const connectionLabel = value => ({
    HEALTHY: 'Terhubung',
    UNTESTED: 'Belum dites',
    STALE: 'Perlu dites ulang',
    DOWN: 'Bermasalah',
    NOT_CONFIGURED: 'Belum dikonfigurasi',
}[value] || value || 'Belum diketahui');

function search() {
    router.get('/admin/digiflazz', {
        q: filters.q || undefined,
        category: filters.category || undefined,
        brand: filters.brand || undefined,
        product: filters.product || undefined,
        health: filters.health || undefined,
        scope: filters.scope,
        per_page: filters.per_page,
    }, { preserveState: true, preserveScroll: true });
}

function reset() {
    Object.assign(filters, {
        q: '',
        category: '',
        brand: '',
        product: '',
        health: '',
        scope: 'all',
        per_page: 25,
    });
    search();
}

function sync(id = null) {
    syncForm.transform(() => id ? { item_id: id } : {})
        .post('/admin/digiflazz/sync', { preserveScroll: true });
}

function baseline(id) {
    router.put('/admin/digiflazz/baseline/' + id, {}, { preserveScroll: true });
}

function refresh() {
    router.reload({ preserveScroll: true });
}

let refreshTimer, removeStartListener, removeFinishListener;
let visitInProgress = false;
onMounted(() => {
    removeStartListener = router.on('start', () => { visitInProgress = true; });
    removeFinishListener = router.on('finish', () => { visitInProgress = false; });
    refreshTimer = setInterval(() => {
        if (document.visibilityState === 'visible' && !visitInProgress && !syncForm.processing && !autoSyncForm.processing) {
            router.reload({
                only: ['items', 'summary', 'lastSyncedAt', 'catalogSync', 'categories', 'brands', 'products', 'mappingCount'],
                preserveScroll: true,
            });
        }
    }, 30000);
});
onUnmounted(() => {
    clearInterval(refreshTimer);
    removeStartListener?.();
    removeFinishListener?.();
});
</script>

<template>
<Head title="Digiflazz" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-col gap-4 border-b border-border pb-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">Digiflazz</h1>
                <p class="mt-1 text-sm text-muted-foreground">Pantau harga modal, penjual, stok, jam layanan, pembaruan daftar harga, dan transaksi Digiflazz.</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Link href="/admin/catalog?import=digiflazz" class="inline-flex min-h-10 items-center justify-center rounded-md border px-3 py-2 text-sm font-medium">Impor ke produk</Link>
                <Button class="w-full sm:w-auto" variant="outline" :disabled="syncForm.processing" @click="refresh">Muat ulang</Button>
                <Button class="w-full sm:w-auto" :disabled="syncForm.processing" @click="sync()">
                    {{ syncForm.processing ? 'Menyinkronkan…' : 'Sinkron daftar harga' }}
                </Button>
            </div>
        </header>

        <div class="lf-admin-summary">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Koneksi</p>
                <p class="mt-2 font-semibold">{{ connectionLabel(connection?.status) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ connection?.message }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Saldo</p>
                <p class="mt-2 font-semibold">{{ canSeeBalance ? money(connection?.balance_idr) : 'Khusus Pemilik' }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ canSeeBalance ? date(connection?.balance_checked_at) : 'Saldo tidak ditampilkan untuk Admin.' }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">SKU dalam daftar</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary?.total || 0).toLocaleString('id-ID') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ mappingCount }} mapping tersimpan</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Normal</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary?.healthy || 0).toLocaleString('id-ID') }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Peringatan</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary?.warning || 0).toLocaleString('id-ID') }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Kritis</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary?.critical || 0).toLocaleString('id-ID') }}</p>
            </Card>
        </div>

        <Card class="p-4">
            <div class="mb-3 space-y-1 text-xs text-muted-foreground">
                <p>Sinkron daftar harga lengkap: {{ date(catalogSync?.at || lastSyncedAt) }} · Sinkron otomatis {{ autoSync ? 'setiap ' + monitorSettings.sync_interval_minutes + ' menit' : 'nonaktif' }}.</p>
                <p>Daftar di layar dimuat ulang setiap 30 detik. SKU yang hilang tetapi punya riwayat transaksi disimpan sebagai tidak tersedia; mapping tanpa riwayat dibersihkan.</p>
                <p v-if="Number(catalogSync?.missing_marked_unavailable || 0) > 0 || Number(catalogSync?.mappings_deleted_without_history || 0) > 0">Sinkron terakhir menandai {{ catalogSync.missing_marked_unavailable || 0 }} SKU hilang yang memiliki riwayat sebagai tidak tersedia. {{ catalogSync.mappings_deleted_without_history || 0 }} mapping tanpa riwayat dibersihkan.</p>
            </div>
            <details><summary class="cursor-pointer font-semibold">Pengaturan monitor & sinkron otomatis</summary>
            <form class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="autoSyncForm.put('/admin/digiflazz/settings', { preserveScroll: true })">
                <label class="flex items-center gap-2 text-sm md:col-span-2 xl:col-span-1">
                    <AdminSwitch v-model="autoSyncForm.enabled" />
                    Sinkron harga otomatis
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Interval sinkron</span>
                    <div class="flex items-center gap-2">
                        <Input v-model.number="autoSyncForm.sync_interval_minutes" type="number" min="5" max="1440" step="5" />
                        <span class="text-xs text-muted-foreground">menit</span>
                    </div>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Peringatan stok menipis</span>
                    <Input v-model.number="autoSyncForm.low_stock_threshold" type="number" min="1" max="1000000" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Peringatan kenaikan harga</span>
                    <div class="flex items-center gap-2">
                        <Input v-model.number="autoSyncForm.price_warning_percent" type="number" min="0.1" max="100" step="0.1" />
                        <span class="text-xs text-muted-foreground">%</span>
                    </div>
                </label>
                <div class="flex flex-wrap items-end justify-between gap-3 md:col-span-2 xl:col-span-4">
                    <Button size="sm" variant="outline" :disabled="autoSyncForm.processing">Simpan pengaturan monitor</Button>
                    <div class="text-right text-xs text-muted-foreground">
                        <p>Digiflazz {{ providerActive ? 'aktif' : 'nonaktif' }} di menu Provider</p>
                        <p>Sinkron terakhir: {{ date(lastSyncedAt) }}</p>
                    </div>
                </div>
            </form>
            <p class="mt-3 text-xs text-muted-foreground">Harga jual, margin, dan prioritas sumber dikelola di menu Produk. Credential tetap dikelola di Integrasi.</p>
            </details>
        </Card>

        <Card class="p-4">
            <form class="grid min-w-0 grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4" @submit.prevent="search">
<label class="space-y-1 col-span-2 md:col-span-1">
                    <span class="text-sm font-medium">Cari</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Produk, nominal, SKU, seller, atau brand" />
                </label>
<label class="space-y-1">
                    <span class="text-sm font-medium">Kategori</span>
                    <select v-model="filters.category" class="digiflazz-select">
                        <option value="">Semua kategori</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </label>
<label class="space-y-1">
                    <span class="text-sm font-medium">Produk</span>
                    <select v-model="filters.product" class="digiflazz-select">
                        <option value="">Semua produk</option>
                        <option v-for="product in products" :key="product" :value="product">{{ product }}</option>
                    </select>
                </label>
<label class="space-y-1">
                    <span class="text-sm font-medium">Kesehatan</span>
                    <select v-model="filters.health" class="digiflazz-select">
                        <option value="">Semua status</option>
                        <option value="healthy">Normal</option>
                        <option value="warning">Peringatan</option>
                        <option value="critical">Kritis</option>
                    </select>
                </label>
                <details class="col-span-2 md:col-span-4"><summary class="cursor-pointer text-sm font-medium">Filter lanjutan</summary><div class="mt-3 grid gap-3 sm:grid-cols-3"><label class="space-y-1">
                    <span class="text-sm font-medium">Tampilan</span>
                    <select v-model="filters.scope" class="digiflazz-select">
                        <option value="mapped">SKU yang terhubung</option>
                        <option value="all">Semua daftar harga</option>
                    </select>
                </label>
<label class="space-y-1">
                    <span class="text-sm font-medium">Brand</span>
                    <select v-model="filters.brand" class="digiflazz-select">
                        <option value="">Semua brand</option>
                        <option v-for="brand in brands" :key="brand" :value="brand">{{ brand }}</option>
                    </select>
                </label>
<label class="space-y-1">
                    <span class="text-sm font-medium">Baris per halaman</span>
                    <select v-model="filters.per_page" class="digiflazz-select">
                        <option v-for="size in [10,25,50,100]" :key="size" :value="size">{{ size }} SKU</option>
                    </select>
                </label></div></details>
                <div class="col-span-2 flex gap-2 md:col-span-4"><Button :disabled="syncForm.processing">Terapkan filter</Button><Button type="button" variant="outline" @click="reset">Hapus filter</Button></div>
            </form>
        </Card>

        <Card class="overflow-hidden">
            <div class="overflow-x-auto overscroll-x-contain" role="region" aria-label="Daftar produk dan harga Digiflazz" tabindex="0">
                <Table class="min-w-[900px] table-fixed">
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[25%]">Produk / Nominal</TableHead>
                            <TableHead class="w-[16%]">SKU / Seller</TableHead>
                            <TableHead class="w-[16%] text-right">Harga Modal</TableHead>
                            <TableHead class="w-[15%]">Stok & Cut-off</TableHead>
                            <TableHead class="w-[16%]">Kesehatan</TableHead>
                            <TableHead class="w-[12%] text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="item in items.data" :key="item.id" :data-digiflazz-health="item.health">
                            <TableCell class="align-top">
                                <strong class="break-words text-xs block">{{ item.local_product_name || item.product_name }}</strong>
                                <p class="text-[11px] text-muted-foreground">{{ item.local_package_name || item.product_name }}</p>
                                <span class="text-[10px] text-muted-foreground">{{ item.mapped ? 'Terhubung' : 'Belum diimpor' }}</span>
                            </TableCell>
                            <TableCell class="align-top">
                                <code class="text-xs font-mono text-primary">{{ item.buyer_sku_code }}</code>
                                <p class="text-[11px] text-muted-foreground truncate">{{ item.seller_name || '—' }}</p>
                            </TableCell>
                            <TableCell class="align-top text-right">
                                <strong class="text-xs">{{ money(item.price_idr) }}</strong>
                                <p v-if="item.baseline_price_idr" class="text-[10px] text-muted-foreground">Base: {{ money(item.baseline_price_idr) }}</p>
                            </TableCell>
                            <TableCell class="align-top text-xs">
                                <span>{{ item.unlimited_stock ? 'Tak terbatas' : Number(item.stock).toLocaleString('id-ID') }}</span>
                                <p class="text-[10px] text-muted-foreground">{{ item.start_cut_off === item.end_cut_off ? 'Tanpa cut-off' : item.start_cut_off + '–' + item.end_cut_off }}</p>
                            </TableCell>
                            <TableCell class="align-top">
                                <Badge :variant="healthVariant(item.health)" class="text-[10px]">{{ healthLabel(item.health) }}</Badge>
                                <p v-if="item.alert_reason" class="text-[10px] text-destructive mt-0.5 truncate" :title="item.alert_reason">{{ item.alert_reason }}</p>
                            </TableCell>
                            <TableCell class="align-top text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Button size="sm" variant="ghost" class="h-6 px-1.5 text-[10px]" :disabled="syncForm.processing" @click="sync(item.id)">Sync</Button>
                                    <Button size="sm" variant="outline" class="h-6 px-1.5 text-[10px]" @click="baseline(item.id)">Baseline</Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!items.data.length">
                            <TableCell colspan="6" class="py-10 text-center text-sm text-muted-foreground">Tidak ada SKU yang sesuai dengan filter.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <div class="flex flex-col gap-3 border-t p-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted-foreground">{{ items.from || 0 }}–{{ items.to || 0 }} dari {{ items.total }} SKU · Halaman {{ items.current_page }} dari {{ items.last_page }}</p>
                <div class="grid grid-cols-2 gap-2 sm:flex">
                    <Button class="w-full sm:w-auto" variant="outline" size="sm" :disabled="!items.prev_page_url" @click="router.get(items.prev_page_url, {}, { preserveState:true, preserveScroll:true })">Sebelumnya</Button>
                    <Button class="w-full sm:w-auto" variant="outline" size="sm" :disabled="!items.next_page_url" @click="router.get(items.next_page_url, {}, { preserveState:true, preserveScroll:true })">Berikutnya</Button>
                </div>
            </div>
        </Card>

        <Card class="overflow-hidden">
            <div class="border-b p-4">
                <h2 class="font-semibold text-sm">Transaksi Digiflazz terbaru</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Ringkasan operasional transaksi terakhir.</p>
            </div>

            <div class="overflow-x-auto overscroll-x-contain" role="region" aria-label="Transaksi Digiflazz" tabindex="0">
                <Table class="min-w-[700px]">
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[20%]">Pesanan</TableHead>
                            <TableHead class="w-[25%]">Produk</TableHead>
                            <TableHead class="w-[18%]">Status</TableHead>
                            <TableHead class="w-[17%]">Waktu</TableHead>
                            <TableHead class="w-[20%]">Catatan</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in recentTransactions" :key="row.id">
                            <TableCell><Link :href="'/admin/orders/' + row.order_id" class="font-medium text-xs text-primary underline-offset-4 hover:underline">{{ row.order_number }}</Link></TableCell>
                            <TableCell class="text-xs">{{ row.product_name }}<p class="text-[11px] text-muted-foreground">{{ row.package_name }}</p></TableCell>
                            <TableCell><Badge variant="secondary" class="text-[10px]">{{ row.status_label }}</Badge><p v-if="row.provider_status" class="mt-0.5 text-[10px] text-muted-foreground">{{ row.provider_status }}</p></TableCell>
                            <TableCell class="text-xs text-muted-foreground">{{ date(row.created_at) }}</TableCell>
                            <TableCell class="max-w-[240px] text-xs text-muted-foreground truncate">{{ row.message || '—' }}</TableCell>
                        </TableRow>
                        <TableRow v-if="!recentTransactions.length">
                            <TableCell colspan="5" class="py-8 text-center text-sm text-muted-foreground">Belum ada transaksi Digiflazz.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </Card>
    </div>
</AdminShell>
</template>

<style scoped>
.digiflazz-select {
    display: block;
    width: 100%;
    height: 2.5rem;
    border: 1px solid hsl(var(--border));
    border-radius: calc(var(--radius) - 2px);
    background: transparent;
    padding: 0 .75rem;
    font-size: .875rem;
}
</style>
