<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';
import CustomerShell from '../../Components/CustomerShell.vue';

const props = defineProps({
    product: Object,
    packages: Array,
    fields: Array,
    customer: Object,
    paymentChannels: Array,
    faviconUrl: String,
});

const page = usePage();
const security = computed(() => page.props.security || {});
const turnstile = ref(null);
const turnstileToken = ref('');
const selectedPackageId = ref('');
const customerInput = reactive(Object.fromEntries(props.fields.map((field) => [field.field_key, ''])));
const guestEmail = ref(props.customer?.email || '');
const guestPhone = ref(props.customer?.phone || '');
const voucherCode = ref('');
const paymentChannelCode = ref('');
const quote = ref(null);
const nicknameResult = ref(null);
const checkoutResult = ref(null);
const paymentResult = ref(null);
const errors = ref({});
const busy = ref('');
const activeTab = ref('transaction');
const idempotencyKey = ref(newIdempotencyKey());
const paymentIdempotencyKey = ref(newIdempotencyKey());

const selectedPackage = computed(() => props.packages.find((item) => String(item.id) === String(selectedPackageId.value)));

watch([selectedPackageId, voucherCode, guestEmail, guestPhone, paymentChannelCode], () => {
    quote.value = null;
    checkoutResult.value = null;
});

function newIdempotencyKey() {
    return globalThis.crypto?.randomUUID?.() || ('checkout-' + Date.now() + '-' + Math.random().toString(36).slice(2));
}

function formatIdr(value) {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
}

async function postJson(url, payload) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = data.message || 'Permintaan gagal.';
        const validation = data.errors || { checkout: [message] };
        const error = new Error(message);
        error.validation = validation;
        error.status = response.status;
        throw error;
    }
    return data;
}

function basePayload() {
    return {
        package_id: selectedPackageId.value ? Number(selectedPackageId.value) : null,
        payment_channel_code: paymentChannelCode.value || null,
        voucher_code: voucherCode.value.trim() || null,
        ...(props.customer ? {} : {
            guest_email: guestEmail.value.trim(),
            guest_phone: guestPhone.value.trim(),
        }),
    };
}

async function checkNickname() {
    errors.value = {};
    nicknameResult.value = null;
    busy.value = 'nickname';
    try {
        nicknameResult.value = await postJson('/checkout/nickname', {
            product_id: props.product.id,
            customer_input: { ...customerInput },
        });
    } catch (error) {
        errors.value = error.validation || {};
    } finally {
        busy.value = '';
    }
}

async function loadQuote() {
    errors.value = {};
    quote.value = null;
    busy.value = 'quote';
    try {
        quote.value = await postJson('/checkout/quote', basePayload());
    } catch (error) {
        errors.value = error.validation || {};
    } finally {
        busy.value = '';
    }
}

async function createOrder() {
    errors.value = {};
    checkoutResult.value = null;
    paymentResult.value = null;
    busy.value = 'order';
    try {
        checkoutResult.value = await postJson('/checkout/orders', {
            ...basePayload(),
            customer_input: { ...customerInput },
            idempotency_key: idempotencyKey.value,
            turnstile_token: turnstileToken.value || null,
        });
        quote.value = {
            ...(quote.value || {}),
            total_idr: checkoutResult.value.total_idr,
        };
    } catch (error) {
        errors.value = error.validation || {};
    } finally {
        turnstile.value?.reset();
        busy.value = '';
    }
}

async function startPayment() {
    if (!checkoutResult.value) return;
    errors.value = {};
    busy.value = 'payment';
    try {
        paymentResult.value = await postJson('/payments/orders/' + encodeURIComponent(checkoutResult.value.order_number), {
            idempotency_key: paymentIdempotencyKey.value,
            access_code: checkoutResult.value.access_code || null,
        });
        const redirect = paymentResult.value?.instructions?.redirect_url;
        if (redirect) window.location.assign(redirect);
    } catch (error) {
        errors.value = error.validation || {};
    } finally {
        busy.value = '';
    }
}

function fieldError(key) {
    return errors.value['customer_input.' + key]?.[0];
}
</script>

<template>
    <Head :title="product.name"><link v-if="faviconUrl" rel="icon" :href="faviconUrl"></Head>
    <CustomerShell>
        <main class="lf-checkout-page lf-container pb-24 pt-3 sm:pb-8 sm:pt-7">
            <section v-if="product.banner_url || product.image_url" class="relative -mx-4 mt-2 h-44 overflow-hidden bg-[#10131b] sm:-mx-6 sm:h-56 lg:-mx-8 lg:h-64">
                <img :src="product.banner_url || product.image_url" :alt="'Banner '+product.name" class="absolute inset-0 h-full w-full object-cover object-center">
            </section>

            <section class="relative z-10 -mx-4 min-h-[118px] overflow-visible border-y border-white/10 bg-[#202224] px-4 py-3 shadow-xl sm:-mx-6 sm:px-6 lg:-mx-8">
                <span class="absolute -top-12 left-4 block h-24 w-24 overflow-hidden rounded-[14px] border-[3px] border-[#202224] bg-[#111827] shadow-xl sm:-top-14 sm:left-6 sm:h-28 sm:w-28">
                    <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover">
                    <span v-else class="grid h-full w-full place-items-center text-2xl font-black text-[#b9ff35]">{{ product.name.slice(0,1) }}</span>
                </span>
                <div class="pl-28 pt-1 sm:pl-32">
                    <h1 class="m-0 text-sm font-black uppercase tracking-[.06em] text-white sm:text-base">{{ product.name }}</h1>
                    <p class="mt-1 text-[10px] font-medium text-white/55 sm:text-xs">{{ product.category_name }}</p>
                </div>
                <div class="absolute inset-x-4 bottom-3 grid grid-cols-3 gap-2 text-center text-[8px] text-white/50 sm:inset-x-6 sm:text-[9px]">
                    <span><b class="mb-1 block text-[#cfff72]">⚡</b>Proses cepat</span>
                    <span><b class="mb-1 block text-[#cfff72]">◉</b>Chat 24/7</span>
                    <span><b class="mb-1 block text-[#cfff72]">✓</b>Pembayaran aman</span>
                </div>
            </section>

            <div class="mx-auto mt-3 grid grid-cols-2 rounded-lg border border-white/[.07] bg-white/[.04] p-1 text-xs font-bold">
                <button type="button" class="rounded-md py-2 transition" :class="activeTab==='transaction' ? 'bg-[#bca17d] text-white' : 'text-white/50'" @click="activeTab='transaction'">Transaksi</button>
                <button type="button" class="rounded-md py-2 transition" :class="activeTab==='details' ? 'bg-[#bca17d] text-white' : 'text-white/50'" @click="activeTab='details'">Keterangan</button>
            </div>

            <div v-if="activeTab==='transaction'" class="mt-3 grid items-start gap-3 lg:grid-cols-[1fr_360px]">
                <div class="space-y-3">
                    <section v-if="fields.length" class="overflow-hidden rounded-lg border border-white/10 bg-[#2f3338]">
                        <header class="border-b border-white/[.08] bg-white/[.025] px-3 py-2.5 sm:px-4">
                            <div class="flex items-start gap-2"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-[#bca17d] text-xs font-black text-white">1</span><div><h2 class="m-0 text-[12px] font-black">Masukkan Data Akun</h2><p class="mt-0.5 text-[9px] text-white/45">Isi ID tujuan dengan benar. Nickname diperiksa otomatis jika didukung.</p></div></div>
                        </header>
                        <div class="p-3 sm:p-4">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label v-for="field in fields" :key="field.field_key" class="text-[10px] font-semibold text-white/70">
                                    {{ field.label }}{{ field.is_required ? ' *' : '' }}
                                    <input v-model="customerInput[field.field_key]" :type="field.type==='email'?'email':field.type==='tel'?'tel':'text'" :required="field.is_required" maxlength="255" class="mt-1 block h-9 w-full rounded-md border border-white/10 bg-white/[.035] px-3 text-[11px] text-white outline-none">
                                    <span v-if="fieldError(field.field_key)" class="mt-1 block text-[9px] text-red-300">{{ fieldError(field.field_key) }}</span>
                                </label>
                            </div>
                            <button v-if="product.nickname_check_enabled" type="button" :disabled="busy==='nickname'" class="lf-secondary mt-3" @click="checkNickname">{{ busy==='nickname'?'Memeriksa...':'Cek nickname' }}</button>
                            <div v-if="nicknameResult?.verified" class="mt-3 rounded-lg border border-emerald-400/30 bg-emerald-400/[.08] p-2.5 text-[10px] text-emerald-200">Nickname: <strong>{{ nicknameResult.nickname }}</strong><span v-if="nicknameResult.country"> · {{ nicknameResult.country }}</span></div>
                            <div v-else-if="nicknameResult?.warning" class="mt-3 rounded-lg border border-amber-300/20 bg-amber-300/[.05] p-2.5 text-[10px] text-amber-200">{{ nicknameResult.warning }}</div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-white/10 bg-[#2f3338] p-3 sm:p-4">
                        <div class="flex items-start gap-2"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-[#bca17d] text-xs font-black text-white">{{ fields.length ? '2' : '1' }}</span><div><h2 class="m-0 text-[12px] font-black">Pilih Nominal</h2><p class="mt-0.5 text-[9px] text-white/45">Pilih nominal yang ingin dibeli.</p></div></div>
                        <p v-if="!packages.length" class="mt-3 text-[10px] text-white/40">Belum ada nominal aktif.</p>
                        <div v-else class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <button v-for="item in packages" :key="item.id" type="button" :disabled="!item.is_available" class="flex min-h-[62px] items-center justify-between gap-2 rounded-lg border p-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-40" :class="String(selectedPackageId)===String(item.id)?'border-[#b9ff35] bg-[#b9ff35]/10':'border-white/10 bg-white/[.025]'" @click="selectedPackageId=item.id">
                                <span class="min-w-0"><strong class="block truncate text-[11px]">{{ item.name }}</strong><small class="mt-1 block text-[9px] text-white/35">{{ item.nominal_value || '' }}</small></span>
                                <strong class="shrink-0 text-[10px] text-[#cfff72]">{{ item.is_available ? formatIdr(item.price_idr) : 'Tidak tersedia' }}</strong>
                            </button>
                        </div>
                    </section>

                    <section class="rounded-lg border border-white/10 bg-[#2f3338] p-3 sm:p-4">
                        <div class="flex items-start gap-2"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-[#bca17d] text-xs font-black text-white">{{ fields.length ? '3' : '2' }}</span><div><h2 class="m-0 text-[12px] font-black">Pembayaran</h2><p class="mt-0.5 text-[9px] text-white/45">Isi kontak, pilih pembayaran, lalu gunakan voucher jika tersedia.</p></div></div>
                        <div v-if="!customer" class="mt-3 grid gap-2 sm:grid-cols-2">
                            <label class="text-[10px] font-semibold text-white/70">Email<input v-model="guestEmail" type="email" maxlength="255" class="mt-1 block h-9 w-full rounded-md border border-white/10 bg-white/[.035] px-3 text-[11px] text-white"></label>
                            <label class="text-[10px] font-semibold text-white/70">Nomor WhatsApp<input v-model="guestPhone" type="tel" maxlength="32" class="mt-1 block h-9 w-full rounded-md border border-white/10 bg-white/[.035] px-3 text-[11px] text-white"></label>
                        </div>
                        <div v-else class="mt-3 rounded-lg border border-white/[.07] bg-black/15 p-2.5 text-[10px] text-white/55">Checkout sebagai <strong class="text-white">{{ customer.name }}</strong> · {{ customer.phone || 'nomor HP belum lengkap' }}</div>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <button v-for="channel in paymentChannels" :key="channel.code" type="button" class="min-h-[42px] rounded-lg border px-3 py-2 text-left text-[10px] font-bold" :class="paymentChannelCode===channel.code?'border-[#b9ff35] bg-[#b9ff35]/10 text-white':'border-white/10 bg-white/[.025] text-white/65'" @click="paymentChannelCode=channel.code">{{ channel.name }}</button>
                        </div>
                        <p v-if="!paymentChannels.length" class="mt-2 text-[10px] text-amber-200">Belum ada metode pembayaran aktif.</p>
                        <label class="mt-3 block text-[10px] font-semibold text-white/70">Voucher
                            <div class="mt-1 flex gap-2"><input v-model="voucherCode" maxlength="100" placeholder="KODE VOUCHER" class="h-9 min-w-0 flex-1 rounded-md border border-white/10 bg-white/[.035] px-3 text-[11px] uppercase text-white"><button type="button" :disabled="busy==='quote'||!selectedPackage||!paymentChannelCode" class="lf-secondary" @click="loadQuote">Pakai</button></div>
                        </label>
                        <p class="mt-2 text-[9px] text-white/35">Harga final dihitung ulang oleh server. Data harga/provider dari browser tidak digunakan.</p>
                    </section>

                    <section v-if="checkoutResult" class="rounded-lg border border-emerald-400/30 bg-emerald-400/[.08] p-3 text-[10px]">
                        <h2 class="text-[13px] font-black text-emerald-200">Pesanan berhasil dibuat</h2><p class="mt-1">Nomor pesanan: <strong>{{ checkoutResult.order_number }}</strong></p><p v-if="checkoutResult.access_code" class="mt-1 break-all">Kode akses guest: <strong>{{ checkoutResult.access_code }}</strong></p><div class="mt-3 flex gap-2"><button class="lf-primary" :disabled="busy==='payment'" @click="startPayment">{{busy==='payment'?'Menyiapkan...':'Bayar sekarang'}}</button><a :href="checkoutResult.status_url" class="lf-secondary">Lihat status</a></div>
                    </section>
                    <section v-if="paymentResult" class="rounded-lg border border-[#b9ff35]/25 bg-[#b9ff35]/[.07] p-3 text-[10px]"><h2 class="text-[13px] font-black">Pembayaran</h2><p>Status: <strong>{{ paymentResult.status }}</strong></p><img v-if="paymentResult.instructions?.qr_url" :src="paymentResult.instructions.qr_url" class="mt-3 max-h-64 rounded-lg bg-white p-2"><p v-if="paymentResult.instructions?.va_number">Nomor VA: <strong>{{ paymentResult.instructions.va_number }}</strong></p><a v-if="paymentResult.instructions?.payment_url" :href="paymentResult.instructions.payment_url" class="lf-primary mt-3">Buka pembayaran</a></section>
                </div>

                <aside class="sticky top-[76px] rounded-lg border border-white/10 bg-[#2f3338] p-3">
                    <h2 class="m-0 text-[12px] font-black">Ringkasan pesanan</h2>
                    <dl class="mt-3 space-y-2 text-[10px]">
                        <div class="flex justify-between gap-3"><dt class="text-white/40">Produk</dt><dd class="m-0 max-w-[180px] truncate text-right font-semibold">{{ product.name }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-white/40">Nominal</dt><dd class="m-0 text-right font-semibold">{{ selectedPackage?.name || '-' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-white/40">Harga</dt><dd class="m-0 text-right font-semibold">{{ formatIdr(quote?.subtotal_idr ?? selectedPackage?.price_idr) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-white/40">Diskon</dt><dd class="m-0 text-right font-semibold">- {{ formatIdr(quote?.discount_idr || 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-white/40">Biaya pembayaran</dt><dd class="m-0 text-right font-semibold">{{ formatIdr(quote?.fee_idr || 0) }}</dd></div>
                        <div class="mt-2 flex justify-between gap-3 border-t border-white/10 pt-3"><dt class="font-bold">Total</dt><dd class="m-0 text-right text-[14px] font-black text-[#cfff72]">{{ formatIdr(quote?.total_idr ?? selectedPackage?.price_idr) }}</dd></div>
                    </dl>
                    <TurnstileWidget v-if="security.turnstile_required" ref="turnstile" class="mt-3" :site-key="security.turnstile_site_key" :action="security.turnstile_action" @token="turnstileToken=$event"/>
                    <button type="button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" class="mt-3 h-9 w-full rounded-md bg-[#bca17d] text-[11px] font-black text-white disabled:opacity-50" @click="createOrder">{{ busy==='order'?'Membuat pesanan...':'Pesan Sekarang' }}</button>
                    <p class="mt-2 text-[8px] leading-4 text-white/35">Gateway internal dipilih server dan tidak ditampilkan ke customer.</p>
                    <p v-if="errors.checkout" class="mt-2 text-[9px] text-red-300">{{ errors.checkout[0] }}</p>
                </aside>
            </div>

            <section v-else class="mt-3 rounded-lg border border-white/10 bg-[#2f3338] p-4">
                <h2 class="text-[13px] font-black">Keterangan</h2>
                <p class="mt-2 whitespace-pre-line text-[10px] leading-5 text-white/55">{{ product.description || 'Informasi produk akan ditampilkan di sini.' }}</p>
            </section>

            <div v-if="activeTab==='transaction'" class="fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-[#101217]/95 px-3 py-2 backdrop-blur lg:hidden">
                <div class="mx-auto flex max-w-xl items-center gap-3"><div class="min-w-0 flex-1"><small class="block text-[8px] text-white/40">{{ selectedPackage?.name || 'Pilih nominal' }}</small><strong class="block truncate text-[13px] text-[#cfff72]">{{ formatIdr(quote?.total_idr ?? selectedPackage?.price_idr) }}</strong></div><button type="button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" class="h-9 rounded-md bg-[#bca17d] px-5 text-[11px] font-black text-white disabled:opacity-50" @click="createOrder">Pesan Sekarang</button></div>
            </div>
        </main>
    </CustomerShell>
</template>
