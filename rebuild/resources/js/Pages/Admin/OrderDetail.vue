<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Badge } from '../../Components/ui/badge';
import { Textarea } from '../../Components/ui/textarea';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import { confirmSensitive } from '../../lib/sensitivePrompt';
const props = defineProps({ order: Object, events: Array, payments: Array, attempts: Array, canViewCustomer: Boolean, canCheckFulfillment: Boolean, canResendDelivery: Boolean, canCancel: Boolean });
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
    cancel:{ title:'Batalkan pesanan', description:'Hanya untuk pesanan yang tidak sedang dikirim. Saldo tidak dikembalikan otomatis; selesaikan dana manual bersama pelanggan.', suffix:null },
    retry:{ title:'Lanjutkan pengiriman', description:'Hanya pengiriman yang tertahan sebelum dikirim atau telah dikonfirmasi gagal yang dapat dilanjutkan. Pesanan yang belum pasti hasilnya harus diperiksa terlebih dahulu.', suffix:'retry' },
    payment:{ title:'Konfirmasi pembayaran QRIS manual', description:'Periksa mutasi rekening dan cocokkan nominal serta nomor pesanan sebelum melanjutkan.', suffix:'confirm' },
    resend:{ title:'Kirim ulang hasil pesanan', description:'Kode atau hasil pengiriman yang sudah tersimpan akan dikirim lagi ke email pelanggan. Tindakan ini tidak memproses ulang pesanan ke penyedia.', suffix:null },
};
const heading = computed(() => action.value ? actions[action.value.type] : null);
const whatsappUrl = computed(() => {
    const digits = String(props.order.buyer_phone || '').replace(/\D/g, '');
    if (!digits) return '';
    const normalized = digits.startsWith('0') ? '62' + digits.slice(1) : (digits.startsWith('8') ? '62' + digits : digits);
    const text = `Halo ${props.order.buyer_name || 'Pelanggan'}, kami menghubungi terkait pesanan ${props.order.order_number}.`;
    return 'https://wa.me/' + normalized + '?text=' + encodeURIComponent(text);
});
// Langkah berikut ditentukan dari percobaan pengiriman terbaru, supaya admin
// tidak perlu menebak tombol mana yang harus ditekan lebih dulu.
const latest = computed(() => props.attempts[0] || null);
const nextStep = computed(() => {
    const attempt = latest.value;
    if (attempt?.can_manual) return { text: 'Pengiriman manual menunggu hasil. Isi kode pengiriman bila sudah diterima pelanggan, atau nyatakan gagal bila tidak berhasil.', primary: 'complete', label: 'Selesaikan pesanan' };
    if (attempt?.can_retry) return { text: 'Pengiriman sebelumnya gagal. Periksa penyedia, lalu lanjutkan pengiriman.', primary: 'retry', label: 'Lanjutkan pengiriman' };
    if (props.canCheckFulfillment) return { text: 'Hasil pengiriman belum pasti. Periksa ke penyedia untuk memastikan status sebenarnya.', primary: 'check', label: 'Periksa proses' };
    if (props.order.needs_attention) return { text: 'Pesanan ini perlu penanganan admin. Periksa riwayat proses di bawah sebelum mengambil tindakan.', primary: null };
    if (!props.order.paid_at) return { text: 'Menunggu pembayaran dari pelanggan. Kirim pengingat lewat WhatsApp bila perlu.', primary: null };
    if (props.order.status === 'SUCCESS') return { text: 'Pesanan sudah selesai. Hasil pengiriman tersimpan di bawah dan bisa dikirim ulang ke email pelanggan.', primary: null };
    return { text: 'Pembayaran sudah diterima dan pesanan sedang diproses. Tidak ada tindakan yang diperlukan saat ini.', primary: null };
});
const summary = computed(() => [
    ['Produk', props.order.product_name],
    ['Paket', props.order.package_name],
    ['Total pembayaran', money(props.order.total_idr)],
    ['Metode pembayaran', props.order.payment_method],
    ['Penyedia', props.order.provider],
    ['Dibuat', date(props.order.created_at)],
    ['Pembayaran diterima', props.order.paid_at ? date(props.order.paid_at) : 'Belum diterima'],
]);
function open(type,id) { form.reset(); form.clearErrors(); action.value={type,id}; actionOpen.value=true; }
function runPrimary() {
    if (nextStep.value.primary === 'check') return check('process');
    if (latest.value) open(nextStep.value.primary, latest.value.id);
}
async function submit() {
    if (!form.confirmed || !action.value) return;
    const needsPassword = ['cancel','payment'].includes(action.value.type);
    if (needsPassword && !(await confirmSensitive('Tindakan ini mengubah status pesanan. Masukkan kata sandi admin untuk melanjutkan.'))) return;
    const url = action.value.type==='cancel' ? '/admin/orders/'+props.order.id+'/cancel' : action.value.type==='payment' ? '/admin/payments/manual/'+action.value.id+'/confirm' : action.value.type==='resend' ? '/admin/orders/'+props.order.id+'/resend-delivery' : '/admin/fulfillment/'+action.value.id+'/'+heading.value.suffix;
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
    <header class="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <Link href="/admin/orders" class="text-xs text-muted-foreground underline underline-offset-4">← Semua pesanan</Link>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h1 class="break-all text-xl font-semibold tracking-tight sm:text-2xl">{{ order.order_number }}</h1>
                <Badge :variant="order.status==='FAILED'?'destructive':'secondary'">{{ order.status_label }}</Badge>
            </div>
            <p class="mt-1 text-xs text-muted-foreground">Dibuat {{ date(order.created_at) }} · {{ order.paid_at ? 'Pembayaran sudah diterima' : 'Belum dibayar' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" @click="copy(order.order_number)">Salin nomor</Button>
            <Button v-if="whatsappUrl" variant="outline" size="sm" as-child><a :href="whatsappUrl" target="_blank" rel="noopener noreferrer">Hubungi pelanggan</a></Button>
            <Button variant="outline" size="sm" @click="refresh" :disabled="busy">{{ busy?'Memuat…':'Muat ulang' }}</Button>
        </div>
    </header>

    <section class="mt-5 border-b border-border pb-5" aria-labelledby="order-status-heading">
        <h2 id="order-status-heading" class="text-sm font-semibold text-muted-foreground">Status saat ini</h2>
        <p class="mt-2 text-lg font-semibold">{{ order.status_label }}</p>
        <p class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ nextStep.text }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <Button v-if="nextStep.primary" size="sm" :disabled="busy" @click="runPrimary">{{ nextStep.label }}</Button>
            <Button v-if="canCancel" variant="outline" size="sm" @click="open('cancel',order.id)">Batalkan pesanan</Button>
        </div>
    </section>

    <p v-if="notice" role="status" class="mt-4 text-sm">{{ notice }}</p>
    <p v-for="(error,key) in $page.props.errors" :key="key" role="alert" class="mt-3 text-sm text-destructive">{{ error }}</p>

    <section class="mt-6" aria-labelledby="order-summary-heading">
        <h2 id="order-summary-heading" class="text-sm font-semibold text-muted-foreground">Ringkasan pesanan</h2>
        <dl class="mt-3 grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="row in summary" :key="row[0]" class="min-w-0">
                <dt class="text-xs text-muted-foreground">{{ row[0] }}</dt>
                <dd class="break-words font-medium">{{ row[1] }}</dd>
            </div>
        </dl>
    </section>

    <section class="mt-6 border-t border-border pt-5" aria-labelledby="order-customer-heading">
        <h2 id="order-customer-heading" class="text-sm font-semibold text-muted-foreground">Pelanggan dan tujuan</h2>
        <dl class="mt-3 grid gap-x-8 gap-y-3 sm:grid-cols-2">
            <div class="min-w-0"><dt class="text-xs text-muted-foreground">Nama pelanggan</dt><dd><Link v-if="order.customer_id && canViewCustomer" :href="'/admin/customers/'+order.customer_id" class="underline underline-offset-4">{{ order.buyer_name }}</Link><span v-else>{{ order.buyer_name }}</span></dd></div>
            <div class="min-w-0"><dt class="text-xs text-muted-foreground">Nomor telepon / WhatsApp</dt><dd>{{ order.buyer_phone || 'Belum tersedia' }}</dd></div>
            <div class="min-w-0"><dt class="text-xs text-muted-foreground">Email</dt><dd class="break-all">{{ order.buyer_email || 'Belum tersedia' }}</dd></div>
            <div v-for="(target,index) in order.destinations" :key="index" class="min-w-0"><dt class="text-xs text-muted-foreground">{{ target.label }}</dt><dd class="break-all">{{ target.value }}</dd></div>
            <div v-if="order.nickname" class="min-w-0"><dt class="text-xs text-muted-foreground">Nama akun</dt><dd>{{ order.nickname }}</dd></div>
        </dl>
    </section>

    <section v-if="order.manual_note || (order.manual && order.manual_instructions)" class="mt-6 border-t border-border pt-5" aria-labelledby="order-manual-heading">
        <h2 id="order-manual-heading" class="text-sm font-semibold text-muted-foreground">Catatan penanganan manual</h2>
        <p v-if="order.manual_note" class="mt-2 whitespace-pre-wrap break-words text-sm">Catatan pembayaran: {{ order.manual_note }}</p>
        <p v-if="order.manual_instructions" class="mt-2 whitespace-pre-wrap break-words text-sm">{{ order.manual_instructions }}</p>
    </section>

    <section v-if="Object.keys(order.delivery).length" class="mt-6 border-t border-border pt-5" aria-labelledby="order-delivery-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 id="order-delivery-heading" class="text-sm font-semibold text-muted-foreground">Hasil pengiriman</h2>
            <Button v-if="canResendDelivery" variant="outline" size="sm" @click="open('resend',order.id)">Kirim ulang ke email</Button>
        </div>
        <p v-if="order.delivery.code || order.delivery.serial_number" class="mt-2 whitespace-pre-wrap break-all font-medium">{{ order.delivery.code || order.delivery.serial_number }}</p>
        <p v-if="order.delivery.note" class="mt-2 whitespace-pre-wrap break-words text-sm">{{ order.delivery.note }}</p>
        <p v-if="canResendDelivery" class="mt-2 text-xs text-muted-foreground">Mengirim ulang hanya memakai hasil yang sudah tersimpan dan tidak mengulangi transaksi ke penyedia.</p>
    </section>

    <section class="mt-6 border-t border-border pt-5" aria-labelledby="order-payment-heading">
        <h2 id="order-payment-heading" class="text-sm font-semibold text-muted-foreground">Pembayaran</h2>
        <p v-if="!payments.length" class="mt-2 text-sm text-muted-foreground">{{ order.payment_method==='Pembayaran dicatat admin' ? 'Pembayaran pesanan ini dicatat langsung oleh admin.' : order.payment_method==='Saldo akun' ? 'Pembayaran menggunakan saldo akun.' : 'Belum ada transaksi pembayaran.' }}</p>
        <article v-for="payment in payments" :key="payment.id" class="mt-3 flex flex-wrap items-start justify-between gap-3 border-t border-border pt-3">
            <div class="min-w-0">
                <p class="font-medium">{{ payment.method }} · {{ money(payment.amount_idr) }}</p>
                <Badge variant="secondary" class="mt-1 whitespace-normal">{{ payment.status_label }}</Badge>
                <p class="mt-1 text-xs text-muted-foreground">Dibuat {{ date(payment.created_at) }}</p>
                <p v-if="payment.verified_at" class="text-xs text-muted-foreground">Diverifikasi {{ date(payment.verified_at) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="payment.can_check" variant="outline" size="sm" :disabled="busy" @click="check('payment')">Periksa pembayaran</Button>
                <Button v-if="payment.can_confirm" size="sm" @click="open('payment',payment.id)">Konfirmasi pembayaran</Button>
            </div>
        </article>
        <p class="mt-3 text-xs text-muted-foreground">Status pembayaran berubah otomatis setelah hasil yang sah diterima dari penyedia. Memuat ulang hanya menampilkan data terbaru yang sudah tersimpan.</p>
    </section>

    <section class="mt-6 border-t border-border pt-5" aria-labelledby="order-process-heading">
        <h2 id="order-process-heading" class="text-sm font-semibold text-muted-foreground">Proses pesanan</h2>
        <p v-if="!attempts.length" class="mt-2 text-sm text-muted-foreground">Belum ada proses pengiriman. Pesanan yang sudah dibayar akan masuk antrean penanganan.</p>
        <article v-for="attempt in attempts" :key="attempt.id" class="mt-3 border-t border-border pt-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium">Penanganan ke-{{ attempt.attempt_no }} · {{ attempt.provider }}</p>
                    <Badge variant="secondary" class="mt-1 whitespace-normal">{{ attempt.status_label }}</Badge>
                    <p class="mt-1 text-xs text-muted-foreground">{{ date(attempt.created_at) }}</p>
                    <p v-if="attempt.checked_at" class="text-xs text-muted-foreground">Terakhir diperiksa: {{ date(attempt.checked_at) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="attempt.can_manual">
                        <Button size="sm" @click="open('complete',attempt.id)">Selesaikan pesanan</Button>
                        <Button size="sm" variant="outline" @click="open('fail',attempt.id)">Nyatakan gagal</Button>
                    </template>
                    <Button v-if="attempt.can_retry" size="sm" variant="outline" @click="open('retry',attempt.id)">Lanjutkan pengiriman</Button>
                </div>
            </div>
            <p v-if="attempt.reference" class="mt-2 break-all text-xs text-muted-foreground">Nomor referensi penyedia: {{ attempt.reference }}</p>
            <p v-if="attempt.result_code" class="mt-1 text-xs text-muted-foreground">Kode hasil penyedia: {{ attempt.result_code }}</p>
            <p v-if="attempt.serial_number" class="mt-2 break-all text-sm">Kode pengiriman: {{ attempt.serial_number }}</p>
            <p v-if="attempt.note" class="mt-2 text-sm text-muted-foreground">{{ attempt.note }}</p>
        </article>
    </section>

    <section class="mt-6 border-t border-border pt-5" aria-labelledby="order-history-heading">
        <h2 id="order-history-heading" class="text-sm font-semibold text-muted-foreground">Riwayat pesanan</h2>
        <p v-if="!events.length" class="mt-2 text-sm text-muted-foreground">Belum ada perubahan status.</p>
        <ol class="mt-2">
            <li v-for="event in events" :key="event.id" class="border-t border-border py-3">
                <p class="text-sm font-medium">{{ event.label }}</p>
                <p class="mt-0.5 text-sm text-muted-foreground">{{ event.from }} → {{ event.to }}</p>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ date(event.created_at) }}</p>
            </li>
        </ol>
    </section>

    <Sheet v-model:open="actionOpen"><SheetContent class="w-full overflow-y-auto sm:max-w-lg"><SheetHeader><SheetTitle>{{ heading?.title }}</SheetTitle><SheetDescription>{{ heading?.description }}</SheetDescription></SheetHeader><form @submit.prevent="submit" class="mt-6 space-y-4">
        <template v-if="action?.type==='complete'"><label class="block space-y-1"><span class="text-sm font-medium">Kode / bukti pengiriman (jika ada)</span><Textarea v-model="form.delivery_code" maxlength="2000" :disabled="form.processing" /></label><label class="block space-y-1"><span class="text-sm font-medium">Catatan untuk pelanggan (opsional)</span><Textarea v-model="form.note" maxlength="4000" :disabled="form.processing" /></label></template>
        <label v-if="action?.type==='fail' || action?.type==='cancel'" class="block space-y-1"><span class="text-sm font-medium">Alasan kegagalan</span><Textarea v-model="form.reason" required maxlength="4000" :disabled="form.processing" /></label>
        <p v-if="action?.type==='cancel'" class="text-sm font-medium">Tidak ada pengembalian dana otomatis. Pengembalian dana, jika disetujui, dilakukan admin secara terpisah.</p><p v-if="action?.type==='payment'" class="font-semibold">Total pesanan: {{ money(order.total_idr) }}</p><p v-if="action?.type==='resend'" class="text-sm">Email tujuan: <strong class="break-all">{{ order.buyer_email }}</strong></p>
        <label class="flex items-start gap-2 text-sm"><input v-model="form.confirmed" type="checkbox" required class="mt-1 size-4 shrink-0" :disabled="form.processing" /><span>Saya sudah memeriksa pesanan dan memastikan tindakan ini benar.</span></label>
        <p v-for="(error,key) in form.errors" :key="key" role="alert" class="text-sm text-destructive">{{ error }}</p>
        <div class="flex flex-col gap-2 sm:flex-row"><Button class="w-full sm:w-auto" type="submit" :disabled="form.processing || !form.confirmed">{{ form.processing?'Menyimpan…':'Konfirmasi tindakan' }}</Button><Button class="w-full sm:w-auto" type="button" variant="outline" :disabled="form.processing" @click="actionOpen=false">Batal</Button></div>
    </form></SheetContent></Sheet>
</AdminShell>
</template>
