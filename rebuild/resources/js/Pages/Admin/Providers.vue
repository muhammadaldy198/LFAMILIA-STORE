<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    mappings: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const rows = ref(props.providers.map((row) => ({ ...row })));
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
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Provider</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Aktifkan penyedia layanan, atur nama dan urutannya, lalu pantau nominal yang terhubung. Kredensial API tetap dikelola di menu Integrasi.
                </p>
            </div>
            <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <div class="lf-admin-summary ">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Provider aktif</p>
                <p class="mt-2 text-2xl font-semibold">{{ summary.provider_active || 0 }}</p>
                <p class="mt-1 text-xs text-muted-foreground">dari {{ summary.provider_total || 0 }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Mapping aktif</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary.mapping_active || 0).toLocaleString('id-ID') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">siap dipilih sistem</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Total mapping</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary.mapping_total || 0).toLocaleString('id-ID') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">seluruh nominal</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Perlu diperiksa</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary.needs_reconciliation || 0).toLocaleString('id-ID') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">pending / belum pasti</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Boleh dialihkan</p>
                <p class="mt-2 text-2xl font-semibold">{{ Number(summary.safe_to_failover || 0).toLocaleString('id-ID') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">gagal sudah terkonfirmasi</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Aturan pengalihan</p>
                <p class="mt-2 font-semibold">Aman saja</p>
                <p class="mt-1 text-xs text-muted-foreground">pending tidak pernah dialihkan otomatis</p>
            </Card>
        </div>

        <section>
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Daftar provider</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Nama tampilan, keterangan, urutan, dan status dapat diubah. Kode sistem tetap dikunci agar proses transaksi tidak rusak.</p>
                </div>
                <Button variant="outline" size="sm" as-child><Link href="/admin/integrations">Buka Integrasi</Link></Button>
            </div>

            <div class="grid gap-3 xl:grid-cols-3">
                <Card v-for="row in rows" :key="row.id" class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <strong class="truncate">{{ row.display_name }}</strong>
                                <Badge :variant="healthVariant(row.health?.status)">{{ healthLabel(row.health?.status) }}</Badge>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ modeLabel(row.fulfillment_mode) }} · Kode sistem {{ row.code }}
                            </p>
                        </div>
                        <label class="flex shrink-0 items-center gap-2 text-sm">
                            <AdminSwitch v-model="row.is_active" />
                            Aktif
                        </label>
                    </div>

                    <div class="mt-4 grid gap-3">
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Nama tampilan</span>
                            <Input v-model="row.display_name" maxlength="80" />
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Keterangan</span>
                            <textarea
                                v-model="row.description"
                                maxlength="500"
                                rows="3"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            />
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Urutan tampilan</span>
                            <Input v-model.number="row.sort_order" type="number" min="0" max="9999" />
                        </label>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 rounded-md border p-3 text-sm">
                        <div>
                            <p class="text-xs text-muted-foreground">Mapping</p>
                            <p class="mt-1 font-medium">{{ row.active_mapping_count }}/{{ row.mapping_count }} aktif</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Berhasil 24 jam</p>
                            <p class="mt-1 font-medium">{{ Number(row.success_24h || 0).toLocaleString('id-ID') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Perlu diperiksa</p>
                            <p class="mt-1 font-medium">{{ Number(row.attention_count || 0).toLocaleString('id-ID') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Proses terakhir</p>
                            <p class="mt-1 text-xs font-medium">{{ date(row.last_attempt_at) }}</p>
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-muted-foreground">{{ row.health?.message }}</p>
                    <div class="mt-4 flex justify-end">
                        <Button size="sm" :disabled="saving === row.id" @click="saveProvider(row)">
                            {{ saving === row.id ? 'Menyimpan…' : 'Simpan provider' }}
                        </Button>
                    </div>
                </Card>
            </div>
        </section>

        <Card class="p-4">
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Nominal terhubung</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Daftar ini untuk memantau hubungan nominal ke provider. Perubahan SKU, harga, margin, prioritas, dan pengaturan nominal tetap dilakukan dari menu Produk agar tidak ada pengaturan ganda.
                </p>
            </div>

            <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="search">
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
                    <span class="text-sm font-medium">Status mapping</span>
                    <select v-model="filters.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Baris</span>
                    <select v-model.number="filters.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
                    <Button type="submit" size="sm">Terapkan</Button>
                    <Button type="button" size="sm" variant="outline" @click="reset">Reset</Button>
                    <Button type="button" size="sm" variant="outline" as-child><Link href="/admin/catalog">Kelola di Produk</Link></Button>
                </div>
            </form>

            <div class="mt-4 overflow-x-auto">
                <AdminResponsiveTable :mobile-columns="[0,1,4,7]">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Produk</TableHead>
                            <TableHead>Nominal</TableHead>
                            <TableHead>Provider</TableHead>
                            <TableHead>SKU</TableHead>
                            <TableHead class="text-right">Harga modal</TableHead>
                            <TableHead class="text-right">Batas harga</TableHead>
                            <TableHead class="text-right">Prioritas</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in mappings.data" :key="row.id">
                            <TableCell>
                                <div class="font-medium">{{ row.product_name }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.category_name }}</div>
                            </TableCell>
                            <TableCell>
                                <div>{{ row.package_name }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.package_code }}</div>
                            </TableCell>
                            <TableCell>
                                <div>{{ row.provider_name || row.provider_code }}</div>
                                <div v-if="!row.provider_active" class="text-xs text-amber-600">Provider nonaktif</div>
                            </TableCell>
                            <TableCell class="font-mono text-xs">{{ row.external_sku || '—' }}</TableCell>
                            <TableCell class="text-right">{{ money(row.cost_idr) }}</TableCell>
                            <TableCell class="text-right">{{ money(row.max_price_idr) }}</TableCell>
                            <TableCell class="text-right">{{ row.priority }}</TableCell>
                            <TableCell>
                                <Badge :variant="row.is_active && row.product_active && row.package_active && row.provider_active ? 'secondary' : 'outline'">
                                    {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!mappings.data?.length">
                            <TableCell colspan="8" class="py-10 text-center text-muted-foreground">Belum ada mapping yang sesuai filter.</TableCell>
                        </TableRow>
                    </TableBody>
                </AdminResponsiveTable>
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
                    v-html="link.label"
                />
            </div>
        </Card>
    </div>
</AdminShell>
</template>
