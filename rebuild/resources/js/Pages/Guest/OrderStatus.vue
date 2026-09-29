<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { rupiah } from '../../lib/money';

const props = defineProps({ order: Object, payment: Object });

const paymentState = ref(props.payment);
const errors = ref({});
const busy = ref(false);
const paymentKey = ref(globalThis.crypto?.randomUUID?.() || ('payment-' + Date.now()));

async function continuePayment() {
    errors.value = {};
    busy.value = true;
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('/payments/orders/' + encodeURIComponent(props.order.order_number), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({ idempotency_key: paymentKey.value }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            errors.value = data.errors || { payment: [data.message || 'Pembayaran tidak dapat dilanjutkan.'] };
            return;
        }
        paymentState.value = data;
        const redirect = data?.instructions?.redirect_url || data?.instructions?.payment_url;
        if (redirect) window.location.assign(redirect);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Status pesanan" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <div class="mx-auto max-w-xl space-y-5">
            <Link href="/" class="text-cyan-300">← LFAMILIA STORE</Link>
            <h1 class="text-3xl font-semibold">Pesanan {{ order.order_number }}</h1>
            <dl class="grid gap-4 rounded-xl border border-slate-800 bg-slate-900 p-5 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-400">Produk</dt><dd>{{ order.product_name }}</dd></div>
                <div><dt class="text-sm text-slate-400">Status</dt><dd>{{ order.status }}</dd></div>
                <div><dt class="text-sm text-slate-400">Total</dt><dd>{{ rupiah(order.total_idr) }}</dd></div>
                <div><dt class="text-sm text-slate-400">Dibuat</dt><dd>{{ order.created_at }}</dd></div>
            </dl>

            <section v-if="order.status === 'PENDING_PAYMENT'" class="space-y-3 rounded-xl border border-cyan-500/30 bg-cyan-500/10 p-5">
                <h2 class="text-lg font-semibold">Pembayaran</h2>
                <p v-if="paymentState" class="text-sm">Status: <strong>{{ paymentState.status }}</strong><span v-if="paymentState.channel_name"> · {{ paymentState.channel_name }}</span></p>
                <img v-if="paymentState?.instructions?.qr_url" :src="paymentState.instructions.qr_url" alt="QRIS pembayaran" class="max-h-72 rounded-lg bg-white p-2">
                <p v-if="paymentState?.instructions?.va_number" class="text-sm">Nomor VA: <strong>{{ paymentState.instructions.va_number }}</strong></p>
                <p v-if="paymentState?.instructions?.payment_code" class="text-sm">Kode pembayaran: <strong>{{ paymentState.instructions.payment_code }}</strong></p>
                <p v-if="paymentState?.status === 'UNKNOWN'" class="text-sm text-amber-200">Status pembayaran belum dapat dipastikan. Sistem tidak akan membuat pembayaran kedua otomatis.</p>
                <button type="button" :disabled="busy" class="rounded-lg bg-cyan-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50" @click="continuePayment">
                    {{ busy ? 'Memeriksa...' : paymentState ? 'Lanjutkan pembayaran' : 'Bayar sekarang' }}
                </button>
                <p v-if="errors.payment" class="text-sm text-red-300">{{ errors.payment[0] }}</p>
            </section>
        </div>
    </main>
</template>
