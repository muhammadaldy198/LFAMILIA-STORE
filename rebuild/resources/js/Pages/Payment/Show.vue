<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';

const props = defineProps({
    order: { type: Object, required: true },
    payment: { type: Object, default: null },
    statusUrl: { type: String, required: true },
});

const page = usePage();
const storefront = computed(() => page.props.storefront || {});
const storeLogo = computed(() => storefront.value.assets?.logo?.url || '');

const paidStatuses = ['PAID', 'SETTLEMENT', 'CAPTURE', 'SUCCESS'];
const failedStatuses = ['FAILED', 'EXPIRED', 'REJECTED', 'CANCELLED', 'CANCEL', 'DENIED', 'DENY'];

const paymentStatus = computed(() => String(props.payment?.status || '').toUpperCase());
const paid = computed(() => paidStatuses.includes(paymentStatus.value) || ['PAID', 'PROCESSING', 'SUCCESS'].includes(String(props.order.status || '').toUpperCase()));
const failed = computed(() => failedStatuses.includes(paymentStatus.value) || ['FAILED', 'EXPIRED', 'CANCELLED'].includes(String(props.order.status || '').toUpperCase()));
const instructions = computed(() => props.payment?.instructions || {});
const paymentUrl = computed(() => instructions.value.redirect_url || instructions.value.payment_url || null);
const paymentNumber = computed(() => instructions.value.va_number || instructions.value.payment_code || null);
const qrUrl = computed(() => instructions.value.qr_url || null);
const qrString = computed(() => instructions.value.qr_string || null);

const paymentLabel = computed(() => {
    const code = String(props.payment?.channel_code || '').toLowerCase();
    const name = String(props.payment?.channel_name || props.payment?.channel_code || '').trim();
    const value = (code + ' ' + name).toLowerCase();
    if (value.includes('qris') || value.includes('qr')) return 'QRIS';
    if (value.includes('wallet') || value.includes('saldo') || value.includes('cash')) return 'LFAMILIA Cash';
    if (value.includes('va') || value.includes('virtual') || value.includes('bank')) return name ? 'Virtual Account • ' + name : 'Virtual Account';
    if (value.includes('dana') || value.includes('ovo') || value.includes('gopay') || value.includes('shopee')) return name ? 'E-Wallet • ' + name : 'E-Wallet';
    return name || '-';
});
const statusText = computed(() => paid.value
    ? 'Pembayaran sudah diterima. Status pesanan akan diperbarui otomatis.'
    : failed.value
        ? 'Transaksi ini tidak dapat dilanjutkan. Buat checkout baru bila diperlukan.'
        : 'Status diperiksa otomatis setiap 3 detik.');

function formatIdr(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0));
}

function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

async function copy(value) {
    if (!value) return;
    await navigator.clipboard.writeText(String(value));
}

let poller;
onMounted(() => {
    if (!paid.value && !failed.value) {
        poller = window.setInterval(() => {
            router.reload({ only: ['order', 'payment'], preserveScroll: true, preserveState: true });
        }, 3000);
    }
});
onBeforeUnmount(() => {
    if (poller) window.clearInterval(poller);
});
</script>

<template>
<Head title="Pembayaran" />
<CustomerShell>
<main class="lf-payment-page mx-auto min-h-[76vh] max-w-xl px-4 py-8 sm:py-12">
    <section class="lf-payment-card overflow-hidden rounded-xl border border-white/10 bg-[#0d1019] shadow-2xl">
        <div class="lf-payment-head border-b border-white/10 bg-gradient-to-br from-white/[0.035] via-transparent to-transparent p-5 sm:p-6">
            <div class="lf-payment-brand-row mb-5 flex items-center justify-between gap-4 border-b border-white/[0.07] pb-4">
                <div class="lf-payment-brand">
                    <img v-if="storeLogo" :src="storeLogo" alt="LFAMILIA STORE">
                    <span v-else class="lf-payment-brand-fallback">LF</span>
                    <div>
                        <strong>LFAMILIA</strong>
                        <small>STORE</small>
                    </div>
                </div>
                <button type="button" class="rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-right" @click="copy(order.order_number)">
                    <span class="block text-[8px] font-bold uppercase tracking-[0.16em] text-white/35">Invoice</span>
                    <span class="mt-0.5 block max-w-40 truncate font-mono text-[10px] font-black text-[#b9ff35]">{{order.order_number}}</span>
                </button>
            </div>

            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-[#b9ff35]">LFAMILIA PAYMENT</p>
                    <h1 class="mt-1 text-xl font-black">{{paid ? 'Pembayaran berhasil' : failed ? 'Pembayaran tidak aktif' : 'Selesaikan pembayaran'}}</h1>
                </div>
                <span class="lf-payment-status-icon" :class="{paid,failed}">
                    <svg v-if="paid" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v7c0 4.5 3.2 7 8 8.5 4.8-1.5 8-4 8-8.5V6z"/><path d="M12 8v5M12 16h.01"/></svg>
                </span>
            </div>
            <p class="mt-2 text-xs leading-5 text-white/45">
                Pembayaran diproses aman oleh LFAMILIA STORE.
            </p>
        </div>

        <div class="lf-payment-body p-5 sm:p-6">
            <div class="rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3">
                <strong class="text-[10px] text-amber-200">Simpan invoice sebelum membayar</strong>
                <p class="mt-1 text-[9px] leading-4 text-white/42">Invoice diperlukan untuk mengecek transaksi jika halaman pembayaran tertutup atau terjadi kendala.</p>
                <div class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-black/20 px-3 py-2.5">
                    <code class="break-all text-sm font-black tracking-wider text-white">{{order.order_number}}</code>
                    <button type="button" class="shrink-0 text-[10px] font-bold text-[#b9ff35]" @click="copy(order.order_number)">Salin Invoice</button>
                </div>
            </div>

            <dl class="mt-5 space-y-3 text-xs">
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Produk</dt><dd class="max-w-[65%] text-right font-bold">{{order.product_name}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Nominal</dt><dd class="max-w-[65%] text-right font-bold">{{order.package_name}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Metode</dt><dd class="max-w-[65%] text-right font-bold">{{paymentLabel}}</dd></div>
                <div class="h-px bg-white/[0.08]"></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Harga setelah promo</dt><dd class="font-bold">{{formatIdr(order.product_total_idr)}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Biaya pembayaran</dt><dd class="font-bold">{{formatIdr(order.fee_idr)}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Total pembayaran</dt><dd class="text-lg font-black text-[#b9ff35]">{{formatIdr(order.total_idr)}}</dd></div>
            </dl>

            <div v-if="!paid && !failed && qrUrl" class="mt-5 rounded-xl border border-white/10 bg-white p-4 text-center">
                <img :src="qrUrl" alt="QRIS pembayaran" class="mx-auto h-auto w-full max-w-[220px]">
                <p class="mt-3 text-[10px] font-black text-[#091006]">Scan QRIS untuk membayar</p>
            </div>

            <div v-if="!paid && !failed && qrString && !qrUrl" class="mt-5 rounded-xl border border-white/10 bg-white/[0.03] p-4">
                <p class="text-[9px] font-bold uppercase tracking-[0.14em] text-white/35">QRIS</p>
                <div class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-black/25 px-3 py-3">
                    <code class="break-all text-[10px] font-bold text-white">{{qrString}}</code>
                    <button type="button" class="shrink-0 text-[10px] font-bold text-[#b9ff35]" @click="copy(qrString)">Salin</button>
                </div>
            </div>

            <div v-if="!paid && !failed && paymentNumber" class="mt-5 rounded-xl border border-white/10 bg-white/[0.03] p-4">
                <p class="text-[9px] font-bold uppercase tracking-[0.14em] text-white/35">{{payment?.channel_name || 'Nomor pembayaran'}}</p>
                <div class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-black/25 px-3 py-3">
                    <code class="break-all text-base font-black tracking-wider text-white">{{paymentNumber}}</code>
                    <button type="button" class="shrink-0 text-[10px] font-bold text-[#b9ff35]" @click="copy(paymentNumber)">Salin</button>
                </div>
            </div>

            <p v-if="!paid && !failed && payment?.expires_at" class="lf-payment-expiry">Berlaku sampai {{formatDateTime(payment.expires_at)}}</p>

            <div class="lf-payment-status-box">
                <svg v-if="paid" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <span>{{statusText}}</span>
            </div>

            <a v-if="!paid && !failed && paymentUrl" :href="paymentUrl" class="lf-payment-primary mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006]">Bayar Sekarang</a>
            <Link v-if="paid" :href="statusUrl" class="lf-payment-primary mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006]">Lihat status pesanan</Link>

            <div class="lf-payment-actions mt-3 grid grid-cols-2 gap-2">
                <button type="button" class="h-10 rounded-lg border border-white/10 bg-white/[0.03] text-[10px] font-bold text-white" @click="router.reload({ only: ['order','payment'], preserveScroll: true })">Cek status</button>
                <Link :href="statusUrl" class="flex h-10 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-[10px] font-bold text-white">Cek invoice</Link>
            </div>
            <div class="lf-payment-support"><Link href="/contact">Butuh bantuan pembayaran?</Link></div>
        </div>
    </section>
</main>
</CustomerShell>
</template>
