<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';

const props = defineProps({
    order: { type: Object, required: true },
    payment: { type: Object, default: null },
    statusUrl: { type: String, required: true },
    pageSettings: { type: Object, default: () => ({}) },
});

const page = usePage();
const storefront = computed(() => page.props.storefront || {});
const storeLogo = computed(() => storefront.value.assets?.logo?.url || '');
const settings = computed(() => props.pageSettings || {});
const accent = computed(() => settings.value.accentColor || '#b9ff35');

const paidStatuses = ['PAID', 'SETTLEMENT', 'CAPTURE', 'SUCCESS'];
const expiredStatuses = ['EXPIRE', 'EXPIRED'];
const cancelledStatuses = ['CANCEL', 'CANCELLED'];
const failedStatuses = ['FAILED', 'REJECTED', 'DENIED', 'DENY'];

const paymentStatus = computed(() => String(props.payment?.status || '').toUpperCase());
const orderStatus = computed(() => String(props.order.status || '').toUpperCase());
const refunded = computed(() => paymentStatus.value === 'REFUNDED' || ['REFUND', 'REFUNDED'].includes(orderStatus.value));
const expired = computed(() => expiredStatuses.includes(paymentStatus.value) || orderStatus.value === 'EXPIRED');
const cancelled = computed(() => cancelledStatuses.includes(paymentStatus.value) || orderStatus.value === 'CANCELLED');
const retryRejected = computed(() => paymentStatus.value === 'REJECTED' && orderStatus.value === 'PENDING_PAYMENT');
const failed = computed(() => (failedStatuses.includes(paymentStatus.value) && !retryRejected.value) || orderStatus.value === 'FAILED');
const latePaid = computed(() => paidStatuses.includes(paymentStatus.value) && ['EXPIRED', 'CANCELLED', 'FAILED'].includes(orderStatus.value));
const paid = computed(() => !refunded.value && !expired.value && !cancelled.value && !failed.value
    && (paidStatuses.includes(paymentStatus.value) || ['PAID', 'PROCESSING', 'SUCCESS'].includes(orderStatus.value)));
const uncertain = computed(() => ['UNKNOWN', 'CREATING'].includes(paymentStatus.value) && !paid.value && !refunded.value);
const terminal = computed(() => paid.value || expired.value || cancelled.value || failed.value || refunded.value);
const canResume = computed(() => !terminal.value && (!props.payment || retryRejected.value));
const title = computed(() => {
    if (refunded.value) return 'Pengembalian dana';
    if (latePaid.value) return 'Pembayaran perlu diperiksa';
    if (paid.value) return settings.value.paidTitle || 'Pembayaran berhasil';
    if (expired.value) return settings.value.expiredTitle || 'Pembayaran kedaluwarsa';
    if (cancelled.value) return settings.value.cancelledTitle || 'Pembayaran dibatalkan';
    if (failed.value) return settings.value.failedTitle || 'Pembayaran gagal';
    if (uncertain.value) return 'Memastikan status pembayaran';
    if (retryRejected.value) return 'Pembayaran belum tersedia';
    return settings.value.pendingTitle || 'Selesaikan pembayaran';
});

const instructions = computed(() => props.payment?.instructions || {});
const paymentUrl = computed(() => instructions.value.redirect_url || instructions.value.payment_url || null);
const paymentNumber = computed(() => instructions.value.va_number || instructions.value.payment_code || null);
const qrUrl = computed(() => instructions.value.qr_url || null);
const qrString = computed(() => instructions.value.qr_string || null);
const manualQris = computed(() => instructions.value.kind === 'manual_qris'
    || String(props.payment?.channel_code || '').toLowerCase().includes('manual'));
const copiedInvoice = ref(false);
const copiedPayment = ref(false);
const openingPayment = ref(false);
const startingPayment = ref(false);
const paymentError = ref('');

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
const statusText = computed(() => {
    if (refunded.value) return 'Pesanan masuk proses pengembalian dana. Periksa status invoice atau hubungi bantuan.';
    if (latePaid.value) return 'Pembayaran terverifikasi setelah pesanan ditutup. Admin perlu memeriksa transaksi ini. Jangan membayar lagi.';
    if (paid.value) return settings.value.paidStatusText || 'Pembayaran sudah diterima. Status pesanan akan diperbarui otomatis.';
    if (expired.value) return settings.value.expiredStatusText || 'Waktu pembayaran sudah berakhir. Invoice ini tetap dapat dipakai untuk mengecek status transaksi.';
    if (cancelled.value) return settings.value.cancelledStatusText || 'Pembayaran dibatalkan. Invoice tetap tersimpan dan statusnya dapat dicek kapan saja.';
    if (failed.value) return settings.value.failedStatusText || 'Pembayaran gagal. Jangan membuat pesanan baru sebelum mengecek status invoice ini.';
    if (uncertain.value) return 'Status pembayaran sedang dipastikan. Jangan membayar ulang atau membuat pesanan baru. Periksa status invoice ini.';
    if (retryRejected.value) return 'Percobaan sebelumnya ditolak sebelum pembayaran dibuat. Kamu dapat menyiapkan pembayaran lagi untuk invoice yang sama.';
    return settings.value.pendingStatusText || 'Status diperiksa otomatis setiap 3 detik.';
});

function formatIdr(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0));
}

function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

async function copyValue(value, kind) {
    if (!value) return;
    await navigator.clipboard.writeText(String(value));
    const target = kind === 'invoice' ? copiedInvoice : copiedPayment;
    target.value = true;
    window.setTimeout(() => { target.value = false; }, 1600);
}

function openPayment() {
    if (!paymentUrl.value || terminal.value || uncertain.value || openingPayment.value) return;
    openingPayment.value = true;
    window.location.assign(paymentUrl.value);
}

function paymentAttemptStorageKey() {
    const retry = retryRejected.value ? ':retry:' + props.payment.id : '';
    return 'lfamilia:payment-attempt:' + props.order.order_number + retry;
}

const attemptKeys = new Map();
function paymentAttemptKey() {
    const storageKey = paymentAttemptStorageKey();
    if (attemptKeys.has(storageKey)) return attemptKeys.get(storageKey);
    let key;
    try {
        const stored = window.sessionStorage.getItem(storageKey);
        if (stored && /^[A-Za-z0-9:_-]{16,120}$/.test(stored)) key = stored;
    } catch {}
    key ||= globalThis.crypto?.randomUUID?.()
        || ('payment-' + Date.now() + '-' + Math.random().toString(36).slice(2));
    attemptKeys.set(storageKey, key);
    try { window.sessionStorage.setItem(storageKey, key); } catch {}
    return key;
}

const refreshingStatus = ref(false);
let disposed = false;
function refreshStatus() {
    if (disposed || refreshingStatus.value) return Promise.resolve();
    refreshingStatus.value = true;
    return new Promise(resolve => {
        router.reload({
            only: ['order', 'payment'], preserveScroll: true, preserveState: true,
            onFinish: () => { refreshingStatus.value = false; resolve(); },
        });
    });
}

async function resumePayment() {
    if (!canResume.value || startingPayment.value || refreshingStatus.value) return;

    startingPayment.value = true;
    paymentError.value = '';

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('/payments/orders/' + encodeURIComponent(props.order.order_number), {
            method: 'POST',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({
                idempotency_key: paymentAttemptKey(),
                access_code: null,
            }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Pembayaran belum dapat disiapkan.');
        }

        await refreshStatus();
    } catch (error) {
        paymentError.value = error.message || 'Pembayaran belum dapat disiapkan. Cek status sebelum mencoba lagi.';
        await refreshStatus();
    } finally {
        startingPayment.value = false;
    }
}

let poller;
function stopPolling() {
    if (poller) window.clearInterval(poller);
    poller = null;
}
function restorePage() {
    openingPayment.value = false;
    if (!document.hidden) void refreshStatus();
}
watch(terminal, value => { if (value) stopPolling(); });
onMounted(() => {
    window.addEventListener('pageshow', restorePage);
    const shouldResume = new URLSearchParams(window.location.search).get('resume') === '1';
    if (shouldResume && canResume.value) void resumePayment();
    if (!terminal.value) {
        poller = window.setInterval(() => {
            if (!terminal.value && !startingPayment.value && !document.hidden) void refreshStatus();
        }, 3000);
    }
});
onBeforeUnmount(() => {
    disposed = true;
    stopPolling();
    window.removeEventListener('pageshow', restorePage);
});
</script>

<template>
<Head title="Pembayaran" />
<CustomerShell>
<main class="lf-payment-page mx-auto min-h-[76vh] max-w-xl px-4 py-8 sm:py-12" :style="{ '--payment-accent': accent }">
    <section class="lf-payment-card overflow-hidden rounded-xl border border-white/10 bg-[#0d1019] shadow-2xl">
        <img v-if="settings.headerImageUrl" :src="settings.headerImageUrl" alt="" class="lf-payment-header-image">
        <div class="lf-payment-head border-b border-white/10 bg-gradient-to-br from-white/[0.035] via-transparent to-transparent p-5 sm:p-6">
            <div v-if="settings.showStoreBrand !== false" class="lf-payment-brand-row mb-5 flex items-center justify-between gap-4 border-b border-white/[0.07] pb-4">
                <div class="lf-payment-brand">
                    <img v-if="storeLogo" :src="storeLogo" alt="LFAMILIA STORE">
                    <span v-else class="lf-payment-brand-fallback">LF</span>
                    <div>
                        <strong>LFAMILIA</strong>
                        <small>STORE</small>
                    </div>
                </div>
                <button type="button" class="rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-right" @click="copyValue(order.order_number,'invoice')">
                    <span class="block text-[8px] font-bold uppercase tracking-[0.16em] text-white/35">Invoice</span>
                    <span class="mt-0.5 block max-w-40 truncate font-mono text-[10px] font-black text-[#b9ff35]">{{order.order_number}}</span>
                </button>
            </div>

            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-[0.2em]" :style="{color:accent}">{{settings.eyebrow || 'LFAMILIA PAYMENT'}}</p>
                    <h1 class="mt-1 text-xl font-black">{{title}}</h1>
                </div>
                <span class="lf-payment-status-icon" :class="{paid,failed:failed||expired||cancelled||refunded}">
                    <svg v-if="paid" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v7c0 4.5 3.2 7 8 8.5 4.8-1.5 8-4 8-8.5V6z"/><path d="M12 8v5M12 16h.01"/></svg>
                </span>
            </div>
            <p class="mt-2 text-xs leading-5 text-white/45">
                {{settings.subtitle || 'Pembayaran diproses aman oleh LFAMILIA STORE.'}}
            </p>
        </div>

        <div class="lf-payment-body p-5 sm:p-6">
            <div v-if="settings.showInvoiceNotice !== false" class="rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3">
                <strong class="text-[10px] text-amber-200">{{settings.invoiceNoticeTitle || 'Simpan invoice sebelum membayar'}}</strong>
                <p class="mt-1 text-[9px] leading-4 text-white/42">{{settings.invoiceNoticeText || 'Invoice diperlukan untuk mengecek transaksi jika halaman pembayaran tertutup atau terjadi kendala.'}}</p>
                <div class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-black/20 px-3 py-2.5">
                    <code class="break-all text-sm font-black tracking-wider text-white">{{order.order_number}}</code>
                    <button type="button" class="shrink-0 text-[10px] font-bold text-[#b9ff35]" @click="copyValue(order.order_number,'invoice')">{{copiedInvoice ? 'Tersalin' : 'Salin Invoice'}}</button>
                </div>
            </div>

            <dl v-if="settings.showOrderSummary !== false" class="mt-5 space-y-3 text-xs">
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Produk</dt><dd class="max-w-[65%] text-right font-bold">{{order.product_name}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Nominal</dt><dd class="max-w-[65%] text-right font-bold">{{order.package_name}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Metode</dt><dd class="max-w-[65%] text-right font-bold">{{paymentLabel}}</dd></div>
                <div class="h-px bg-white/[0.08]"></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Harga setelah promo</dt><dd class="font-bold">{{formatIdr(order.product_total_idr)}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Biaya pembayaran</dt><dd class="font-bold">{{formatIdr(order.fee_idr)}}</dd></div>
                <div class="flex items-start justify-between gap-4"><dt class="text-white/35">Total pembayaran</dt><dd class="text-lg font-black text-[#b9ff35]">{{formatIdr(order.total_idr)}}</dd></div>
            </dl>

            <div v-if="!terminal && !uncertain && qrUrl" class="mt-5 rounded-xl border border-white/10 bg-white p-4 text-center">
                <img :src="qrUrl" alt="QRIS pembayaran" class="mx-auto h-auto w-full max-w-[220px]">
                <p class="mt-3 text-[10px] font-black text-[#091006]">Scan QRIS untuk membayar</p>
                <p class="mt-1 text-[8px] text-black/55">Gunakan aplikasi bank atau e-wallet yang mendukung QRIS.</p>
            </div>

            <div v-if="!terminal && !uncertain && manualQris && qrUrl" class="mt-3 rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3">
                <strong class="text-[10px] text-amber-200">Konfirmasi QRIS manual</strong>
                <p class="mt-1 text-[9px] leading-4 text-white/45">Setelah pembayaran dilakukan, status tetap Menunggu Pembayaran sampai Admin memverifikasi transaksi. Jangan melakukan pembayaran kedua untuk invoice yang sama.</p>
            </div>

            <div v-if="!terminal && !uncertain && qrString && !qrUrl" class="mt-5 rounded-xl border border-white/10 bg-white/[0.03] p-4">
                <p class="text-[9px] font-bold uppercase tracking-[0.14em] text-white/35">QRIS</p>
                <div class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-black/25 px-3 py-3">
                    <code class="break-all text-[10px] font-bold text-white">{{qrString}}</code>
                    <button type="button" class="shrink-0 text-[10px] font-bold text-[#b9ff35]" @click="copyValue(qrString,'payment')">{{copiedPayment ? 'Tersalin' : 'Salin'}}</button>
                </div>
            </div>

            <div v-if="!terminal && !uncertain && paymentNumber" class="mt-5 rounded-xl border border-white/10 bg-white/[0.03] p-4">
                <p class="text-[9px] font-bold uppercase tracking-[0.14em] text-white/35">{{payment?.channel_name || 'Nomor pembayaran'}}</p>
                <div class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-black/25 px-3 py-3">
                    <code class="break-all text-base font-black tracking-wider text-white">{{paymentNumber}}</code>
                    <button type="button" class="shrink-0 text-[10px] font-bold text-[#b9ff35]" @click="copyValue(paymentNumber,'payment')">{{copiedPayment ? 'Tersalin' : 'Salin'}}</button>
                </div>
            </div>

            <p v-if="!terminal && payment?.expires_at" class="lf-payment-expiry">Berlaku sampai {{formatDateTime(payment.expires_at)}}</p>

            <div v-if="settings.showStatusBox !== false" class="lf-payment-status-box">
                <svg v-if="paid" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <span>{{statusText}}</span>
            </div>
            <p v-if="paymentError" role="alert" class="mt-3 text-center text-[10px] font-semibold text-rose-300">{{paymentError}}</p>

            <button v-if="canResume" type="button" :disabled="startingPayment || refreshingStatus" class="lf-payment-primary mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006] disabled:opacity-50" @click="resumePayment">{{startingPayment ? 'Menyiapkan…' : 'Siapkan pembayaran'}}</button>
            <button v-if="!terminal && !uncertain && paymentUrl" type="button" :disabled="openingPayment" class="lf-payment-primary mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006] disabled:opacity-50" @click="openPayment">{{openingPayment ? 'Memproses…' : (settings.payButtonText || 'Bayar Sekarang')}}</button>
            <Link v-if="paid || latePaid || refunded" :href="statusUrl" class="lf-payment-primary mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006]">Lihat status pesanan</Link>

            <div class="lf-payment-actions mt-3 grid grid-cols-2 gap-2">
                <button type="button" class="h-10 rounded-lg border border-white/10 bg-white/[0.03] text-[10px] font-bold text-white" :disabled="refreshingStatus || startingPayment" @click="refreshStatus">{{settings.checkStatusButtonText || 'Cek status'}}</button>
                <Link :href="statusUrl" class="flex h-10 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-[10px] font-bold text-white">{{settings.checkInvoiceButtonText || 'Cek invoice'}}</Link>
            </div>
            <div v-if="settings.showSupport !== false" class="lf-payment-support"><a :href="settings.supportUrl || '/contact'">{{settings.supportText || 'Butuh bantuan pembayaran?'}}</a></div>
        </div>
    </section>
</main>
</CustomerShell>
</template>
