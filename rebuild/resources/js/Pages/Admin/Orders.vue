<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Card } from '../../Components/ui/card';
import { Badge } from '../../Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';

const props = defineProps({ orders: Object, filters: Object, metrics: Array, statuses: Object, providers: Array, channels: Array, activities: Array, canCreateManual: Boolean, updatedAt: String });
const filters = ref({ q: '', status: '', provider: '', payment: '', from: '', to: '', per_page: 25, ...props.filters });
const selected = ref([]);
const auto = ref(true);
const busy = ref(false);
const manualOpen = ref(false);
const notice = ref('');
const manual = useForm({ customer_name: '', phone: '', email: '', product_name: '', package_name: '', destination: '', total_idr: '', note: '', payment_received: false, idempotency_key: '' });
const money = value => 'Rp' + Number(value).toLocaleString('id-ID');
const date = value => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value)) + ' WIB' : 'Belum tersedia';
const allSelected = computed(() => props.orders.data.length > 0 && props.orders.data.every(row => selected.value.includes(row.id)));
function toggleAll(event) { selected.value = event.target.checked ? props.orders.data.map(row => row.id) : []; }
function apply() {
    busy.value = true;
    router.get('/admin/orders', Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value !== '')), {
        preserveState: true, preserveScroll: true,
        onSuccess: () => { selected.value = []; },
        onFinish: () => { busy.value = false; },
    });
}
function reset() { filters.value = { q: '', status: '', provider: '', payment: '', from: '', to: '', per_page: 25 }; apply(); }
function refresh() {
    if (busy.value) return;
    busy.value = true;
    router.reload({ preserveScroll: true, onFinish: () => { busy.value = false; } });
}
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries(props.filters).forEach(([key, value]) => { if (value !== '' && value != null) params.set(key, value); });
    return '/admin/orders/export?' + params.toString();
});
function exportSelected() {
    if (!selected.value.length) return;
    const params = new URLSearchParams(exportUrl.value.split('?')[1]);
    selected.value.forEach(id => params.append('selected[]', id));
    window.location.assign('/admin/orders/export?' + params.toString());
}
function openManual() {
    manual.reset(); manual.clearErrors(); manual.idempotency_key = crypto.randomUUID(); manualOpen.value = true;
}
function createManual() { manual.post('/admin/orders/manual', { onSuccess: () => { manualOpen.value = false; } }); }
async function copy(value) {
    try { await navigator.clipboard.writeText(value); notice.value = 'Nomor pesanan disalin.'; }
    catch { notice.value = 'Nomor belum dapat disalin. Pilih dan salin nomor pesanan secara langsung.'; }
}
let timer;
onMounted(() => { timer = setInterval(() => {
    const changed = ['q','status','provider','payment','from','to','per_page'].some(key => String(filters.value[key] ?? '') !== String(props.filters[key] ?? (key === 'per_page' ? 25 : '')));
    if (auto.value && !busy.value && !manualOpen.value && !selected.value.length && !changed && document.visibilityState === 'visible') refresh();
}, 30000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
<Head title="Pesanan" />
<AdminShell>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h1 class="text-2xl font-semibold">Pesanan</h1><p class="mt-1 text-sm text-muted-foreground">Pantau pembayaran, tujuan, dan penyelesaian pesanan.</p></div>
        <Button v-if="canCreateManual" @click="openManual">Catat pesanan manual</Button>
    </div>
    <p v-if="notice" role="status" class="mt-3 text-sm">{{ notice }}</p>
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
        <Card v-for="metric in metrics" :key="metric.label" class="min-w-0 p-4"><p class="text-sm text-muted-foreground">{{ metric.label }}</p><p class="mt-2 text-2xl font-semibold">{{ Number(metric.value).toLocaleString('id-ID') }}</p></Card>
    </div>
    <Card class="mt-5 p-4">
        <form @submit.prevent="apply" class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label class="space-y-1 sm:col-span-2"><span class="text-sm font-medium">Cari pesanan</span><Input v-model="filters.q" placeholder="Nomor pesanan, pelanggan, produk, atau tujuan" maxlength="150" /></label>
            <label class="space-y-1"><span class="text-sm font-medium">Status pesanan</span><select v-model="filters.status" class="order-select"><option value="">Semua status</option><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option><option value="attention">Perlu perhatian</option></select></label>
            <label class="space-y-1"><span class="text-sm font-medium">Penyedia</span><select v-model="filters.provider" class="order-select"><option value="">Semua penyedia</option><option v-for="provider in providers" :key="provider" :value="provider">{{ provider === 'MANUAL' ? 'Penanganan manual' : provider.charAt(0) + provider.slice(1).toLowerCase() }}</option></select></label>
            <label class="space-y-1"><span class="text-sm font-medium">Metode pembayaran</span><select v-model="filters.payment" class="order-select"><option value="">Semua metode</option><option v-for="channel in channels" :key="channel.code" :value="channel.code">{{ channel.name }}</option><option v-if="!channels.some(c => c.code === 'WALLET')" value="WALLET">Saldo akun</option><option value="ADMIN_MANUAL">Pembayaran dicatat admin</option></select></label>
            <label class="space-y-1"><span class="text-sm font-medium">Dari tanggal (WIB)</span><Input v-model="filters.from" type="date" /></label>
            <label class="space-y-1"><span class="text-sm font-medium">Sampai tanggal (WIB)</span><Input v-model="filters.to" type="date" :min="filters.from || undefined" /></label>
            <label class="space-y-1"><span class="text-sm font-medium">Pesanan per halaman</span><select v-model="filters.per_page" class="order-select"><option v-for="size in [10,25,50,100]" :key="size" :value="size">{{ size }} pesanan</option></select></label>
            <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-4"><Button type="submit" :disabled="busy">Terapkan filter</Button><Button type="button" variant="outline" @click="reset" :disabled="busy">Hapus filter</Button></div>
        </form>
        <p v-for="(error,key) in $page.props.errors" :key="key" role="alert" class="mt-2 text-sm text-destructive">{{ error }}</p>
        <p class="mt-3 text-xs text-muted-foreground">Ringkasan mengikuti filter yang diterapkan. Tanggal menggunakan waktu Indonesia Barat.</p>
    </Card>
    <div class="my-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3"><Button variant="outline" @click="refresh" :disabled="busy">{{ busy ? 'Memuat…' : 'Muat ulang' }}</Button><label class="flex items-center gap-2 text-sm"><input v-model="auto" type="checkbox" class="size-4" />Perbarui otomatis setiap 30 detik</label></div>
        <Button variant="outline" as-child><a :href="exportUrl">Unduh hasil filter</a></Button>
        <p class="w-full text-xs text-muted-foreground">Terakhir diperbarui: {{ date(updatedAt) }}. Pembaruan otomatis berhenti sementara saat formulir diisi atau pesanan dipilih.</p>
    </div>
    <Card class="min-w-0 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="allSelected" @change="toggleAll" :disabled="!orders.data.length" class="size-4" />Pilih pesanan di halaman ini</label><div class="flex items-center gap-2"><span class="text-sm">{{ selected.length }} dipilih</span><Button variant="outline" size="sm" @click="exportSelected" :disabled="!selected.length">Unduh yang dipilih</Button></div></div>
        <div class="hidden md:block">
            <Table class="min-w-[1000px] table-fixed">
                <TableHeader><TableRow><TableHead class="w-[5%]">Pilih</TableHead><TableHead class="w-[17%]">Pesanan</TableHead><TableHead class="w-[16%]">Pelanggan</TableHead><TableHead class="w-[18%]">Produk dan tujuan</TableHead><TableHead class="w-[16%]">Pembayaran</TableHead><TableHead class="w-[18%]">Status</TableHead><TableHead class="w-[10%]">Detail</TableHead></TableRow></TableHeader>
                <TableBody><TableRow v-for="row in orders.data" :key="row.id">
                    <TableCell><input v-model="selected" :value="row.id" type="checkbox" :aria-label="'Pilih ' + row.order_number" class="size-4" /></TableCell>
                    <TableCell class="break-all"><strong class="font-medium">{{ row.order_number }}</strong><p class="mt-1 text-xs text-muted-foreground">{{ date(row.created_at) }}</p><Button variant="ghost" size="sm" @click="copy(row.order_number)">Salin nomor</Button></TableCell>
                    <TableCell class="break-words">{{ row.buyer_name }}<p class="mt-1 text-xs text-muted-foreground break-all">{{ row.buyer_phone || row.buyer_email || 'Kontak belum tersedia' }}</p></TableCell>
                    <TableCell class="break-words"><strong class="font-medium">{{ row.product_name }}</strong><p class="text-sm">{{ row.package_name }}</p><p v-for="(target,index) in row.destinations" :key="index" class="mt-1 text-xs text-muted-foreground break-all">{{ target.label }}: {{ target.value }}</p><p v-if="row.nickname" class="text-xs">Nama akun: {{ row.nickname }}</p></TableCell>
                    <TableCell class="break-words"><strong>{{ money(row.total_idr) }}</strong><p class="text-xs">{{ row.payment_method }}</p><p class="mt-1 text-xs text-muted-foreground">{{ row.provider }}</p></TableCell>
                    <TableCell class="break-words"><Badge variant="secondary" class="whitespace-normal">{{ row.status_label }}</Badge><p v-if="row.needs_attention" class="mt-2 text-xs text-amber-700">Perlu perhatian</p></TableCell>
                    <TableCell><Button variant="outline" size="sm" as-child><Link :href="'/admin/orders/'+row.id">Lihat</Link></Button></TableCell>
                </TableRow></TableBody>
            </Table>
        </div>
        <div class="divide-y md:hidden"><article v-for="row in orders.data" :key="row.id" class="min-w-0 space-y-3 p-4">
            <div class="flex items-start justify-between gap-3"><label class="flex min-w-0 items-start gap-2"><input v-model="selected" type="checkbox" :value="row.id" :aria-label="'Pilih '+row.order_number" class="mt-1 size-4 shrink-0" /><span class="break-all text-sm font-semibold">{{ row.order_number }}</span></label><Badge variant="secondary" class="shrink-0 max-w-[45%] whitespace-normal">{{ row.status_label }}</Badge></div>
            <p class="text-xs text-muted-foreground">{{ date(row.created_at) }}</p><div class="break-words"><p class="font-medium">{{ row.product_name }}</p><p class="text-sm">{{ row.package_name }}</p></div>
            <p class="break-words text-sm">{{ row.buyer_name }}<span v-if="row.buyer_phone" class="block">{{ row.buyer_phone }}</span></p>
            <p v-for="(target,index) in row.destinations" :key="index" class="break-all text-sm">{{ target.label }}: {{ target.value }}</p><p v-if="row.nickname" class="text-sm">Nama akun: {{ row.nickname }}</p>
            <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-semibold">{{ money(row.total_idr) }}</p><p class="text-xs">{{ row.payment_method }} · {{ row.provider }}</p><p v-if="row.needs_attention" class="mt-1 text-xs text-amber-700">Perlu perhatian</p></div><div class="flex gap-2"><Button size="sm" variant="ghost" @click="copy(row.order_number)">Salin</Button><Button size="sm" variant="outline" as-child><Link :href="'/admin/orders/'+row.id">Lihat detail</Link></Button></div></div>
        </article></div>
        <p v-if="!orders.data.length" class="p-8 text-center text-sm text-muted-foreground">Tidak ada pesanan yang sesuai. Coba ubah atau hapus filter.</p>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4"><p class="text-sm text-muted-foreground">{{ orders.from || 0 }}–{{ orders.to || 0 }} dari {{ orders.total }} pesanan · Halaman {{ orders.current_page }} dari {{ orders.last_page }}</p><div class="flex gap-2"><Button variant="outline" size="sm" :disabled="!orders.prev_page_url || busy" @click="router.get(orders.prev_page_url, {}, { preserveState:true, preserveScroll:true, onSuccess:()=>selected=[] })">Sebelumnya</Button><Button variant="outline" size="sm" :disabled="!orders.next_page_url || busy" @click="router.get(orders.next_page_url, {}, { preserveState:true, preserveScroll:true, onSuccess:()=>selected=[] })">Berikutnya</Button></div></div>
    </Card>
    <Card class="mt-5 p-4"><h2 class="font-semibold">Aktivitas pesanan terbaru</h2><p v-if="!activities.length" class="mt-3 text-sm text-muted-foreground">Belum ada aktivitas pesanan.</p><ol class="mt-3 max-h-80 divide-y overflow-auto"><li v-for="activity in activities" :key="activity.id" class="py-3 text-sm"><Link :href="'/admin/orders/'+activity.order_id" class="break-all font-medium underline underline-offset-4">{{ activity.order_number }}</Link><p>{{ activity.label }}</p><p class="mt-1 text-xs text-muted-foreground">{{ date(activity.created_at) }}</p></li></ol></Card>
    <Sheet v-model:open="manualOpen"><SheetContent class="w-full overflow-y-auto sm:max-w-xl"><SheetHeader><SheetTitle>Catat pesanan manual</SheetTitle><SheetDescription>Untuk pesanan khusus yang pembayarannya sudah diterima di luar pembayaran otomatis.</SheetDescription></SheetHeader>
        <form @submit.prevent="createManual" class="mt-6 space-y-4">
            <div v-for="field in [{key:'customer_name',label:'Nama pelanggan'},{key:'phone',label:'Nomor telepon / WhatsApp'},{key:'email',label:'Email (opsional)'},{key:'product_name',label:'Nama produk'},{key:'package_name',label:'Nama paket'},{key:'destination',label:'Tujuan / ID akun'}]" :key="field.key"><label :for="'manual-'+field.key" class="mb-1 block text-sm font-medium">{{ field.label }}</label><Input :id="'manual-'+field.key" v-model="manual[field.key]" :type="field.key==='email'?'email':'text'" :required="field.key!=='email'" :disabled="manual.processing" /><p v-if="manual.errors[field.key]" class="mt-1 text-sm text-destructive">{{ manual.errors[field.key] }}</p></div>
            <label class="block space-y-1"><span class="text-sm font-medium">Total pembayaran (Rp)</span><Input v-model="manual.total_idr" type="number" min="1" max="100000000" step="1" required :disabled="manual.processing" /></label><p v-if="manual.errors.total_idr" class="text-sm text-destructive">{{ manual.errors.total_idr }}</p>
            <label class="block space-y-1"><span class="text-sm font-medium">Catatan pembayaran (opsional)</span><Textarea v-model="manual.note" maxlength="1000" :disabled="manual.processing" /></label>
            <label class="flex items-start gap-2 text-sm"><input v-model="manual.payment_received" type="checkbox" required class="mt-1 size-4 shrink-0" :disabled="manual.processing" /><span>Saya sudah memastikan pembayaran sebesar {{ money(manual.total_idr || 0) }} benar-benar diterima.</span></label>
            <p v-if="manual.errors.payment_received || manual.errors.order" role="alert" class="text-sm text-destructive">{{ manual.errors.payment_received || manual.errors.order }}</p>
            <p class="text-xs text-muted-foreground">Pesanan masuk antrean penanganan manual. Formulir ini tidak menagih atau mengirim pesanan ke penyedia otomatis.</p>
            <div class="flex flex-wrap gap-2 pb-6"><Button type="submit" :disabled="manual.processing">{{ manual.processing ? 'Menyimpan…' : 'Simpan pesanan' }}</Button><Button type="button" variant="outline" @click="manualOpen=false" :disabled="manual.processing">Batal</Button></div>
        </form>
    </SheetContent></Sheet>
</AdminShell>
</template>
<style scoped>
.order-select { display:block;width:100%;height:2.5rem;border:1px solid hsl(var(--border));border-radius:calc(var(--radius) - 2px);background:transparent;padding:0 .75rem;font-size:.875rem; }
</style>
