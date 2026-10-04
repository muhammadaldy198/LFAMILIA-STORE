<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { nextTick, reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    vouchers: { type: Object, default: () => ({ data: [], links: [] }) },
    popularProducts: { type: Object, default: () => ({ data: [], links: [] }) },
    categories: { type: Array, default: () => [] },
    scopeProducts: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const section = ref('vouchers');
const editorOpen = ref(false);
const editingId = ref(null);

const voucherFilters = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    discount_type: props.filters?.discount_type || '',
    per_page: Number(props.filters?.per_page || 25),
});

const popularFilters = reactive({
    popular_q: props.filters?.popular_q || '',
    popular_category: props.filters?.popular_category || '',
    popular_status: props.filters?.popular_status || '',
    product_per_page: Number(props.filters?.product_per_page || 25),
});

const voucherForm = useForm({
    code: '',
    name: '',
    description: '',
    discount_type: 'PERCENT',
    discount_value: 10,
    max_discount_idr: null,
    minimum_total_idr: 0,
    total_quota: null,
    per_customer_limit: null,
    starts_at: '',
    ends_at: '',
    product_ids: [],
    category_ids: [],
    is_active: true,
});

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Tanpa batas';

const statusLabel = (value) => ({
    active: 'Aktif',
    scheduled: 'Terjadwal',
    expired: 'Berakhir',
    inactive: 'Nonaktif',
    exhausted: 'Kuota habis',
}[value] || value);

const statusVariant = (value) => value === 'active' ? 'secondary' : 'outline';

function submitVoucherFilters() {
    router.get('/admin/vouchers', {
        ...voucherFilters,
        popular_q: props.filters?.popular_q || undefined,
        popular_category: props.filters?.popular_category || undefined,
        popular_status: props.filters?.popular_status || undefined,
        product_per_page: props.filters?.product_per_page || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function resetVoucherFilters() {
    Object.assign(voucherFilters, { q: '', status: '', discount_type: '', per_page: 25 });
    submitVoucherFilters();
}

function submitPopularFilters() {
    router.get('/admin/vouchers', {
        q: props.filters?.q || undefined,
        status: props.filters?.status || undefined,
        discount_type: props.filters?.discount_type || undefined,
        per_page: props.filters?.per_page || undefined,
        ...popularFilters,
    }, { preserveState: true, preserveScroll: true });
}

function resetPopularFilters() {
    Object.assign(popularFilters, {
        popular_q: '',
        popular_category: '',
        popular_status: '',
        product_per_page: 25,
    });
    submitPopularFilters();
}

function openCreate() {
    editingId.value = null;
    voucherForm.reset();
    voucherForm.clearErrors();
    Object.assign(voucherForm, {
        code: '',
        name: '',
        description: '',
        discount_type: 'PERCENT',
        discount_value: 10,
        max_discount_idr: null,
        minimum_total_idr: 0,
        total_quota: null,
        per_customer_limit: null,
        starts_at: '',
        ends_at: '',
        product_ids: [],
        category_ids: [],
        is_active: true,
    });
    editorOpen.value = true;
    nextTick(() => document.getElementById('promotions-editor')?.scrollIntoView({ block: 'start' }));
}

function openEdit(row) {
    editingId.value = row.id;
    voucherForm.clearErrors();
    Object.assign(voucherForm, {
        code: row.code,
        name: row.name,
        description: row.description || '',
        discount_type: row.discount_type,
        discount_value: Number(row.discount_value),
        max_discount_idr: row.max_discount_idr,
        minimum_total_idr: Number(row.minimum_total_idr || 0),
        total_quota: row.total_quota,
        per_customer_limit: row.per_customer_limit,
        starts_at: row.starts_at || '',
        ends_at: row.ends_at || '',
        product_ids: [...(row.product_ids || [])],
        category_ids: [...(row.category_ids || [])],
        is_active: Boolean(row.is_active),
    });
    editorOpen.value = true;
    nextTick(() => document.getElementById('promotions-editor')?.scrollIntoView({ block: 'start' }));
}

function closeEditor() {
    editorOpen.value = false;
    editingId.value = null;
    voucherForm.clearErrors();
}

function saveVoucher() {
    const options = {
        preserveScroll: true,
        onSuccess: () => closeEditor(),
    };
    if (editingId.value) {
        voucherForm.put('/admin/vouchers/' + editingId.value, options);
        return;
    }
    voucherForm.post('/admin/vouchers', options);
}

function quickToggle(row) {
    router.put('/admin/vouchers/' + row.id, {
        code: row.code,
        name: row.name,
        description: row.description,
        discount_type: row.discount_type,
        discount_value: Number(row.discount_value),
        max_discount_idr: row.max_discount_idr,
        minimum_total_idr: Number(row.minimum_total_idr || 0),
        total_quota: row.total_quota,
        per_customer_limit: row.per_customer_limit,
        starts_at: row.starts_at,
        ends_at: row.ends_at,
        product_ids: row.product_ids || [],
        category_ids: row.category_ids || [],
        is_active: !row.is_active,
    }, { preserveScroll: true });
}

function deleteVoucher(row) {
    if (!window.confirm('Hapus voucher ' + row.code + '? Voucher yang sudah memiliki riwayat transaksi tidak dapat dihapus.')) return;
    router.delete('/admin/vouchers/' + row.id, { preserveScroll: true });
}

function togglePopular(product) {
    router.put('/admin/vouchers/popular/' + product.id, {
        popular: !product.popular,
    }, { preserveScroll: true });
}

function discountLabel(row) {
    return row.discount_type === 'PERCENT'
        ? Number(row.discount_value).toLocaleString('id-ID') + '%'
        : money(row.discount_value);
}
</script>

<template>
<Head title="Promo" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Promo</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Kelola voucher diskon dan prioritas produk di bagian Populer Sekarang. Flash Sale tidak digunakan.
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
                <Button @click="openCreate">Tambah voucher</Button>
            </div>
        </header>

        <div class="lf-admin-summary ">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total voucher</p><p class="mt-2 text-2xl font-semibold">{{ summary.total_vouchers || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Voucher aktif</p><p class="mt-2 text-2xl font-semibold">{{ summary.active_vouchers || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Terpakai</p><p class="mt-2 text-2xl font-semibold">{{ summary.used_count || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Reservasi aktif</p><p class="mt-2 text-2xl font-semibold">{{ summary.reserved_count || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Produk populer</p><p class="mt-2 text-2xl font-semibold">{{ summary.popular_products || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Populer & aktif</p><p class="mt-2 text-2xl font-semibold">{{ summary.active_popular_products || 0 }}</p></Card>
        </div>

        <nav class="lf-admin-tabs">
            <Button type="button" :variant="section === 'vouchers' ? 'secondary' : 'ghost'" class="shrink-0" @click="section = 'vouchers'">Voucher Diskon</Button>
            <Button type="button" :variant="section === 'popular' ? 'secondary' : 'ghost'" class="shrink-0" @click="section = 'popular'">Populer Sekarang</Button>
        </nav>

        <template v-if="section === 'vouchers'">
            <Card class="p-4">
                <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="submitVoucherFilters">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-sm font-medium">Cari voucher</span>
                        <Input v-model="voucherFilters.q" maxlength="100" placeholder="Kode, nama, atau deskripsi" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Status</span>
                        <select v-model="voucherFilters.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua status</option>
                            <option value="active">Aktif</option>
                            <option value="scheduled">Terjadwal</option>
                            <option value="expired">Berakhir</option>
                            <option value="inactive">Nonaktif</option>
                            <option value="exhausted">Kuota habis</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Jenis diskon</span>
                        <select v-model="voucherFilters.discount_type" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua jenis</option>
                            <option value="PERCENT">Persentase</option>
                            <option value="FIXED">Nominal tetap</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Baris</span>
                        <select v-model.number="voucherFilters.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                        </select>
                    </label>
                    <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
                        <Button size="sm">Terapkan</Button>
                        <Button type="button" size="sm" variant="outline" @click="resetVoucherFilters">Reset</Button>
                    </div>
                </form>
            </Card>

            <Card class="min-w-0 p-4">
                <div class="overflow-x-auto">
                    <AdminResponsiveTable :mobile-columns="[0,1,6,7]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Voucher</TableHead>
                                <TableHead>Diskon</TableHead>
                                <TableHead>Minimum</TableHead>
                                <TableHead>Pemakaian</TableHead>
                                <TableHead>Periode</TableHead>
                                <TableHead>Cakupan</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="text-right">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="row in vouchers.data" :key="row.id">
                                <TableCell>
                                    <strong class="block">{{ row.code }}</strong>
                                    <span class="text-xs text-muted-foreground">{{ row.name }}</span>
                                </TableCell>
                                <TableCell>
                                    <strong>{{ discountLabel(row) }}</strong>
                                    <p v-if="row.max_discount_idr" class="text-xs text-muted-foreground">Maks. {{ money(row.max_discount_idr) }}</p>
                                </TableCell>
                                <TableCell>{{ money(row.minimum_total_idr) }}</TableCell>
                                <TableCell>
                                    <span>{{ row.used_count }} terpakai · {{ row.reserved_count }} dipesan</span>
                                    <p class="text-xs text-muted-foreground">
                                        {{ row.total_quota === null ? 'Kuota tanpa batas' : (row.remaining_quota + ' dari ' + row.total_quota + ' tersisa') }}
                                        · {{ row.per_customer_limit === null ? 'tanpa limit/customer' : ('maks. ' + row.per_customer_limit + '/customer') }}
                                    </p>
                                </TableCell>
                                <TableCell>
                                    <p>{{ row.starts_at ? date(row.starts_at) : 'Mulai langsung' }}</p>
                                    <p class="text-xs text-muted-foreground">s.d. {{ row.ends_at ? date(row.ends_at) : 'tanpa batas' }}</p>
                                </TableCell>
                                <TableCell>{{ row.scope_label }}</TableCell>
                                <TableCell><Badge :variant="statusVariant(row.status)">{{ statusLabel(row.status) }}</Badge></TableCell>
                                <TableCell class="text-right">
                                    <div class="flex justify-end gap-1">
                                        <Button size="sm" variant="outline" @click="openEdit(row)">Edit</Button>
                                        <Button size="sm" variant="outline" @click="quickToggle(row)">{{ row.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</Button>
                                        <Button size="sm" variant="destructive" @click="deleteVoucher(row)">Hapus</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="!vouchers.data?.length"><TableCell colspan="8" class="py-10 text-center text-muted-foreground">Tidak ada voucher sesuai filter.</TableCell></TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>

                <div v-if="vouchers.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="link in vouchers.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        preserve-scroll
                        :class="['rounded-md border px-3 py-1.5 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-background', !link.url ? 'pointer-events-none opacity-40' : '']"
                        v-html="link.label"
                    />
                </div>
            </Card>
        </template>

        <template v-else>
            <Card class="p-4">
                <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="submitPopularFilters">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-sm font-medium">Cari produk</span>
                        <Input v-model="popularFilters.popular_q" maxlength="100" placeholder="Nama atau slug produk" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Kategori</span>
                        <select v-model="popularFilters.popular_category" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua kategori</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Status</span>
                        <select v-model="popularFilters.popular_status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua</option>
                            <option value="popular">Diprioritaskan</option>
                            <option value="not_popular">Tidak diprioritaskan</option>
                            <option value="active">Produk aktif</option>
                            <option value="inactive">Produk nonaktif</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Baris</span>
                        <select v-model.number="popularFilters.product_per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                        </select>
                    </label>
                    <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
                        <Button size="sm">Terapkan</Button>
                        <Button type="button" size="sm" variant="outline" @click="resetPopularFilters">Reset</Button>
                    </div>
                </form>
            </Card>

            <Card class="min-w-0 p-4">
                <div class="mb-4 rounded-md border p-3 text-sm text-muted-foreground">
                    Produk bertanda populer didahulukan di homepage. Jika slot belum penuh, storefront melanjutkan dengan produk aktif yang memiliki ulasan terbanyak. Status produk tetap dikelola dari menu Produk.
                </div>
                <div class="overflow-x-auto">
                    <AdminResponsiveTable :mobile-columns="[0,2,3,4,5]">
                        <TableHeader><TableRow><TableHead>Produk</TableHead><TableHead>Kategori</TableHead><TableHead>Nominal</TableHead><TableHead>Status Produk</TableHead><TableHead>Prioritas</TableHead><TableHead class="text-right">Aksi</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="product in popularProducts.data" :key="product.id">
                                <TableCell>
                                    <div class="flex min-w-[220px] items-center gap-3">
                                        <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="size-10 rounded-md border object-cover">
                                        <div><strong class="block">{{ product.name }}</strong><span class="text-xs text-muted-foreground">{{ product.slug }}</span></div>
                                    </div>
                                </TableCell>
                                <TableCell>{{ product.category_name }}</TableCell>
                                <TableCell>{{ product.packages_count }}</TableCell>
                                <TableCell><Badge variant="outline">{{ product.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></TableCell>
                                <TableCell><Badge :variant="product.popular ? 'secondary' : 'outline'">{{ product.popular ? 'Diprioritaskan' : 'Normal' }}</Badge></TableCell>
                                <TableCell class="text-right"><Button size="sm" :variant="product.popular ? 'outline' : 'default'" @click="togglePopular(product)">{{ product.popular ? 'Lepas prioritas' : 'Jadikan populer' }}</Button></TableCell>
                            </TableRow>
                            <TableRow v-if="!popularProducts.data?.length"><TableCell colspan="6" class="py-10 text-center text-muted-foreground">Tidak ada produk sesuai filter.</TableCell></TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>

                <div v-if="popularProducts.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="link in popularProducts.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        preserve-scroll
                        :class="['rounded-md border px-3 py-1.5 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-background', !link.url ? 'pointer-events-none opacity-40' : '']"
                        v-html="link.label"
                    />
                </div>
            </Card>
        </template>

        <Card v-if="editorOpen" id="promotions-editor" class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ editingId ? 'Edit Voucher' : 'Tambah Voucher' }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Atur kode, nilai diskon, batas, jadwal, dan cakupan. Perubahan tidak menulis ulang harga pesanan yang sudah dibuat.</p>
                </div>
                <Button type="button" size="sm" variant="ghost" @click="closeEditor">Tutup</Button>
            </div>

            <form class="mt-4 space-y-4" @submit.prevent="saveVoucher">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label class="space-y-1"><span class="text-sm font-medium">Kode voucher</span><Input v-model="voucherForm.code" maxlength="40" required placeholder="LFHEMAT" /><span v-if="voucherForm.errors.code" class="text-xs text-destructive">{{ voucherForm.errors.code }}</span></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Nama promo</span><Input v-model="voucherForm.name" maxlength="100" required placeholder="Hemat Akhir Pekan" /><span v-if="voucherForm.errors.name" class="text-xs text-destructive">{{ voucherForm.errors.name }}</span></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Jenis diskon</span><select v-model="voucherForm.discount_type" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="PERCENT">Persentase</option><option value="FIXED">Nominal tetap</option></select></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Nilai diskon</span><Input v-model.number="voucherForm.discount_value" type="number" min="1" :max="voucherForm.discount_type === 'PERCENT' ? 100 : 1000000000" required /><span v-if="voucherForm.errors.discount_value" class="text-xs text-destructive">{{ voucherForm.errors.discount_value }}</span></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Maksimum diskon</span><Input v-model.number="voucherForm.max_discount_idr" type="number" min="1" placeholder="Tanpa batas" /><span class="text-xs text-muted-foreground">Opsional; berguna terutama untuk diskon persen.</span></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Minimum transaksi</span><Input v-model.number="voucherForm.minimum_total_idr" type="number" min="0" required /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Total kuota</span><Input v-model.number="voucherForm.total_quota" type="number" min="1" placeholder="Tanpa batas" /><span v-if="voucherForm.errors.total_quota" class="text-xs text-destructive">{{ voucherForm.errors.total_quota }}</span></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Limit per pelanggan</span><Input v-model.number="voucherForm.per_customer_limit" type="number" min="1" placeholder="Tanpa batas" /></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Mulai</span><Input v-model="voucherForm.starts_at" type="datetime-local" /><span class="text-xs text-muted-foreground">Kosong = langsung.</span></label>
                    <label class="space-y-1"><span class="text-sm font-medium">Berakhir</span><Input v-model="voucherForm.ends_at" type="datetime-local" /><span v-if="voucherForm.errors.ends_at" class="text-xs text-destructive">{{ voucherForm.errors.ends_at }}</span></label>
                    <label class="flex items-center gap-2 self-end rounded-md border px-3 py-2 text-sm"><AdminSwitch v-model="voucherForm.is_active" />Voucher aktif</label>
                </div>

                <label class="block space-y-1">
                    <span class="text-sm font-medium">Deskripsi internal</span>
                    <Textarea v-model="voucherForm.description" maxlength="300" rows="2" placeholder="Catatan singkat tentang promo ini" />
                </label>

                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Cakupan kategori</span>
                        <select v-model="voucherForm.category_ids" multiple class="min-h-36 w-full rounded-md border border-input bg-background p-2 text-sm">
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}{{ category.is_active ? '' : ' · nonaktif' }}</option>
                        </select>
                        <span class="text-xs text-muted-foreground">Kosong berarti tidak membatasi berdasarkan kategori.</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Cakupan produk</span>
                        <select v-model="voucherForm.product_ids" multiple class="min-h-36 w-full rounded-md border border-input bg-background p-2 text-sm">
                            <option v-for="product in scopeProducts" :key="product.id" :value="product.id">{{ product.name }}{{ product.is_active ? '' : ' · nonaktif' }}</option>
                        </select>
                        <span class="text-xs text-muted-foreground">Kategori dan produk memakai logika OR. Jika keduanya kosong, voucher berlaku global.</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="closeEditor">Batal</Button>
                    <Button :disabled="voucherForm.processing">{{ voucherForm.processing ? 'Menyimpan…' : 'Simpan voucher' }}</Button>
                </div>
            </form>
        </Card>
    </div>
</AdminShell>
</template>
