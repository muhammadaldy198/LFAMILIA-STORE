<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';
import CustomerPagination from '../../Components/CustomerPagination.vue';
import { rupiah } from '../../lib/money';

const props = defineProps({
    balanceIdr: Number,
    minimumTopupIdr: Number,
    topupEnabled: { type: Boolean, default: true },
    paymentChannels: Array,
    entries: Object,
});

const amountIdr = ref(props.minimumTopupIdr || 10000);
const paymentChannelCode = ref('');
const openedPaymentGroup = ref('');
const paymentGroups = computed(() => {
    const grouped = new Map();
    for (const channel of props.paymentChannels || []) {
        const code = channel.group || 'other';
        if (!grouped.has(code)) grouped.set(code, { code, title: channel.group_title || 'Metode Lainnya',
            description: channel.group_description || '', order: Number(channel.group_order ?? 9999), items: [] });
        grouped.get(code).items.push(channel);
    }
    return [...grouped.values()].sort((a, b) => a.order - b.order || a.title.localeCompare(b.title, 'id'));
});
const choosePaymentGroup = group => {
    openedPaymentGroup.value = openedPaymentGroup.value === group.code ? '' : group.code;
    if (openedPaymentGroup.value && !group.items.some(channel => channel.code === paymentChannelCode.value)) {
        paymentChannelCode.value = group.items[0]?.code || '';
    }
};
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
    <Head :title="customerText('pages.customer.wallet.attribute.title.32d4363a', 'Saldo')" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">{{ customerText("pages.customer.wallet.32d4363a", "Saldo") }}</h1>
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-6">
            <span class="text-slate-400">{{ customerText("pages.customer.wallet.d57dc459", "Saldo tersedia") }}</span>
            <strong class="mt-2 block text-3xl text-cyan-300">{{ rupiah(balanceIdr) }}</strong>
        </div>

        <section v-if="topupEnabled" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
            <div>
                <h2 class="text-xl font-semibold">{{ customerText("pages.customer.wallet.1b8225c4", "Top up saldo") }}</h2>
                <p class="text-sm text-slate-400">Minimum {{ rupiah(minimumTopupIdr) }}. Total pembayaran ditampilkan sebelum top up.</p>
            </div>
            <label class="block text-sm">{{ customerText("pages.customer.wallet.a2defbe3", "Nominal") }}
                <input v-model.number="amountIdr" type="number" step="1" :min="minimumTopupIdr" :disabled="Boolean(busy) || attemptLocked" class="mt-1 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2">
            </label>
            <span v-if="errors.amount_idr" class="text-xs text-red-300">{{ errors.amount_idr[0] }}</span>

            <div class="space-y-3" aria-label="Pilih metode pembayaran top up">
                <h3 class="text-sm font-semibold text-slate-100">Metode pembayaran</h3>
                <div v-for="group in paymentGroups" :key="group.code"
                    class="overflow-hidden rounded-xl border transition-colors"
                    :class="group.items.some(item => item.code === paymentChannelCode) ? 'border-cyan-400/70 bg-cyan-500/5' : 'border-slate-700 bg-slate-950/70'">
                    <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
                        :disabled="Boolean(busy) || attemptLocked" :aria-expanded="openedPaymentGroup === group.code"
                        @click="choosePaymentGroup(group)">
                        <span class="min-w-0"><strong class="block text-sm text-slate-100">{{ group.title }}</strong>
                            <small v-if="group.description" class="mt-1 block text-xs leading-relaxed text-slate-400">{{ group.description }}</small>
                            <small v-else class="mt-1 block text-xs text-slate-400">{{ group.items.length }} pilihan pembayaran</small>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <span v-if="group.items.some(item => item.code === paymentChannelCode)" class="rounded-full bg-cyan-400/15 px-2 py-1 text-[11px] font-semibold text-cyan-200">Dipilih</span>
                            <span class="text-slate-400" aria-hidden="true">{{ openedPaymentGroup === group.code ? '▴' : '▾' }}</span>
                        </span>
                    </button>
                    <div v-if="openedPaymentGroup !== group.code && ['va', 'ewallet'].includes(group.code)" class="lf-wallet-logo-strip border-t border-slate-700/80" aria-label="Logo metode pembayaran">
                        <span v-for="channel in group.items.filter(item => item.logo_url)" :key="channel.code" class="lf-wallet-logo">
                            <img :src="channel.logo_url" :alt="channel.name" loading="lazy">
                        </span>
                    </div>
                    <div v-if="openedPaymentGroup === group.code" class="grid gap-2 border-t border-slate-700/80" :class="['va', 'ewallet'].includes(group.code) ? 'grid-cols-2 p-2' : 'grid-cols-1 p-3 sm:grid-cols-2'" role="radiogroup" :aria-label="'Metode '+group.title">
                        <button v-for="channel in group.items" :key="channel.code" type="button" role="radio"
                            :aria-checked="paymentChannelCode === channel.code"
                            :disabled="Boolean(busy) || attemptLocked"
                            class="flex min-w-0 rounded-lg border text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-300 disabled:opacity-50"
                            :class="[paymentChannelCode === channel.code ? 'border-cyan-400 bg-cyan-400/10' : 'border-slate-700 bg-slate-900', ['va', 'ewallet'].includes(group.code) ? 'min-h-[94px] flex-col items-start justify-between gap-2 p-2.5' : 'min-h-[64px] items-center gap-3 px-3 py-3']"
                            @click="paymentChannelCode = channel.code">
                            <span class="flex shrink-0 items-center justify-center rounded-lg border border-slate-700 bg-slate-950" :class="['va', 'ewallet'].includes(group.code) ? 'h-7 w-16 px-1' : 'size-10'">
                                <img v-if="channel.logo_url" :src="channel.logo_url" :alt="channel.name" class="max-h-full max-w-full object-contain">
                                <span v-else class="text-xs font-semibold text-cyan-300">{{ channel.name.slice(0,2).toUpperCase() }}</span>
                            </span>
                            <span class="min-w-0 w-full flex-1"><strong class="block break-words text-xs leading-snug text-slate-100">{{ channel.name }}</strong>
                                <small v-if="channel.description && !['va', 'ewallet'].includes(group.code)" class="mt-0.5 block text-xs leading-snug text-slate-400">{{ channel.description }}</small></span>
                            <span class="shrink-0 text-cyan-300" aria-hidden="true">{{ paymentChannelCode === channel.code ? '●' : '○' }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <span v-if="errors.payment_channel_code" class="text-xs text-red-300">{{ errors.payment_channel_code[0] }}</span>

            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="Boolean(busy) || attemptLocked || !paymentChannelCode" class="rounded-lg bg-slate-700 px-4 py-2 disabled:opacity-50" @click="loadQuote">{{ customerText("pages.customer.wallet.60751a0", "Hitung total") }}</button>
                <button type="button" :disabled="busy || result || !quoteMatches" class="rounded-lg bg-cyan-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50" @click="createTopup">{{busy === 'topup' ? 'Menyiapkan…' : attemptLocked ? 'Cek percobaan yang sama' : 'Top up'}}</button>
            </div>
            <p v-if="!paymentChannels?.length" class="text-sm text-slate-400">{{ customerText("pages.customer.wallet.8879a24e", "Metode top up belum tersedia. Hubungi bantuan.") }}</p>
            <p v-if="attemptLocked && !result" role="status" class="text-sm text-amber-200">{{ customerText("pages.customer.wallet.2d62f6cc", "Jika status belum pasti, coba cek percobaan yang sama. Jangan membuat top up baru.") }}</p>
            <div v-if="quoteMatches" class="space-y-2 rounded-xl border border-cyan-400/30 bg-slate-950 p-4 text-sm" aria-live="polite">
                <div class="flex items-center justify-between gap-3"><span class="text-slate-400">Metode</span><strong class="text-right">{{ paymentChannels?.find(channel => channel.code === paymentChannelCode)?.name || paymentChannelCode }}</strong></div>
                <div class="flex items-center justify-between gap-3"><span>{{ customerText("pages.customer.wallet.dbcf71f5", "Saldo masuk") }}</span><span>{{ rupiah(quote.amount_idr) }}</span></div>
                <div class="flex items-center justify-between gap-3 text-slate-300"><span>Biaya pembayaran</span><span>{{ rupiah(Math.max(0, Number(quote.total_idr || 0) - Number(quote.amount_idr || 0))) }}</span></div>
                <div class="flex items-center justify-between gap-3 border-t border-slate-700 pt-3 text-base font-semibold"><span>{{ customerText("pages.customer.wallet.4cb761ce", "Total bayar") }}</span><strong class="text-cyan-300">{{ rupiah(quote.total_idr) }}</strong></div>
            </div>
            <div v-if="result?.payment" class="space-y-2 rounded-lg border border-cyan-500/30 bg-cyan-500/10 p-3 text-sm">
                <p>{{ customerText("pages.customer.wallet.a86dd943", "Status pembayaran:") }} <strong>{{ statusLabel(result.payment.status) }}</strong></p>
                <p v-if="result.payment.status === 'PENDING' && result.payment.instructions?.va_number">{{ customerText("pages.customer.wallet.d5aec755", "Nomor VA:") }} <strong>{{ result.payment.instructions.va_number }}</strong></p>
                <p v-if="result.payment.instructions?.payment_code">{{ customerText("pages.customer.wallet.6eb1fae2", "Kode:") }} <strong>{{ result.payment.instructions.payment_code }}</strong></p>
                <a v-if="result.payment.instructions?.payment_url" :href="result.payment.instructions.payment_url" class="text-cyan-200 underline">{{ customerText("pages.customer.wallet.52741a92", "Buka pembayaran") }}</a>
            </div>
            <p v-if="errors.payment" class="text-sm text-red-300">{{ errors.payment[0] }}</p>
            <p v-if="errors.topup" class="text-sm text-red-300">{{ errors.topup[0] }}</p>
        </section>
        <section v-else class="rounded-xl border border-slate-800 bg-slate-900 p-5"><h2 class="text-xl font-semibold">Top up saldo</h2><p class="mt-2 text-sm text-slate-400">Top up saldo sedang dinonaktifkan oleh toko.</p></section>

        <h2 class="text-xl font-semibold">{{ customerText("pages.customer.wallet.6d61cbf3", "Riwayat saldo") }}</h2>
        <p v-if="!entries.data.length" class="text-slate-400">{{ customerText("pages.customer.wallet.88aeaea4", "Belum ada mutasi saldo.") }}</p>
        <div v-else class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full min-w-[500px] text-left text-sm">
                <thead class="bg-slate-900 text-slate-400"><tr><th class="p-3">{{ customerText("pages.customer.wallet.b4082553", "Tanggal") }}</th><th class="p-3">{{ customerText("pages.customer.wallet.1c5a8d76", "Jenis") }}</th><th class="p-3">{{ customerText("pages.customer.wallet.e2d8203d", "Perubahan") }}</th><th class="p-3">{{ customerText("pages.customer.wallet.89c777e5", "Saldo akhir") }}</th></tr></thead>
                <tbody><tr v-for="entry in entries.data" :key="entry.id" class="border-t border-slate-800"><td class="p-3">{{ entry.created_at }}</td><td class="p-3">{{ entry.source }}</td><td class="p-3">{{ rupiah(entry.amount_idr) }}</td><td class="p-3">{{ rupiah(entry.balance_after_idr) }}</td></tr></tbody>
            </table>
        </div>
        <CustomerPagination :links="entries.links || []" :label="customerText('pages.customer.wallet.attribute.aria-label.9d63c7d7', 'Halaman riwayat saldo')" />
    </AccountShell>
</template>

<style scoped>
/* Keep every payment logo in one line, shrinking them as channels are added. */
.lf-wallet-logo-strip {
    box-sizing: border-box;
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: 4px;
    min-width: 0;
    width: 100%;
    overflow: hidden;
    padding: 8px 12px;
}
.lf-wallet-logo {
    box-sizing: border-box;
    display: flex;
    flex: 0 1 56px;
    align-items: center;
    justify-content: center;
    min-width: 0;
    max-width: 56px;
    aspect-ratio: 2 / 1;
    padding: 2px 3px;
    border-radius: 4px;
    background: rgba(30, 41, 59, .8);
}
.lf-wallet-logo img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
}
</style>
