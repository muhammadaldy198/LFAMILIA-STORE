<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Card } from '../../Components/ui/card';
import { Badge } from '../../Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';

const props = defineProps({
    attempts: Object,
    filters: Object,
    metrics: Array,
    statuses: Object,
    updatedAt: String,
});

const filters = reactive({
    q: props.filters?.q || '',
    scope: props.filters?.scope || 'action',
    status: props.filters?.status || '',
    per_page: props.filters?.per_page || 25,
});
const selected = ref(null);
const form = reactive({ delivery_code: '', note: '', reason: '' });
const busy = ref(false);
const auto = ref(true);

const date = value => value
    ? new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value)) + ' WIB'
    : 'Belum tersedia';
const money = value => value == null ? '-' : 'Rp' + Number(value).toLocaleString('id-ID');
const hasActions = computed(() => selected.value && (
    selected.value.can_complete
    || selected.value.can_fail
    || selected.value.can_reconcile
    || selected.value.can_retry
));

function apply() {
    busy.value = true;
    router.get('/admin/fulfillment', {
        q: filters.q || undefined,
        scope: filters.scope || undefined,
        status: filters.status || undefined,
        per_page: filters.per_page,
    }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}

function reset() {
    filters.q = '';
    filters.scope = 'action';
    filters.status = '';
    filters.per_page = 25;
    apply();
}

function refresh() {
    if (busy.value || selected.value) return;
    busy.value = true;
    router.reload({
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}

function openAttempt(attempt) {
    selected.value = attempt;
    form.delivery_code = '';
    form.note = '';
    form.reason = '';
}

function closeAttempt() {
    if (!busy.value) selected.value = null;
}

function completeManual() {
    if (!selected.value || busy.value) return;
    busy.value = true;
    router.post('/admin/fulfillment/' + selected.value.id + '/complete', {
        delivery_code: form.delivery_code || null,
        note: form.note || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = null; },
        onFinish: () => { busy.value = false; },
    });
}

function failManual() {
    if (!selected.value || busy.value || !form.reason.trim()) return;
    busy.value = true;
    router.post('/admin/fulfillment/' + selected.value.id + '/fail', {
        reason: form.reason,
    }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = null; },
        onFinish: () => { busy.value = false; },
    });
}

function reconcile() {
    if (!selected.value || busy.value) return;
    busy.value = true;
    router.post('/admin/fulfillment/' + selected.value.id + '/reconcile', {}, {
        preserveScroll: true,
        onSuccess: () => { selected.value = null; },
        onFinish: () => { busy.value = false; },
    });
}

function retry() {
    if (!selected.value || busy.value) return;
    busy.value = true;
    router.post('/admin/fulfillment/' + selected.value.id + '/retry', {}, {
        preserveScroll: true,
        onSuccess: () => { selected.value = null; },
        onFinish: () => { busy.value = false; },
    });
}

let timer;
onMounted(() => {
    timer = setInterval(() => {
        const unchanged = String(filters.q || '') === String(props.filters?.q || '')
            && String(filters.scope || '') === String(props.filters?.scope || '')
            && String(filters.status || '') === String(props.filters?.status || '')
            && String(filters.per_page || 25) === String(props.filters?.per_page || 25);
        if (auto.value && unchanged && !busy.value && !selected.value && document.visibilityState === 'visible') {
            refresh();
        }
    }, 30000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
<Head title="Manual" />
<AdminShell>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Manual</h1>
            <p class="mt-1 text-sm text-muted-foreground">Antrean penanganan pesanan yang membutuhkan tindakan atau pemeriksaan Admin.</p>
        </div>
        <Button variant="outline" as-child><Link href="/admin/orders">Buka Pesanan</Link></Button>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card v-for="metric in metrics" :key="metric.label" class="min-w-0 p-4">
            <p class="text-sm text-muted-foreground">{{ metric.label }}</p>
            <p class="mt-2 text-2xl font-semibold">{{ Number(metric.value).toLocaleString('id-ID') }}</p>
        </Card>
    </div>

    <Card class="mt-5 p-4">
        <form class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="apply">
            <label class="space-y-1 sm:col-span-2">
                <span class="text-sm font-medium">Cari antrean</span>
                <Input v-model="filters.q" maxlength="150" placeholder="Nomor pesanan, pelanggan, produk, atau tujuan" />
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">Tampilan</span>
                <select v-model="filters.scope" class="manual-select">
                    <option value="action">Perlu tindakan</option>
                    <option value="manual">Penanganan manual</option>
                    <option value="all">Semua proses terbaru</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">Status proses</span>
                <select v-model="filters.status" class="manual-select">
                    <option value="">Semua status</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">Baris per halaman</span>
                <select v-model="filters.per_page" class="manual-select">
                    <option v-for="size in [10,25,50,100]" :key="size" :value="size">{{ size }} pesanan</option>
                </select>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-3">
                <Button type="submit" :disabled="busy">{{ busy ? 'Memuat…' : 'Terapkan filter' }}</Button>
                <Button type="button" variant="outline" :disabled="busy" @click="reset">Hapus filter</Button>
            </div>
        </form>
        <p class="mt-3 text-xs text-muted-foreground">Halaman ini hanya menampilkan proses terbaru setiap pesanan. Riwayat lengkap tetap tersedia di menu Pesanan.</p>
    </Card>

    <div class="my-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="outline" :disabled="busy || selected" @click="refresh">{{ busy ? 'Memuat…' : 'Muat ulang' }}</Button>
            <label class="flex items-center gap-2 text-sm"><input v-model="auto" type="checkbox" class="size-4" />Perbarui otomatis setiap 30 detik</label>
        </div>
        <p class="text-xs text-muted-foreground">Terakhir diperbarui: {{ date(updatedAt) }}</p>
    </div>

    <Card class="min-w-0 overflow-hidden">
        <div class="hidden md:block">
            <Table class="min-w-[980px] table-fixed">
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[18%]">Pesanan</TableHead>
                        <TableHead class="w-[20%]">Pelanggan</TableHead>
                        <TableHead class="w-[22%]">Produk dan tujuan</TableHead>
                        <TableHead class="w-[14%]">Sumber</TableHead>
                        <TableHead class="w-[16%]">Status</TableHead>
                        <TableHead class="w-[10%]">Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="attempt in attempts.data" :key="attempt.id">
                        <TableCell class="align-top break-all">
                            <strong class="font-medium">{{ attempt.order_number }}</strong>
                            <p class="mt-1 text-xs text-muted-foreground">{{ date(attempt.created_at) }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Proses ke-{{ attempt.attempt_no }}</p>
                        </TableCell>
                        <TableCell class="align-top break-words">
                            <strong class="font-medium">{{ attempt.buyer_name }}</strong>
                            <p v-if="attempt.buyer_phone" class="mt-1 text-xs">{{ attempt.buyer_phone }}</p>
                            <p v-if="attempt.buyer_email" class="mt-1 break-all text-xs text-muted-foreground">{{ attempt.buyer_email }}</p>
                        </TableCell>
                        <TableCell class="align-top break-words">
                            <strong class="font-medium">{{ attempt.product_name }}</strong>
                            <p class="text-sm">{{ attempt.package_name }}</p>
                            <p v-for="(target,index) in attempt.destinations" :key="index" class="mt-1 break-all text-xs text-muted-foreground">{{ target.label }}: {{ target.value }}</p>
                        </TableCell>
                        <TableCell class="align-top break-words">
                            <p>{{ attempt.source }}</p>
                            <p v-if="attempt.price_idr != null" class="mt-1 text-xs text-muted-foreground">Biaya sumber {{ money(attempt.price_idr) }}</p>
                        </TableCell>
                        <TableCell class="align-top">
                            <Badge variant="secondary" class="whitespace-normal">{{ attempt.status_label }}</Badge>
                            <p class="mt-2 text-xs text-muted-foreground">Pesanan: {{ attempt.order_status_label }}</p>
                            <p v-if="attempt.last_error" class="mt-2 text-xs text-amber-700">{{ attempt.last_error }}</p>
                        </TableCell>
                        <TableCell class="align-top">
                            <Button size="sm" variant="outline" @click="openAttempt(attempt)">{{ attempt.can_complete || attempt.can_fail || attempt.can_reconcile || attempt.can_retry ? 'Tangani' : 'Lihat' }}</Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div class="divide-y md:hidden">
            <article v-for="attempt in attempts.data" :key="attempt.id" class="min-w-0 space-y-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0"><strong class="block break-all text-sm">{{ attempt.order_number }}</strong><p class="mt-1 text-xs text-muted-foreground">{{ date(attempt.created_at) }}</p></div>
                    <Badge variant="secondary" class="max-w-[48%] shrink-0 whitespace-normal">{{ attempt.status_label }}</Badge>
                </div>
                <div><p class="font-medium">{{ attempt.product_name }}</p><p class="text-sm">{{ attempt.package_name }}</p></div>
                <p class="text-sm">{{ attempt.buyer_name }}<span v-if="attempt.buyer_phone" class="block">{{ attempt.buyer_phone }}</span></p>
                <p v-for="(target,index) in attempt.destinations" :key="index" class="break-all text-sm">{{ target.label }}: {{ target.value }}</p>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div><p class="text-sm">{{ attempt.source }}</p><p class="text-xs text-muted-foreground">Pesanan: {{ attempt.order_status_label }}</p><p v-if="attempt.last_error" class="mt-1 text-xs text-amber-700">{{ attempt.last_error }}</p></div>
                    <Button size="sm" variant="outline" @click="openAttempt(attempt)">{{ attempt.can_complete || attempt.can_fail || attempt.can_reconcile || attempt.can_retry ? 'Tangani' : 'Lihat' }}</Button>
                </div>
            </article>
        </div>

        <p v-if="!attempts.data.length" class="p-8 text-center text-sm text-muted-foreground">Tidak ada antrean yang sesuai dengan filter.</p>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4">
            <p class="text-sm text-muted-foreground">{{ attempts.from || 0 }}–{{ attempts.to || 0 }} dari {{ attempts.total }} proses · Halaman {{ attempts.current_page }} dari {{ attempts.last_page }}</p>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" :disabled="!attempts.prev_page_url || busy" @click="router.get(attempts.prev_page_url, {}, { preserveState:true, preserveScroll:true })">Sebelumnya</Button>
                <Button variant="outline" size="sm" :disabled="!attempts.next_page_url || busy" @click="router.get(attempts.next_page_url, {}, { preserveState:true, preserveScroll:true })">Berikutnya</Button>
            </div>
        </div>
    </Card>

    <Sheet :open="Boolean(selected)" @update:open="value => { if (!value) closeAttempt(); }">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <template v-if="selected">
                <SheetHeader>
                    <SheetTitle>Penanganan {{ selected.order_number }}</SheetTitle>
                    <SheetDescription>{{ selected.status_label }} · {{ selected.source }}</SheetDescription>
                </SheetHeader>

                <div class="mt-6 space-y-5 pb-8">
                    <Card class="space-y-3 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-semibold">{{ selected.product_name }}</p><p class="text-sm text-muted-foreground">{{ selected.package_name }}</p></div><Badge variant="secondary">{{ selected.order_status_label }}</Badge></div>
                        <div class="text-sm"><p class="font-medium">{{ selected.buyer_name }}</p><p v-if="selected.buyer_phone">{{ selected.buyer_phone }}</p><p v-if="selected.buyer_email" class="break-all text-muted-foreground">{{ selected.buyer_email }}</p></div>
                        <div v-if="selected.destinations.length" class="space-y-1 border-t pt-3 text-sm"><p v-for="(target,index) in selected.destinations" :key="index" class="break-all"><span class="text-muted-foreground">{{ target.label }}:</span> {{ target.value }}</p></div>
                        <Button variant="outline" size="sm" as-child><Link :href="'/admin/orders/'+selected.order_id">Lihat detail pesanan</Link></Button>
                    </Card>

                    <Card v-if="selected.manual_instructions" class="p-4">
                        <h3 class="font-semibold">Instruksi penanganan</h3>
                        <p class="mt-2 whitespace-pre-wrap text-sm">{{ selected.manual_instructions }}</p>
                    </Card>

                    <Card class="space-y-2 p-4">
                        <h3 class="font-semibold">Status proses</h3>
                        <p class="text-sm"><span class="text-muted-foreground">Status:</span> {{ selected.status_label }}</p>
                        <p class="text-sm"><span class="text-muted-foreground">Sumber:</span> {{ selected.source }}</p>
                        <p class="break-all text-sm"><span class="text-muted-foreground">Referensi proses:</span> {{ selected.process_reference }}</p>
                        <p v-if="selected.provider_status" class="text-sm"><span class="text-muted-foreground">Status sumber:</span> {{ selected.provider_status }}</p>
                        <p v-if="selected.response_code" class="text-sm"><span class="text-muted-foreground">Kode respons:</span> {{ selected.response_code }}</p>
                        <p class="text-sm"><span class="text-muted-foreground">Terakhir diperiksa:</span> {{ date(selected.last_checked_at) }}</p>
                        <p v-if="selected.last_error" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ selected.last_error }}</p>
                    </Card>

                    <Card v-if="selected.delivery && Object.keys(selected.delivery).length" class="p-4">
                        <h3 class="font-semibold">Hasil tersimpan</h3>
                        <p v-if="selected.delivery.code" class="mt-2 break-all text-sm"><span class="text-muted-foreground">Kode / hasil:</span> {{ selected.delivery.code }}</p>
                        <p v-if="selected.delivery.serial_number" class="mt-2 break-all text-sm"><span class="text-muted-foreground">Serial:</span> {{ selected.delivery.serial_number }}</p>
                        <p v-if="selected.delivery.note" class="mt-2 whitespace-pre-wrap text-sm"><span class="text-muted-foreground">Catatan:</span> {{ selected.delivery.note }}</p>
                    </Card>

                    <Card v-if="selected.can_complete || selected.can_fail" class="space-y-4 p-4">
                        <div><h3 class="font-semibold">Selesaikan manual</h3><p class="mt-1 text-xs text-muted-foreground">Isi hasil yang memang perlu diterima pelanggan. Untuk top up langsung ke akun, kolom kode boleh kosong.</p></div>
                        <label class="block space-y-1"><span class="text-sm font-medium">Kode / hasil untuk pelanggan</span><Textarea v-model="form.delivery_code" rows="3" maxlength="2000" /></label>
                        <label class="block space-y-1"><span class="text-sm font-medium">Catatan untuk pelanggan</span><Textarea v-model="form.note" rows="3" maxlength="4000" /></label>
                        <Button :disabled="busy" @click="completeManual">{{ busy ? 'Memproses…' : 'Tandai berhasil' }}</Button>
                        <div class="border-t pt-4">
                            <label class="block space-y-1"><span class="text-sm font-medium">Alasan gagal</span><Textarea v-model="form.reason" rows="3" maxlength="4000" placeholder="Jelaskan alasan pesanan tidak dapat diselesaikan" /></label>
                            <Button class="mt-3" variant="destructive" :disabled="busy || !form.reason.trim()" @click="failManual">Tandai gagal</Button>
                        </div>
                    </Card>

                    <Card v-if="selected.can_reconcile" class="space-y-3 p-4">
                        <div><h3 class="font-semibold">Periksa status</h3><p class="mt-1 text-sm text-muted-foreground">Sistem akan memeriksa transaksi yang sama dengan referensi yang sama. Tidak membuat transaksi baru.</p></div>
                        <Button variant="outline" :disabled="busy" @click="reconcile">{{ busy ? 'Memproses…' : 'Periksa status sekarang' }}</Button>
                    </Card>

                    <Card v-if="selected.can_retry" class="space-y-3 p-4">
                        <div><h3 class="font-semibold">Proses ulang aman</h3><p class="mt-1 text-sm text-muted-foreground">Tindakan ini hanya tersedia jika sistem sudah memastikan tidak ada proses lain yang sedang berjalan.</p></div>
                        <Button :disabled="busy" @click="retry">{{ busy ? 'Memproses…' : selected.retry_label }}</Button>
                    </Card>

                    <p v-if="!hasActions" class="rounded-md border p-4 text-sm text-muted-foreground">Tidak ada tindakan operasional yang tersedia untuk status ini.</p>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</AdminShell>
</template>

<style scoped>
.manual-select {
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
