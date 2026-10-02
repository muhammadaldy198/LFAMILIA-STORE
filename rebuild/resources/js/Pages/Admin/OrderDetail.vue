<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { Card } from '../../Components/ui/card';
import { Button } from '../../Components/ui/button';
import { Badge } from '../../Components/ui/badge';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
const props = defineProps({ order: Object, events: Array, payments: Array, attempts: Array, canViewCustomer: Boolean, canCheckFulfillment: Boolean });
const notice = ref('');
const busy = ref(false);
const action = ref(null);
const actionOpen = ref(false);
const form = useForm({ delivery_code:'', note:'', reason:'', confirmed:false });
const money = value => 'Rp'+Number(value).toLocaleString('id-ID');
const date = value => value ? new Intl.DateTimeFormat('id-ID', { dateStyle:'medium', timeStyle:'short', timeZone:'Asia/Jakarta' }).format(new Date(value))+' WIB' : 'Belum tersedia';
const actions = {
    complete:{ title:'Selesaikan pesanan manual', description:'Pastikan produk atau layanan sudah diterima pelanggan. Kode dan catatan pengiriman akan disimpan pada pesanan.', suffix:'complete' },
    fail:{ title:'Nyatakan pesanan manual gagal', description:'Jelaskan alasan kegagalan. Tindakan ini tidak mengembalikan dana secara otomatis.', suffix:'fail' },
    retry:{ title:'Lanjutkan pengiriman', description:'Hanya pengiriman yang tertahan sebelum dikirim atau telah dikonfirmasi gagal yang dapat dilanjutkan. Pesanan yang belum pasti hasilnya harus diperiksa terlebih dahulu.', suffix:'retry' },
    payment:{ title:'Konfirmasi pembayaran QRIS manual', description:'Periksa mutasi rekening dan cocokkan nominal serta nomor pesanan sebelum melanjutkan.', suffix:'confirm' },
};
const heading = computed(() => action.value ? actions[action.value.type] : null);
function open(type,id) { form.reset(); form.clearErrors(); action.value={type,id}; actionOpen.value=true; }
function submit() {
    if (!form.confirmed || !action.value) return;
    const url = action.value.type==='payment' ? '/admin/payments/manual/'+action.value.id+'/confirm' : '/admin/fulfillment/'+action.value.id+'/'+heading.value.suffix;
    form.post(url, { preserveScroll:true, onSuccess:()=>{ actionOpen.value=false; notice.value='Tindakan berhasil disimpan.'; } });
}
function check(kind) {
    if (busy.value) return;
    busy.value=true;
    router.post('/admin/orders/'+props.order.id+'/check-'+kind, {}, {preserveScroll:true,
        onSuccess:()=>notice.value=kind==='process'?'Pemeriksaan proses dijadwalkan. Muat ulang untuk melihat hasilnya.':'Status pembayaran sudah diperiksa.',
        onFinish:()=>busy.value=false});
}
function refresh() {
    busy.value=true;
    router.reload({preserveScroll:true,onFinish:()=>busy.value=false});
}
async function copy(value) {
    try { await navigator.clipboard.writeText(value); notice.value='Nomor pesanan disalin.'; }
    catch { notice.value='Pilih dan salin nomor pesanan secara langsung.'; }
}
</script>
<template>
<Head :title="'Pesanan '+order.order_number" />
<AdminShell>
    <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h1 class="break-all text-xl font-semibold sm:text-2xl">{{ order.order_number }}</h1><p class="mt-1 text-sm text-muted-foreground">Detail pesanan dan riwayat penanganan.</p></div><Button variant="outline" as-child><Link href="/admin/orders">Daftar pesanan</Link></Button></div>
    <div class="my-4 flex flex-wrap gap-2"><Button variant="outline" size="sm" @click="copy(order.order_number)">Salin nomor pesanan</Button><Button variant="outline" size="sm" @click="refresh" :disabled="busy">{{ busy?'Memuat…':'Muat ulang detail' }}</Button><Button v-if="canCheckFulfillment" size="sm" @click="check('process')" :disabled="busy">Periksa proses pesanan</Button></div>
    <p v-if="notice" role="status" class="mb-4 text-sm">{{ notice }}</p>
    <p v-for="(error,key) in $page.props.errors" :key="key" role="alert" class="mb-3 text-sm text-destructive">{{ error }}</p>
    <div class="grid items-start gap-4 lg:grid-cols-2">
        <Card class="min-w-0 p-4 sm:p-5"><h2 class="font-semibold">Ringkasan pesanan</h2><dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="text-sm text-muted-foreground">Produk</dt><dd class="break-words font-medium">{{ order.product_name }}</dd></div><div><dt class="text-sm text-muted-foreground">Paket</dt><dd class="break-words">{{ order.package_name }}</dd></div><div><dt class="text-sm text-muted-foreground">Status</dt><dd class="mt-1"><Badge variant="secondary" class="whitespace-normal">{{ order.status_label }}</Badge></dd></div><div><dt class="text-sm text-muted-foreground">Total pembayaran</dt><dd class="font-semibold">{{ money(order.total_idr) }}</dd></div><div><dt class="text-sm text-muted-foreground">Dibuat</dt><dd class="text-sm">{{ date(order.created_at) }}</dd></div><div><dt class="text-sm text-muted-foreground">Pembayaran diterima</dt><dd class="text-sm">{{ order.paid_at ? date(order.paid_at) : 'Belum diterima' }}</dd></div><div><dt class="text-sm text-muted-foreground">Metode pembayaran</dt><dd>{{ order.payment_method }}</dd></div><div><dt class="text-sm text-muted-foreground">Penyedia</dt><dd>{{ order.provider }}</dd></div></dl></Card>
        <Card class="min-w-0 p-4 sm:p-5"><h2 class="font-semibold">Pelanggan dan tujuan</h2><dl class="mt-4 space-y-3"><div><dt class="text-sm text-muted-foreground">Nama pelanggan</dt><dd><Link v-if="order.customer_id && canViewCustomer" :href="'/admin/customers/'+order.customer_id" class="underline underline-offset-4">{{ order.buyer_name }}</Link><span v-else>{{ order.buyer_name }}</span></dd></div><div><dt class="text-sm text-muted-foreground">Nomor telepon / WhatsApp</dt><dd>{{ order.buyer_phone || 'Belum tersedia' }}</dd></div><div><dt class="text-sm text-muted-foreground">Email</dt><dd class="break-all">{{ order.buyer_email || 'Belum tersedia' }}</dd></div><div v-for="(target,index) in order.destinations" :key="index"><dt class="text-sm text-muted-foreground">{{ target.label }}</dt><dd class="break-all">{{ target.value }}</dd></div><div v-if="order.nickname"><dt class="text-sm text-muted-foreground">Nama akun</dt><dd>{{ order.nickname }}</dd></div></dl></Card>
    </div>
    <Card v-if="Object.keys(order.delivery).length" class="mt-4 p-4"><h2 class="font-semibold">Hasil pengiriman</h2><p v-if="order.delivery.code || order.delivery.serial_number" class="mt-3 whitespace-pre-wrap break-all">{{ order.delivery.code || order.delivery.serial_number }}</p><p v-if="order.delivery.note" class="mt-3 whitespace-pre-wrap break-words">{{ order.delivery.note }}</p></Card>
    <Card class="mt-4 min-w-0 p-4 sm:p-5"><h2 class="font-semibold">Riwayat pembayaran</h2><p v-if="!payments.length" class="mt-3 text-sm text-muted-foreground">{{ order.payment_method==='Pembayaran dicatat admin' ? 'Pembayaran pesanan ini dicatat langsung oleh admin.' : order.payment_method==='Saldo akun' ? 'Pembayaran menggunakan saldo akun.' : 'Belum ada transaksi pembayaran.' }}</p>
        <div class="mt-3 divide-y"><article v-for="payment in payments" :key="payment.id" class="flex flex-wrap items-start justify-between gap-3 py-3"><div class="min-w-0"><p class="font-medium">{{ payment.method }} · {{ money(payment.amount_idr) }}</p><Badge variant="secondary" class="mt-2 whitespace-normal">{{ payment.status_label }}</Badge><p class="mt-2 text-xs text-muted-foreground">Dibuat {{ date(payment.created_at) }}</p><p v-if="payment.verified_at" class="text-xs text-muted-foreground">Diverifikasi {{ date(payment.verified_at) }}</p></div><div class="flex flex-wrap gap-2"><Button v-if="payment.can_check" variant="outline" size="sm" :disabled="busy" @click="check('payment')">Periksa pembayaran</Button><Button v-if="payment.can_confirm" size="sm" @click="open('payment',payment.id)">Konfirmasi pembayaran</Button></div></article></div>
        <p class="mt-3 text-xs text-muted-foreground">Status pembayaran otomatis berubah setelah hasil yang sah diterima dari penyedia. Memuat ulang detail hanya menampilkan data terbaru yang sudah tersimpan.</p>
    </Card>
    <Card class="mt-4 min-w-0 p-4 sm:p-5"><h2 class="font-semibold">Proses pesanan</h2><p v-if="!attempts.length" class="mt-3 text-sm text-muted-foreground">Belum ada proses pengiriman. Pesanan yang sudah dibayar akan masuk antrean penanganan.</p><article v-for="attempt in attempts" :key="attempt.id" class="mt-3 border-t py-4"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="font-medium">Penanganan ke-{{ attempt.attempt_no }} · {{ attempt.provider }}</p><Badge variant="secondary" class="mt-2 whitespace-normal">{{ attempt.status_label }}</Badge><p class="mt-2 text-xs text-muted-foreground">{{ date(attempt.created_at) }}</p><p v-if="attempt.checked_at" class="text-xs text-muted-foreground">Terakhir diperiksa: {{ date(attempt.checked_at) }}</p></div><div class="flex flex-wrap gap-2"><template v-if="attempt.can_manual"><Button size="sm" @click="open('complete',attempt.id)">Selesaikan pesanan</Button><Button size="sm" variant="outline" @click="open('fail',attempt.id)">Nyatakan gagal</Button></template><Button v-if="attempt.can_retry" size="sm" variant="outline" @click="open('retry',attempt.id)">Lanjutkan pengiriman</Button></div></div><p v-if="attempt.serial_number" class="mt-3 break-all text-sm">Kode pengiriman: {{ attempt.serial_number }}</p><p v-if="attempt.note" class="mt-3 text-sm text-muted-foreground">{{ attempt.note }}</p></article></Card>
    <Card class="mt-4 p-4 sm:p-5"><h2 class="font-semibold">Riwayat pesanan</h2><p v-if="!events.length" class="mt-3 text-sm text-muted-foreground">Belum ada perubahan status.</p><ol class="mt-3 divide-y"><li v-for="event in events" :key="event.id" class="py-3"><p class="text-sm font-medium">{{ event.label }}</p><p class="mt-1 text-sm text-muted-foreground">{{ event.from }} → {{ event.to }}</p><p class="mt-1 text-xs text-muted-foreground">{{ date(event.created_at) }}</p></li></ol></Card>
    <Sheet v-model:open="actionOpen"><SheetContent class="w-full overflow-y-auto sm:max-w-lg"><SheetHeader><SheetTitle>{{ heading?.title }}</SheetTitle><SheetDescription>{{ heading?.description }}</SheetDescription></SheetHeader><form @submit.prevent="submit" class="mt-6 space-y-4">
        <template v-if="action?.type==='complete'"><label class="block space-y-1"><span class="text-sm font-medium">Kode / bukti pengiriman (jika ada)</span><Textarea v-model="form.delivery_code" maxlength="2000" :disabled="form.processing" /></label><label class="block space-y-1"><span class="text-sm font-medium">Catatan untuk pelanggan (opsional)</span><Textarea v-model="form.note" maxlength="4000" :disabled="form.processing" /></label></template>
        <label v-if="action?.type==='fail'" class="block space-y-1"><span class="text-sm font-medium">Alasan kegagalan</span><Textarea v-model="form.reason" required maxlength="4000" :disabled="form.processing" /></label>
        <p v-if="action?.type==='payment'" class="font-semibold">Total pesanan: {{ money(order.total_idr) }}</p>
        <label class="flex items-start gap-2 text-sm"><input v-model="form.confirmed" type="checkbox" required class="mt-1 size-4 shrink-0" :disabled="form.processing" /><span>Saya sudah memeriksa pesanan dan memastikan tindakan ini benar.</span></label>
        <p v-for="(error,key) in form.errors" :key="key" role="alert" class="text-sm text-destructive">{{ error }}</p>
        <div class="flex gap-2"><Button type="submit" :disabled="form.processing || !form.confirmed">{{ form.processing?'Menyimpan…':'Konfirmasi tindakan' }}</Button><Button type="button" variant="outline" :disabled="form.processing" @click="actionOpen=false">Batal</Button></div>
    </form></SheetContent></Sheet>
</AdminShell>
</template>
