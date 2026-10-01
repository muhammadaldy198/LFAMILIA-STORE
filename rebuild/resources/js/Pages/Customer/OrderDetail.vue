<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';
import { rupiah } from '../../lib/money';

const props = defineProps({ order: Object, payment: Object, review: Object });

const paymentState = ref(props.payment);
const errors = ref({});
const busy = ref(false);

const reviewRating = ref(Number(props.review?.rating || 5));
const reviewBody = ref(props.review?.body || '');
const reviewDone = ref(Boolean(props.review));
const reviewMessage = ref('');

async function submitReview() {
    if (busy.value || reviewDone.value || props.order.status !== 'SUCCESS') return;
    reviewMessage.value = '';
    busy.value = true;
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('/reviews', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ order_number: props.order.order_number, rating: Number(reviewRating.value), body: reviewBody.value }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) { reviewMessage.value = data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Ulasan gagal dikirim.'; return; }
        reviewDone.value = true;
        reviewMessage.value = data.message || 'Ulasan berhasil dikirim.';
    } catch {
        reviewMessage.value = 'Koneksi terputus. Coba kirim ulasan lagi.';
    } finally { busy.value = false; }
}
</script>

<template>
    <Head title="Detail pesanan" />
    <AccountShell>
        <Link href="/account/orders" class="text-sm text-cyan-300">← Semua pesanan</Link>
        <h1 class="text-3xl font-semibold">Pesanan {{ order.order_number }}</h1>
        <dl class="grid gap-4 rounded-xl border border-slate-800 bg-slate-900 p-5 sm:grid-cols-2">
            <div><dt class="text-sm text-slate-400">Produk</dt><dd>{{ order.product_name }} · {{ order.package_name }}</dd></div>
            <div><dt class="text-sm text-slate-400">Status</dt><dd>{{ order.status }}</dd></div>
            <div><dt class="text-sm text-slate-400">Total</dt><dd>{{ rupiah(order.total_idr) }}</dd></div>
            <div><dt class="text-sm text-slate-400">Dibuat</dt><dd>{{ order.created_at }}</dd></div>
            <div v-if="order.paid_at"><dt class="text-sm text-slate-400">Dibayar</dt><dd>{{ order.paid_at }}</dd></div>
        </dl>

        <section v-if="order.status === 'PENDING_PAYMENT'" class="space-y-3 rounded-xl border border-cyan-500/30 bg-cyan-500/10 p-5">
            <h2 class="text-lg font-semibold">Pembayaran</h2>
            <p v-if="paymentState" class="text-sm">Status: <strong>{{ paymentState.status }}</strong><span v-if="paymentState.channel_name"> · {{ paymentState.channel_name }}</span></p>
            <img v-if="paymentState?.status === 'PENDING' && paymentState?.instructions?.qr_url" :src="paymentState.instructions.qr_url" alt="QRIS pembayaran" class="max-h-72 rounded-lg bg-white p-2">
            <p v-if="paymentState?.status === 'PENDING' && paymentState?.instructions?.va_number" class="text-sm">Nomor VA: <strong>{{ paymentState.instructions.va_number }}</strong></p>
            <p v-if="paymentState?.status === 'PENDING' && paymentState?.instructions?.payment_code" class="text-sm">Kode pembayaran: <strong>{{ paymentState.instructions.payment_code }}</strong></p>
            <p v-if="paymentState?.status === 'UNKNOWN'" class="text-sm text-amber-200">Status pembayaran belum dapat dipastikan. Sistem tidak akan membuat pembayaran kedua otomatis.</p>
            <Link :href="'/payment?invoice=' + encodeURIComponent(order.order_number)" class="lf-primary">Lihat pembayaran</Link>
            <p v-if="errors.payment" class="text-sm text-red-300">{{ errors.payment[0] }}</p>
        </section>

        <section v-if="order.status === 'SUCCESS' && order.delivery" class="space-y-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-5">
            <h2 class="text-lg font-semibold">Hasil pesanan</h2>
            <p v-if="order.delivery.serial_number" class="break-all text-sm">Serial / SN: <strong>{{ order.delivery.serial_number }}</strong></p>
            <p v-if="order.delivery.code" class="break-all text-sm">Kode / hasil: <strong>{{ order.delivery.code }}</strong></p>
            <p v-if="order.delivery.note" class="whitespace-pre-wrap text-sm">{{ order.delivery.note }}</p>
        </section>

        <section v-if="order.status === 'SUCCESS'" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
            <template v-if="reviewDone">
                <h2 class="text-lg font-semibold">Ulasan kamu</h2>
                <div class="text-amber-400">{{ '★'.repeat(reviewRating) }}{{ '☆'.repeat(5-reviewRating) }}</div>
                <p class="text-sm text-slate-300">{{ reviewBody || 'Terima kasih sudah memberi ulasan.' }}</p>
            </template>
            <template v-else>
                <h2 class="text-lg font-semibold">Beri ulasan</h2>
                <div class="grid gap-3 sm:grid-cols-[120px_1fr]">
                    <select v-model.number="reviewRating" class="rounded bg-slate-800 p-2"><option :value="5">5 ★</option><option :value="4">4 ★</option><option :value="3">3 ★</option><option :value="2">2 ★</option><option :value="1">1 ★</option></select>
                    <textarea v-model="reviewBody" rows="3" maxlength="2000" placeholder="Ceritakan pengalaman transaksimu" class="rounded bg-slate-800 p-2"></textarea>
                </div>
                <button type="button" class="rounded bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50" :disabled="busy || reviewBody.trim().length < 3" @click="submitReview">Kirim ulasan</button>
            </template>
            <p v-if="reviewMessage" class="text-sm text-emerald-300">{{ reviewMessage }}</p>
        </section>

        <Link href="/account/tickets" class="text-cyan-300">Butuh bantuan? Buat tiket</Link>
    </AccountShell>
</template>
