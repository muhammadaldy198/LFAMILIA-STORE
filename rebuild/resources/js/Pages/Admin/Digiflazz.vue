<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Card } from '../../Components/ui/card';
import { Badge } from '../../Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    items: Object,
    filters: Object,
    categories: Array,
    brands: Array,
    products: Array,
    summary: Object,
    connection: Object,
    lastSyncedAt: String,
    mappingCount: Number,
    providerActive: Boolean,
    autoSync: Boolean,
    recentTransactions: Array,
    canSeeBalance: Boolean,
});

const filters = reactive({
    q: props.filters?.q || '',
    category: props.filters?.category || '',
    brand: props.filters?.brand || '',
    product: props.filters?.product || '',
    health: props.filters?.health || '',
    scope: props.filters?.scope || 'mapped',
    per_page: props.filters?.per_page || 25,
});
const autoSyncForm = useForm({ enabled: props.autoSync });
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
        scope: 'mapped',
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
</script>

<template>
<Head title="Digiflazz" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Digiflazz</h1>
                <p class="mt-1 text-sm text-muted-foreground">Monitor harga modal, seller, stok, cut-off, sinkronisasi, dan transaksi Digiflazz.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" :disabled="syncForm.processing" @click="refresh">Muat ulang</Button>
                <Button :disabled="syncForm.processing" @click="sync()">
                    {{ syncForm.processing ? 'Menyinkronkan…' : 'Sinkron daftar harga' }}
                </Button>
            </div>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
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
                <p class="text-xs text-muted-foreground">SKU terhubung</p>
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
            <div class="flex flex-wrap items-start justify-between gap-4">
                <form class="flex flex-wrap items-center gap-3" @submit.prevent="autoSyncForm.put('/admin/digiflazz/settings', { preserveScroll: true })">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="autoSyncForm.enabled" type="checkbox" class="size-4">
                        Sinkron harga otomatis setiap 15 menit
                    </label>
                    <Button size="sm" variant="outline" :disabled="autoSyncForm.processing">Simpan</Button>
                </form>
                <div class="text-right text-xs text-muted-foreground">
                    <p>Digiflazz {{ providerActive ? 'aktif' : 'nonaktif' }} di menu Provider</p>
                    <p>Sinkron terakhir: {{ date(lastSyncedAt) }}</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">Harga jual, margin, prioritas sumber, dan max price tetap dikelola di menu Produk. Credential tetap dikelola di Integrasi.</p>
        </Card>

        <Card class="p-4">
            <form class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="search">
                <label class="space-y-1 sm:col-span-2">
                    <span class="text-sm font-medium">Cari</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Produk, nominal, SKU, seller, atau brand" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Tampilan</span>
                    <select v-model="filters.scope" class="digiflazz-select">
                        <option value="mapped">SKU yang terhubung</option>
                        <option value="all">Semua daftar harga</option>
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
                </label>
                <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-4">
                    <Button :disabled="syncForm.processing">Terapkan filter</Button>
                    <Button type="button" variant="outline" @click="reset">Hapus filter</Button>
                </div>
            </form>
        </Card>

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <Table class="min-w-[1100px] table-fixed">
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[23%]">Produk / nominal</TableHead>
                            <TableHead class="w-[15%]">SKU / seller</TableHead>
                            <TableHead class="w-[18%]">Modal / baseline</TableHead>
                            <TableHead class="w-[16%]">Stok / cut-off</TableHead>
                            <TableHead class="w-[18%]">Status</TableHead>
                            <TableHead class="w-[10%]">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="item in items.data" :key="item.id">
                            <TableCell class="align-top">
                                <strong class="break-words">{{ item.local_product_name || item.product_name }}</strong>
                                <p class="mt-1 break-words text-sm">{{ item.local_package_name || item.product_name }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ item.category || 'Tanpa kategori' }} · {{ item.brand || 'Tanpa brand' }}</p>
                                <Badge class="mt-2" variant="outline">{{ item.mapped ? 'Terhubung' : 'Belum diimpor' }}</Badge>
                            </TableCell>
                            <TableCell class="align-top">
                                <p class="break-all font-mono text-xs">{{ item.buyer_sku_code }}</p>
                                <p class="mt-2 text-sm">{{ item.seller_name || 'Seller tidak tersedia' }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ item.multi ? 'Mendukung transaksi bersamaan' : 'Transaksi berurutan' }}</p>
                            </TableCell>
                            <TableCell class="align-top">
                                <strong>{{ money(item.price_idr) }}</strong>
                                <p class="mt-1 text-xs text-muted-foreground">Baseline {{ money(item.baseline_price_idr) }}</p>
                                <p v-if="Number(item.price_idr) > Number(item.baseline_price_idr)" class="mt-2 text-xs">
                                    Naik {{ money(Number(item.price_idr) - Number(item.baseline_price_idr)) }}
                                </p>
                            </TableCell>
                            <TableCell class="align-top">
                                <p>{{ item.unlimited_stock ? 'Tidak terbatas' : Number(item.stock).toLocaleString('id-ID') + ' tersisa' }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ item.start_cut_off === item.end_cut_off ? 'Tanpa cut-off' : item.start_cut_off + '–' + item.end_cut_off + ' WIB' }}</p>
                            </TableCell>
                            <TableCell class="align-top">
                                <Badge :variant="healthVariant(item.health)">{{ healthLabel(item.health) }}</Badge>
                                <p v-if="item.alert_reason" class="mt-2 text-xs text-muted-foreground">{{ item.alert_reason }}</p>
                                <p class="mt-2 text-xs text-muted-foreground">Produk {{ item.buyer_active ? 'aktif' : 'nonaktif' }} · Seller {{ item.seller_active ? 'aktif' : 'nonaktif' }}</p>
                                <p v-if="item.mapped" class="mt-1 text-xs text-muted-foreground">Mapping {{ item.mapping_active ? 'aktif' : 'nonaktif' }}</p>
                            </TableCell>
                            <TableCell class="align-top">
                                <div class="flex flex-col gap-2">
                                    <Button size="sm" variant="outline" :disabled="syncForm.processing" @click="sync(item.id)">Sinkron SKU</Button>
                                    <Button size="sm" variant="outline" @click="baseline(item.id)">Jadikan baseline</Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!items.data.length">
                            <TableCell colspan="6" class="py-10 text-center text-sm text-muted-foreground">Tidak ada SKU yang sesuai dengan filter.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4">
                <p class="text-sm text-muted-foreground">{{ items.from || 0 }}–{{ items.to || 0 }} dari {{ items.total }} SKU · Halaman {{ items.current_page }} dari {{ items.last_page }}</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="!items.prev_page_url" @click="router.get(items.prev_page_url, {}, { preserveState:true, preserveScroll:true })">Sebelumnya</Button>
                    <Button variant="outline" size="sm" :disabled="!items.next_page_url" @click="router.get(items.next_page_url, {}, { preserveState:true, preserveScroll:true })">Berikutnya</Button>
                </div>
            </div>
        </Card>

        <Card class="overflow-hidden">
            <div class="border-b p-4">
                <h2 class="font-semibold">Transaksi Digiflazz terbaru</h2>
                <p class="mt-1 text-xs text-muted-foreground">Ringkasan operasional. Riwayat dan tindakan lengkap tetap berada di menu Pesanan dan Manual.</p>
            </div>
            <div class="overflow-x-auto">
                <Table class="min-w-[800px]">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Pesanan</TableHead>
                            <TableHead>Produk</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Waktu</TableHead>
                            <TableHead>Catatan</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in recentTransactions" :key="row.id">
                            <TableCell><Link :href="'/admin/orders/' + row.order_id" class="font-medium underline-offset-4 hover:underline">{{ row.order_number }}</Link></TableCell>
                            <TableCell>{{ row.product_name }}<p class="text-xs text-muted-foreground">{{ row.package_name }}</p></TableCell>
                            <TableCell><Badge variant="secondary">{{ row.status_label }}</Badge><p v-if="row.provider_status" class="mt-1 text-xs text-muted-foreground">{{ row.provider_status }}</p></TableCell>
                            <TableCell class="text-sm">{{ date(row.created_at) }}</TableCell>
                            <TableCell class="max-w-[280px] text-sm">{{ row.message || '—' }}</TableCell>
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
