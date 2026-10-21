<script setup>
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
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
const pendingAction = ref(null);
const ask = (title, message, confirmLabel, run, destructive = false) => {
    pendingAction.value = { title, message, confirmLabel, run, destructive };
};
const runPending = () => {
    const action = pendingAction.value;
    pendingAction.value = null;
    action?.run();
};

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
    ask('Tandai pesanan berhasil', 'Pastikan hasil sudah benar-benar diterima pelanggan. Status pesanan akan berubah menjadi berhasil.', 'Tandai berhasil', () => {
        busy.value = true;
        router.post('/admin/fulfillment/' + selected.value.id + '/complete', {
            delivery_code: form.delivery_code || null,
            note: form.note || null,
        }, {
            preserveScroll: true,
            onSuccess: () => { selected.value = null; },
            onFinish: () => { busy.value = false; },
        });
    });
}

function failManual() {
    if (!selected.value || busy.value || !form.reason.trim()) return;
    ask('Tandai pesanan gagal', 'Pesanan akan ditandai gagal dan pelanggan melihat alasan yang kamu tulis. Dana tidak dikembalikan otomatis; pemeriksaan pembayaran dilakukan terpisah.', 'Tandai gagal', () => {
        busy.value = true;
        router.post('/admin/fulfillment/' + selected.value.id + '/fail', {
            reason: form.reason,
        }, {
            preserveScroll: true,
            onSuccess: () => { selected.value = null; },
            onFinish: () => { busy.value = false; },
        });
    }, true);
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
    ask('Lanjutkan pengiriman', 'Pastikan hasil pemeriksaan penyedia sudah ditinjau. Pesanan akan dikirim ulang ke penyedia.', 'Lanjutkan', () => {
        busy.value = true;
        router.post('/admin/fulfillment/' + selected.value.id + '/retry', {}, {
            preserveScroll: true,
            onSuccess: () => { selected.value = null; },
            onFinish: () => { busy.value = false; },
        });
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
<Head title="Antrean Pesanan" />
<AdminShell>
    <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight">Antrean Pesanan</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">Pesanan yang menunggu tindakan atau pemeriksaan Admin. Pilih satu baris untuk menangani pesanan itu tanpa berpindah halaman.</p>
        </div>
        <Button class="w-full sm:w-auto" variant="outline" as-child><Link href="/admin/orders">Buka Pesanan</Link></Button>
    </header>

    <p class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
        <span v-for="metric in metrics" :key="metric.label"><strong class="text-foreground">{{ Number(metric.value).toLocaleString('id-ID') }}</strong> {{ metric.label.toLowerCase() }}</span>
    </p>

    <form class="mt-5 grid min-w-0 items-end gap-3 border-b border-border pb-4 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="apply">
            <label class="space-y-1 sm:col-span-2">
                <span class="text-sm font-medium">Cari antrean</span>
                <Input v-model="filters.q" maxlength="150" placeholder="Nomor pesanan, pelanggan, produk, atau tujuan" />
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">Cakupan antrean</span>
                <select v-model="filters.scope" class="manual-select">
                    <option value="action">Hanya yang perlu ditangani</option>
                    <option value="manual">Hanya penanganan manual</option>
                    <option value="all">Semua proses terbaru</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">Status</span>
                <select v-model="filters.status" class="manual-select">
                    <option value="">Semua status</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">Baris per halaman</span>
                <select v-model="filters.per_page" class="manual-select">
                    <option v-for="size in [10,25,50,100]" :key="size" :value="size">{{ size }} baris</option>
                </select>
            </label>
            <div class="flex flex-col gap-2 sm:flex-row sm:col-span-2 xl:col-span-3">
                <Button type="submit" :disabled="busy">{{ busy ? 'Memuat…' : 'Terapkan filter' }}</Button>
                <Button type="button" variant="outline" :disabled="busy" @click="reset">Hapus filter</Button>
            </div>
        <p class="text-xs text-muted-foreground sm:col-span-2 xl:col-span-4">Halaman ini menampilkan proses terbaru setiap pesanan. Riwayat lengkap ada di menu Pesanan.</p>
    </form>

    <div class="my-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="outline" :disabled="busy || selected" @click="refresh">{{ busy ? 'Memuat…' : 'Muat ulang' }}</Button>
            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="auto" />Perbarui otomatis setiap 30 detik</label>
        </div>
        <p class="text-xs text-muted-foreground">Terakhir diperbarui: {{ date(updatedAt) }}</p>
    </div>

    <section class="mt-4 min-w-0">
        <div class="overflow-x-auto overscroll-x-contain rounded-md border border-border" tabindex="0" role="region" aria-label="Antrean pesanan, geser mendatar untuk melihat semua kolom">
            <Table class="min-w-[850px] table-fixed">
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[18%]">Pesanan</TableHead>
                        <TableHead class="w-[20%]">Pelanggan</TableHead>
                        <TableHead class="w-[22%]">Produk &amp; tujuan</TableHead>
                        <TableHead class="w-[16%]">Sumber</TableHead>
                        <TableHead class="w-[14%]">Status</TableHead>
                        <TableHead class="w-[10%] text-right">Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="attempt in attempts.data" :key="attempt.id" :class="{'bg-destructive/5': attempt.last_error}">
                        <TableCell class="align-top break-all">
                            <strong class="font-medium text-primary text-xs">{{ attempt.order_number }}</strong>
                            <p class="mt-0.5 text-[11px] text-muted-foreground">{{ date(attempt.created_at) }}</p>
                            <p class="text-[10px] text-muted-foreground">Ke-{{ attempt.attempt_no }}</p>
                        </TableCell>
                        <TableCell class="align-top break-words">
                            <strong class="font-medium text-xs">{{ attempt.buyer_name }}</strong>
                            <p v-if="attempt.buyer_phone" class="text-[11px] text-muted-foreground">{{ attempt.buyer_phone }}</p>
                            <p v-if="attempt.buyer_email" class="break-all text-[10px] text-muted-foreground">{{ attempt.buyer_email }}</p>
                        </TableCell>
                        <TableCell class="align-top break-words">
                            <strong class="font-medium text-xs">{{ attempt.product_name }}</strong>
                            <p class="text-[11px] text-muted-foreground">{{ attempt.package_name }}</p>
                            <p v-for="(target,index) in attempt.destinations" :key="index" class="break-all text-[10px] text-muted-foreground">{{ target.label }}: {{ target.value }}</p>
                        </TableCell>
                        <TableCell class="align-top break-words">
                            <p class="text-xs">{{ attempt.source }}</p>
                            <p v-if="attempt.price_idr != null" class="text-[10px] text-muted-foreground">Biaya {{ money(attempt.price_idr) }}</p>
                        </TableCell>
                        <TableCell class="align-top">
                            <Badge variant="secondary" class="text-[10px]">{{ attempt.status_label }}</Badge>
                            <p class="mt-1 text-[10px] text-muted-foreground">Pesanan: {{ attempt.order_status_label }}</p>
                            <p v-if="attempt.last_error" class="mt-1 text-[10px] font-medium text-destructive truncate" :title="attempt.last_error">{{ attempt.last_error }}</p>
                        </TableCell>
                        <TableCell class="align-top text-right">
                            <Button size="sm" variant="outline" class="h-7 text-xs" @click="openAttempt(attempt)">
                                {{ attempt.can_complete || attempt.can_fail || attempt.can_reconcile || attempt.can_retry ? 'Tangani' : 'Lihat' }}
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="!attempts.data.length">
                        <TableCell colspan="6" class="p-8 text-center text-sm text-muted-foreground">Tidak ada antrean yang sesuai dengan filter.</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4">
            <p class="text-sm text-muted-foreground">{{ attempts.from || 0 }}–{{ attempts.to || 0 }} dari {{ attempts.total }} proses · Halaman {{ attempts.current_page }} dari {{ attempts.last_page }}</p>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" :disabled="!attempts.prev_page_url || busy" @click="router.get(attempts.prev_page_url, {}, { preserveState:true, preserveScroll:true })">Sebelumnya</Button>
                <Button variant="outline" size="sm" :disabled="!attempts.next_page_url || busy" @click="router.get(attempts.next_page_url, {}, { preserveState:true, preserveScroll:true })">Berikutnya</Button>
            </div>
        </div>
    </section>

    <Sheet :open="Boolean(selected)" @update:open="value => { if (!value) closeAttempt(); }">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <template v-if="selected">
                <SheetHeader>
                    <SheetTitle>Penanganan {{ selected.order_number }}</SheetTitle>
                    <SheetDescription>{{ selected.status_label }} · {{ selected.source }}</SheetDescription>
                </SheetHeader>

                <div class="mt-6 space-y-5 pb-8">
                    <div class="space-y-3 border-b border-border pb-4">
                        <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-semibold">{{ selected.product_name }}</p><p class="text-sm text-muted-foreground">{{ selected.package_name }}</p></div><Badge variant="secondary">{{ selected.order_status_label }}</Badge></div>
                        <div class="text-sm"><p class="font-medium">{{ selected.buyer_name }}</p><p v-if="selected.buyer_phone">{{ selected.buyer_phone }}</p><p v-if="selected.buyer_email" class="break-all text-muted-foreground">{{ selected.buyer_email }}</p></div>
                        <div v-if="selected.destinations.length" class="space-y-1 border-t pt-3 text-sm"><p v-for="(target,index) in selected.destinations" :key="index" class="break-all"><span class="text-muted-foreground">{{ target.label }}:</span> {{ target.value }}</p></div>
                        <Button variant="outline" size="sm" as-child><Link :href="'/admin/orders/'+selected.order_id">Lihat detail pesanan</Link></Button>
                    </div>

                    <div v-if="selected.manual_instructions" class="border-b border-border pb-4">
                        <h3 class="font-semibold">Instruksi penanganan</h3>
                        <p class="mt-2 whitespace-pre-wrap text-sm">{{ selected.manual_instructions }}</p>
                    </div>

                    <div class="space-y-2 border-b border-border pb-4">
                        <h3 class="font-semibold">Status proses</h3>
                        <p class="text-sm"><span class="text-muted-foreground">Status:</span> {{ selected.status_label }}</p>
                        <p class="text-sm"><span class="text-muted-foreground">Sumber:</span> {{ selected.source }}</p>
                        <p class="text-sm"><span class="text-muted-foreground">Terakhir diperiksa:</span> {{ date(selected.last_checked_at) }}</p>
                        <details class="text-sm"><summary class="cursor-pointer">Rincian teknis (lanjutan)</summary><div class="mt-2 space-y-2"><p class="break-all"><span class="text-muted-foreground">Nomor referensi:</span> {{ selected.process_reference }}</p><p v-if="selected.provider_status"><span class="text-muted-foreground">Status dari penyedia:</span> {{ selected.provider_status }}</p><p v-if="selected.response_code"><span class="text-muted-foreground">Kode jawaban penyedia:</span> {{ selected.response_code }}</p></div></details>
                        <p v-if="selected.last_error" class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm">{{ selected.last_error }}</p>
                    </div>

                    <div v-if="selected.delivery && Object.keys(selected.delivery).length" class="border-b border-border pb-4">
                        <h3 class="font-semibold">Hasil tersimpan</h3>
                        <p v-if="selected.delivery.code" class="mt-2 break-all text-sm"><span class="text-muted-foreground">Kode / hasil:</span> {{ selected.delivery.code }}</p>
                        <p v-if="selected.delivery.serial_number" class="mt-2 break-all text-sm"><span class="text-muted-foreground">Serial:</span> {{ selected.delivery.serial_number }}</p>
                        <p v-if="selected.delivery.note" class="mt-2 whitespace-pre-wrap text-sm"><span class="text-muted-foreground">Catatan:</span> {{ selected.delivery.note }}</p>
                    </div>

                    <div v-if="selected.can_complete || selected.can_fail" class="space-y-4 border-b border-border pb-4">
                        <div><h3 class="font-semibold">Selesaikan manual</h3><p class="mt-1 text-xs text-muted-foreground">Isi hasil yang memang perlu diterima pelanggan. Untuk top up langsung ke akun, kolom kode boleh kosong.</p></div>
                        <label class="block space-y-1"><span class="text-sm font-medium">Kode / hasil untuk pelanggan</span><Textarea v-model="form.delivery_code" rows="3" maxlength="2000" /></label>
                        <label class="block space-y-1"><span class="text-sm font-medium">Catatan untuk pelanggan</span><Textarea v-model="form.note" rows="3" maxlength="4000" /></label>
                        <Button :disabled="busy" @click="completeManual">{{ busy ? 'Memproses…' : 'Tandai berhasil' }}</Button>
                        <div class="border-t pt-4">
                            <label class="block space-y-1"><span class="text-sm font-medium">Alasan gagal</span><Textarea v-model="form.reason" rows="3" maxlength="4000" placeholder="Jelaskan alasan pesanan tidak dapat diselesaikan" /></label>
                            <Button class="mt-3" variant="destructive" :disabled="busy || !form.reason.trim()" @click="failManual">Tandai gagal</Button>
                        </div>
                    </div>

                    <div v-if="selected.can_reconcile" class="space-y-3 border-b border-border pb-4">
                        <div><h3 class="font-semibold">Periksa status</h3><p class="mt-1 text-sm text-muted-foreground">Sistem akan memeriksa transaksi yang sama dengan referensi yang sama. Tidak membuat transaksi baru.</p></div>
                        <Button variant="outline" :disabled="busy" @click="reconcile">{{ busy ? 'Memproses…' : 'Periksa status sekarang' }}</Button>
                    </div>

                    <div v-if="selected.can_retry" class="space-y-3 border-b border-border pb-4">
                        <div><h3 class="font-semibold">Proses ulang aman</h3><p class="mt-1 text-sm text-muted-foreground">Tindakan ini hanya tersedia jika sistem sudah memastikan tidak ada proses lain yang sedang berjalan.</p></div>
                        <Button :disabled="busy" @click="retry">{{ busy ? 'Memproses…' : selected.retry_label }}</Button>
                    </div>

                    <p v-if="!hasActions" class="rounded-md border p-4 text-sm text-muted-foreground">Tidak ada tindakan operasional yang tersedia untuk status ini.</p>
                </div>
            </template>
        </SheetContent>
    </Sheet>

    <AdminConfirmDialog
        :open="Boolean(pendingAction)"
        :title="pendingAction?.title || ''"
        :message="pendingAction?.message || ''"
        :confirm-label="pendingAction?.confirmLabel || 'Lanjutkan'"
        :destructive="Boolean(pendingAction?.destructive)"
        @update:open="value => { if (!value) pendingAction = null; }"
        @confirm="runPending"
    />
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
