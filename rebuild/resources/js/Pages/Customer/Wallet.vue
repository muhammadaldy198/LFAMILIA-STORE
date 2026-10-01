<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';
import { rupiah } from '../../lib/money';

const props = defineProps({
    balanceIdr: Number,
    minimumTopupIdr: Number,
    paymentChannels: Array,
    entries: Object,
});

const amountIdr = ref(props.minimumTopupIdr || 10000);
const paymentChannelCode = ref('');
const quote = ref(null);
const result = ref(null);
const errors = ref({});
const busy = ref('');
const attemptLocked = ref(false);
const quoteMatches = computed(() => quote.value && Number(quote.value.amount_idr) === Number(amountIdr.value)
    && quote.value.payment_channel_code === paymentChannelCode.value);
watch([amountIdr, paymentChannelCode], () => { quote.value = null; errors.value = {}; });
const statusLabel = status => ({PENDING: 'Menunggu pembayaran', CREATING: 'Menyiapkan pembayaran', UNKNOWN: 'Status belum pasti', PAID: 'Pembayaran diterima', EXPIRED: 'Kedaluwarsa', REJECTED: 'Ditolak'}[status] || status);
const idempotencyKey = ref(globalThis.crypto?.randomUUID?.() || ('topup-' + Date.now()));

async function postJson(url, payload) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const response = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(data.message || 'Permintaan gagal.');
        error.validation = data.errors || { topup: [error.message] };
        throw error;
    }
    return data;
}

async function loadQuote() {
    if (busy.value || attemptLocked.value || !paymentChannelCode.value) return;
    quote.value = null;
    errors.value = {};
    busy.value = 'quote';
    try {
        quote.value = await postJson('/account/wallet/topups/quote', {
            amount_idr: Number(amountIdr.value),
            payment_channel_code: paymentChannelCode.value,
        });
    } catch (error) {
        errors.value = error.validation || { topup: [error.message || 'Koneksi terputus. Coba lagi.'] };
    } finally {
        busy.value = '';
    }
}

async function createTopup() {
    if (busy.value || result.value || !quoteMatches.value) return;
    attemptLocked.value = true;
    errors.value = {};
    busy.value = 'topup';
    try {
        result.value = await postJson('/account/wallet/topups', {
            amount_idr: Number(amountIdr.value),
            payment_channel_code: paymentChannelCode.value,
            idempotency_key: idempotencyKey.value,
        });
        const redirect = ['PENDING'].includes(result.value?.payment?.status)
            ? (result.value?.payment?.instructions?.redirect_url || result.value?.payment?.instructions?.payment_url) : null;
        if (redirect) window.location.assign(redirect);
    } catch (error) {
        errors.value = error.validation || {};
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <Head title="Saldo" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">Saldo</h1>
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-6">
            <span class="text-slate-400">Saldo tersedia</span>
            <strong class="mt-2 block text-3xl text-cyan-300">{{ rupiah(balanceIdr) }}</strong>
        </div>

        <section class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
            <div>
                <h2 class="text-xl font-semibold">Top up saldo</h2>
                <p class="text-sm text-slate-400">Minimum {{ rupiah(minimumTopupIdr) }}. Biaya metode pembayaran dihitung server.</p>
            </div>
            <label class="block text-sm">Nominal
                <input v-model.number="amountIdr" type="number" step="1" :min="minimumTopupIdr" :disabled="Boolean(busy) || attemptLocked" class="mt-1 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
            </label>
            <span v-if="errors.amount_idr" class="text-xs text-red-300">{{ errors.amount_idr[0] }}</span>

            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="channel in paymentChannels"
                    :key="channel.code"
                    type="button"
                    :disabled="Boolean(busy) || attemptLocked"
                    class="rounded-lg border px-3 py-2 text-left text-sm"
                    :class="paymentChannelCode === channel.code ? 'border-cyan-400 bg-cyan-400/10' : 'border-slate-700 bg-slate-950'"
                    @click="paymentChannelCode = channel.code; quote = null"
                >{{ channel.name }}</button>
            </div>
            <span v-if="errors.payment_channel_code" class="text-xs text-red-300">{{ errors.payment_channel_code[0] }}</span>

            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="busy || attemptLocked || !paymentChannelCode" class="rounded-lg bg-slate-700 px-4 py-2 disabled:opacity-50" @click="loadQuote">Hitung total</button>
                <button type="button" :disabled="busy || result || !quoteMatches" class="rounded-lg bg-cyan-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50" @click="createTopup">{{busy === 'topup' ? 'Menyiapkan…' : attemptLocked ? 'Cek percobaan yang sama' : 'Top up'}}</button>
            </div>
            <p v-if="!paymentChannels?.length" class="text-sm text-slate-400">Metode top up belum tersedia. Hubungi bantuan.</p>
            <p v-if="attemptLocked && !result" role="status" class="text-sm text-amber-200">Jika status belum pasti, coba cek percobaan yang sama. Jangan membuat top up baru.</p>
            <div v-if="quoteMatches" class="rounded-lg bg-slate-950 p-3 text-sm">
                <div class="flex justify-between"><span>Saldo masuk</span><span>{{ rupiah(quote.amount_idr) }}</span></div>
                <div class="flex justify-between"><span>Biaya</span><span>{{ rupiah(quote.fee_idr) }}</span></div>
                <div class="mt-2 flex justify-between border-t border-slate-800 pt-2 font-semibold"><span>Total bayar</span><span>{{ rupiah(quote.total_idr) }}</span></div>
            </div>
            <div v-if="result?.payment" class="space-y-2 rounded-lg border border-cyan-500/30 bg-cyan-500/10 p-3 text-sm">
                <p>Status pembayaran: <strong>{{ statusLabel(result.payment.status) }}</strong></p>
                <p v-if="result.payment.status === 'PENDING' && result.payment.instructions?.va_number">Nomor VA: <strong>{{ result.payment.instructions.va_number }}</strong></p>
                <p v-if="result.payment.instructions?.payment_code">Kode: <strong>{{ result.payment.instructions.payment_code }}</strong></p>
                <a v-if="result.payment.instructions?.payment_url" :href="result.payment.instructions.payment_url" class="text-cyan-200 underline">Buka pembayaran</a>
            </div>
            <p v-if="errors.payment" class="text-sm text-red-300">{{ errors.payment[0] }}</p>
            <p v-if="errors.topup" class="text-sm text-red-300">{{ errors.topup[0] }}</p>
        </section>

        <h2 class="text-xl font-semibold">Riwayat saldo</h2>
        <p v-if="!entries.data.length" class="text-slate-400">Belum ada mutasi saldo.</p>
        <div v-else class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full min-w-[500px] text-left text-sm">
                <thead class="bg-slate-900 text-slate-400"><tr><th class="p-3">Tanggal</th><th class="p-3">Jenis</th><th class="p-3">Perubahan</th><th class="p-3">Saldo akhir</th></tr></thead>
                <tbody><tr v-for="entry in entries.data" :key="entry.id" class="border-t border-slate-800"><td class="p-3">{{ entry.created_at }}</td><td class="p-3">{{ entry.source }}</td><td class="p-3">{{ rupiah(entry.amount_idr) }}</td><td class="p-3">{{ rupiah(entry.balance_after_idr) }}</td></tr></tbody>
            </table>
        </div>
        <nav aria-label="Halaman riwayat saldo" class="flex flex-wrap gap-2"><Link v-for="link in entries.links" :key="link.label" :href="link.url || '#'" class="rounded-md px-3 py-2 text-sm" :class="link.active ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800 text-slate-200'" :aria-disabled="!link.url" v-html="link.label" /></nav>
    </AccountShell>
</template>
