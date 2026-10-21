<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { byOrder, displayPosition } from '../../lib/ordering.js';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    mappings: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const rows = ref(props.providers.map((row) => ({ ...row })));
const providerPosition = (row) => displayPosition(rows.value, row, byOrder());
watch(() => props.providers, (value) => {
    rows.value = value.map((row) => ({ ...row }));
}, { deep: true });

const filters = reactive({
    q: props.filters?.q || '',
    provider: Number(props.filters?.provider || 0),
    status: props.filters?.status || '',
    per_page: Number(props.filters?.per_page || 25),
});

const saving = ref(null);
const selectedProviderId = ref(null);
const selectedProvider = () => rows.value.find((row) => row.id === selectedProviderId.value) || null;

const money = value => value == null ? '—' : 'Rp' + Number(value).toLocaleString('id-ID');
const date = value => value
    ? new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value)) + ' WIB'
    : 'Belum ada';

const modeLabel = value => ({
    AUTO_PROVIDER: 'Otomatis',
    MANUAL: 'Manual',
}[value] || 'Lainnya');

const healthLabel = value => ({
    HEALTHY: 'Sehat',
    READY: 'Siap',
    UNTESTED: 'Belum diuji',
    STALE: 'Perlu diuji ulang',
    DOWN: 'Bermasalah',
    NOT_CONFIGURED: 'Belum dikonfigurasi',
    INACTIVE: 'Nonaktif',
}[value] || 'Belum diketahui');

const healthVariant = value => value === 'DOWN'
    ? 'destructive'
    : ['HEALTHY', 'READY'].includes(value) ? 'secondary' : 'outline';

function search() {
    router.get('/admin/providers', {
        q: filters.q || undefined,
        provider: filters.provider || undefined,
        status: filters.status || undefined,
        per_page: filters.per_page,
    }, { preserveState: true, preserveScroll: true });
}

function reset() {
    Object.assign(filters, { q: '', provider: 0, status: '', per_page: 25 });
    search();
}

function saveProvider(row) {
    if (saving.value !== null) return;
    saving.value = row.id;
    router.put('/admin/providers/' + row.id, {
        display_name: row.display_name,
        description: row.description || null,
        sort_order: Number(row.sort_order || 0),
        is_active: Boolean(row.is_active),
    }, {
        preserveScroll: true,
        onFinish: () => { saving.value = null; },
    });
}
</script>

<template>
<Head title="Provider" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">Penyedia layanan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Aktifkan penyedia layanan, atur nama dan urutannya, lalu pantau nominal yang terhubung. Data akses penyedia dikelola di menu Integrasi.
                </p>
            </div>
            <Button class="w-full sm:w-auto" variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <Card class="overflow-hidden">
            <div class="border-b p-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Daftar Provider</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Konfigurasi nama tampilan, urutan pemrosesan, dan status aktif provider.</p>
                </div>
                <Button variant="outline" size="sm" as-child><Link href="/admin/integrations">Buka Integrasi</Link></Button>
            </div>

            <div class="overflow-x-auto overscroll-x-contain" role="region" aria-label="Daftar provider" tabindex="0">
                <Table class="min-w-[950px] table-fixed">
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[24%]">Provider / Keterangan</TableHead>
                            <TableHead class="w-[12%]">Layanan</TableHead>
                            <TableHead class="w-[16%]">Koneksi & Status</TableHead>
                            <TableHead class="w-[14%]">Denominasi</TableHead>
                            <TableHead class="w-[12%]">Performa 24j</TableHead>
                            <TableHead class="w-[10%]">Urutan</TableHead>
                            <TableHead class="w-[12%] text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in rows" :key="row.id">
                            <TableCell class="align-top">
                                <div class="space-y-1">
                                    <div class="text-xs font-semibold">{{ row.display_name }}</div>
                                    <p class="text-[11px] text-muted-foreground">{{ row.description || 'Tanpa keterangan' }}</p>
                                </div>
                            </TableCell>
                            <TableCell class="align-top">
                                <Badge variant="secondary" class="text-[10px]">{{ modeLabel(row.fulfillment_mode) }}</Badge>
                                <p class="mt-1 text-[10px] text-muted-foreground">Posisi {{ providerPosition(row) }}</p>
                            </TableCell>
                            <TableCell class="align-top">
                                <Badge :variant="healthVariant(row.health?.status)" class="text-[10px]">{{ healthLabel(row.health?.status) }}</Badge>
                                <p v-if="row.health?.message" class="mt-1 text-[10px] text-muted-foreground truncate" :title="row.health.message">{{ row.health.message }}</p>
                            </TableCell>
                            <TableCell class="align-top text-xs">
                                <strong>{{ row.active_mapping_count }}</strong> <span class="text-muted-foreground">/ {{ row.mapping_count }} aktif</span>
                                <p class="text-[10px] text-muted-foreground mt-0.5">Terakhir: {{ date(row.last_attempt_at) }}</p>
                            </TableCell>
                            <TableCell class="align-top text-xs">
                                <span class="text-emerald-400 font-medium">{{ Number(row.success_24h || 0).toLocaleString('id-ID') }} sukses</span>
                                <p v-if="row.attention_count" class="text-[10px] text-destructive font-medium mt-0.5">{{ row.attention_count }} perhatian</p>
                            </TableCell>
                            <TableCell class="align-top">
                                <span class="text-xs font-mono">{{ providerPosition(row) }}</span>
                            </TableCell>
                            <TableCell class="align-top text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <Badge :variant="row.is_active ? 'secondary' : 'outline'" class="text-[10px]">{{ row.is_active ? 'Aktif' : 'Off' }}</Badge>
                                    <Button size="sm" variant="outline" class="h-7 px-2 text-xs" @click="selectedProviderId = row.id">Atur</Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </Card>

        <Card v-if="selectedProvider()" class="p-4 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Atur {{ selectedProvider().display_name }}</h2>
                    <p class="text-sm text-muted-foreground">Ubah pengaturan provider yang dipilih.</p>
                </div>
                <Button variant="outline" size="sm" @click="selectedProviderId = null">Tutup</Button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1"><span class="text-sm font-medium">Nama tampilan</span><Input v-model="selectedProvider().display_name" maxlength="80" /></label>
                <label class="space-y-1"><span class="text-sm font-medium">Urutan</span><Input v-model.number="selectedProvider().sort_order" type="number" min="0" max="9999" /></label>
                <label class="space-y-1 sm:col-span-2"><span class="text-sm font-medium">Keterangan</span><Input v-model="selectedProvider().description" maxlength="500" /></label>
                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="selectedProvider().is_active" /> Aktif</label>
            </div>
            <div class="flex justify-end"><Button :disabled="saving === selectedProvider().id" @click="saveProvider(selectedProvider())">{{ saving === selectedProvider().id ? 'Menyimpan...' : 'Simpan provider' }}</Button></div>
        </Card>

        <Card class="p-4">
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Nominal produk yang dilayani penyedia</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Daftar ini menunjukkan nominal yang terhubung ke penyedia layanan. Untuk mengubah kode produk dari penyedia, harga, sumber utama atau cadangan, dan pengaturan nominal, buka menu Produk.
                </p>
            </div>

            <form class="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="search">
                <label class="space-y-1 md:col-span-2">
                    <span class="text-sm font-medium">Cari</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Produk, nominal, SKU, atau provider" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Provider</span>
                    <select v-model.number="filters.provider" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option :value="0">Semua provider</option>
                        <option v-for="row in providers" :key="row.id" :value="row.id">{{ row.display_name }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Status nominal</span>
                    <select v-model="filters.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Nominal per halaman</span>
                    <select v-model.number="filters.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </label>
                <div class="flex flex-col gap-2 sm:flex-row md:col-span-2 xl:col-span-5">
                    <Button class="w-full sm:w-auto" type="submit">Terapkan filter</Button>
                    <Button class="w-full sm:w-auto" type="button" variant="outline" @click="reset">Hapus filter</Button>
                    <Button type="button" size="sm" variant="outline" as-child><Link href="/admin/catalog">Kelola di Produk</Link></Button>
                </div>
            </form>

            <div class="mt-4 overflow-x-auto overscroll-x-contain" role="region" aria-label="Nominal produk yang terhubung ke penyedia" tabindex="0">
                <Table class="min-w-[950px] table-fixed">
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[20%]">Produk</TableHead>
                            <TableHead class="w-[18%]">Nominal</TableHead>
                            <TableHead class="w-[14%]">Provider & SKU</TableHead>
                            <TableHead class="w-[14%] text-right">Modal / Batas</TableHead>
                            <TableHead class="w-[14%]">Update Harga</TableHead>
                            <TableHead class="w-[10%] text-right">Sumber</TableHead>
                            <TableHead class="w-[10%] text-right">Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in mappings.data" :key="row.id">
                            <TableCell class="align-top">
                                <div class="font-medium text-xs">{{ row.product_name }}</div>
                                <div class="text-[11px] text-muted-foreground">{{ row.category_name }}</div>
                            </TableCell>
                            <TableCell class="align-top">
                                <div class="text-xs">{{ row.package_name }}</div>
                                <div class="text-[11px] text-muted-foreground">{{ row.package_code }}</div>
                            </TableCell>
                            <TableCell class="align-top">
                                <div class="text-xs font-semibold">{{ row.provider_name || row.provider_code }}</div>
                                <div class="font-mono text-[11px] text-primary">{{ row.external_sku || '—' }}</div>
                                <div v-if="!row.provider_active" class="text-[10px] text-destructive">Nonaktif</div>
                            </TableCell>
                            <TableCell class="align-top text-right">
                                <strong class="text-xs">{{ money(row.cost_idr) }}</strong>
                                <p class="text-[10px] text-muted-foreground">Maks: {{ money(row.max_price_idr) }}</p>
                            </TableCell>
                            <TableCell class="align-top text-xs">
                                <span class="text-[11px]">{{ row.provider_code === 'DIGIFLAZZ' && row.is_manual_price ? 'Manual' : 'Otomatis' }}</span>
                            </TableCell>
                            <TableCell class="align-top text-right text-xs">
                                <span>{{ row.source_position ? 'Posisi ' + row.source_position : '—' }}</span>
                            </TableCell>
                            <TableCell class="align-top text-right">
                                <Badge :variant="row.is_active && row.product_active && row.package_active && row.provider_active ? 'secondary' : 'outline'" class="text-[10px]">
                                    {{ row.is_active ? 'Aktif' : 'Off' }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!mappings.data?.length">
                            <TableCell colspan="7" class="py-10 text-center text-sm text-muted-foreground">Belum ada nominal produk yang sesuai dengan filter.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="mappings.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                <Link
                    v-for="link in mappings.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    preserve-scroll
                    :class="[
                        'rounded-md border px-3 py-1.5 text-sm',
                        link.active ? 'bg-primary text-primary-foreground' : 'bg-background',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                >{{ link.label }}</Link>
            </div>
        </Card>
    </div>
</AdminShell>
</template>
