<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    product: Object,
    packages: Array,
    fields: Array,
    customer: Object,
    faviconUrl: String,
});

const selectedPackageId = ref('');
const customerInput = reactive(Object.fromEntries(props.fields.map((field) => [field.field_key, ''])));
const guestEmail = ref(props.customer?.email || '');
const guestPhone = ref(props.customer?.phone || '');
const voucherCode = ref('');
const quote = ref(null);
const nicknameResult = ref(null);
const checkoutResult = ref(null);
const errors = ref({});
const busy = ref('');
const idempotencyKey = ref(newIdempotencyKey());

const selectedPackage = computed(() => props.packages.find((item) => String(item.id) === String(selectedPackageId.value)));

watch([selectedPackageId, voucherCode, guestEmail, guestPhone], () => {
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
    busy.value = 'order';
    try {
        checkoutResult.value = await postJson('/checkout/orders', {
            ...basePayload(),
            customer_input: { ...customerInput },
            idempotency_key: idempotencyKey.value,
        });
        quote.value = {
            subtotal_idr: checkoutResult.value.total_idr,
            discount_idr: 0,
            fee_idr: 0,
            total_idr: checkoutResult.value.total_idr,
        };
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
    <main class="min-h-screen bg-[#090e1b] text-slate-100">
        <header class="border-b border-white/10 bg-[#0c1424] px-5 py-4">
            <div class="mx-auto flex max-w-6xl items-center justify-between">
                <Link href="/" class="font-bold text-cyan-300">LFAMILIA STORE</Link>
                <Link href="/orders/check" class="text-sm text-slate-300">Cek pesanan</Link>
            </div>
        </header>

        <div class="mx-auto max-w-6xl space-y-7 px-5 py-8">
            <Link href="/" class="text-sm text-cyan-300">← Katalog</Link>

            <img v-if="product.banner_url" :src="product.banner_url" :alt="'Banner ' + product.name" class="max-h-64 w-full rounded-xl object-contain">

            <div class="flex items-center gap-5">
                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-24 w-24 rounded-xl object-cover">
                <div>
                    <p class="text-sm text-cyan-300">{{ product.category_name }}</p>
                    <h1 class="text-3xl font-bold">{{ product.name }}</h1>
                    <p v-if="product.description" class="mt-2 max-w-2xl text-sm text-slate-400">{{ product.description }}</p>
                </div>
            </div>

            <section v-if="fields.length" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div><h2 class="text-xl font-semibold">Data tujuan</h2><p class="mt-1 text-sm text-slate-400">Isi data sesuai akun atau tujuan produk.</p></div>
                <div class="grid gap-3 md:grid-cols-2">
                    <label v-for="field in fields" :key="field.field_key" class="text-sm">
                        {{ field.label }}{{ field.is_required ? ' *' : '' }}
                        <input
                            v-model="customerInput[field.field_key]"
                            :type="field.type === 'email' ? 'email' : field.type === 'tel' ? 'tel' : 'text'"
                            :required="field.is_required"
                            maxlength="255"
                            class="mt-1 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 focus:border-cyan-400 focus:outline-none"
                        >
                        <span v-if="fieldError(field.field_key)" class="mt-1 block text-xs text-red-300">{{ fieldError(field.field_key) }}</span>
                    </label>
                </div>
                <button v-if="product.nickname_check_enabled" type="button" :disabled="busy === 'nickname'" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold disabled:opacity-50" @click="checkNickname">
                    {{ busy === 'nickname' ? 'Memeriksa...' : 'Cek nickname' }}
                </button>
                <div v-if="nicknameResult?.verified" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">
                    Nickname: <strong>{{ nicknameResult.nickname }}</strong><span v-if="nicknameResult.country"> · {{ nicknameResult.country }}</span>
                </div>
                <div v-else-if="nicknameResult?.warning" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">{{ nicknameResult.warning }}</div>
            </section>

            <section class="space-y-4">
                <h2 class="text-xl font-semibold">Pilih nominal</h2>
                <p v-if="!packages.length" class="text-slate-400">Belum ada nominal aktif.</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="item in packages"
                        :key="item.id"
                        type="button"
                        :disabled="!item.is_available"
                        class="flex items-center justify-between gap-3 rounded-xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                        :class="String(selectedPackageId) === String(item.id) ? 'border-cyan-400 bg-cyan-400/10' : 'border-slate-800 bg-slate-900'"
                        @click="selectedPackageId = item.id"
                    >
                        <div class="flex items-center gap-3">
                            <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="h-12 w-12 rounded-md object-contain">
                            <span class="font-semibold">{{ item.name }}</span>
                        </div>
                        <span class="text-sm text-cyan-300">{{ item.is_available ? formatIdr(item.price_idr) : 'Tidak tersedia' }}</span>
                    </button>
                </div>
                <span v-if="errors.package_id" class="text-sm text-red-300">{{ errors.package_id[0] }}</span>
            </section>

            <section class="grid gap-5 lg:grid-cols-[1fr_380px]">
                <div class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <div v-if="!customer" class="grid gap-3 md:grid-cols-2">
                        <label class="text-sm">Email guest<input v-model="guestEmail" type="email" maxlength="255" class="mt-1 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"></label>
                        <label class="text-sm">Nomor HP guest<input v-model="guestPhone" type="tel" maxlength="32" class="mt-1 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"></label>
                        <span v-if="errors.guest_email" class="text-xs text-red-300">{{ errors.guest_email[0] }}</span>
                        <span v-if="errors.guest_phone" class="text-xs text-red-300">{{ errors.guest_phone[0] }}</span>
                    </div>
                    <div v-else class="rounded-lg bg-slate-950 p-3 text-sm text-slate-300">
                        Checkout sebagai <strong>{{ customer.name }}</strong> · {{ customer.phone || 'nomor HP belum lengkap' }}
                    </div>

                    <label class="block text-sm">Voucher
                        <div class="mt-1 flex gap-2">
                            <input v-model="voucherCode" maxlength="100" placeholder="Kode voucher" class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 uppercase">
                            <button type="button" :disabled="busy === 'quote' || !selectedPackage" class="rounded-lg bg-slate-700 px-4 py-2 font-semibold disabled:opacity-50" @click="loadQuote">Pakai</button>
                        </div>
                    </label>
                    <span v-if="errors.voucher_code" class="text-sm text-red-300">{{ errors.voucher_code[0] }}</span>
                    <p class="text-xs text-slate-500">Harga final dihitung ulang oleh server. Data harga/provider dari browser tidak digunakan.</p>
                </div>

                <aside class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-xl font-semibold">Ringkasan</h2>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span>Nominal</span><span>{{ selectedPackage?.name || '-' }}</span></div>
                        <div class="flex justify-between"><span>Harga</span><span>{{ formatIdr(quote?.subtotal_idr ?? selectedPackage?.price_idr) }}</span></div>
                        <div class="flex justify-between"><span>Diskon</span><span>- {{ formatIdr(quote?.discount_idr || 0) }}</span></div>
                        <div class="flex justify-between"><span>Biaya pembayaran</span><span>{{ formatIdr(quote?.fee_idr || 0) }}</span></div>
                        <div class="flex justify-between border-t border-slate-700 pt-3 text-base font-bold"><span>Total</span><span class="text-cyan-300">{{ formatIdr(quote?.total_idr ?? selectedPackage?.price_idr) }}</span></div>
                    </div>
                    <button type="button" :disabled="busy === 'order' || !selectedPackage" class="w-full rounded-lg bg-cyan-400 px-4 py-3 font-bold text-slate-950 disabled:opacity-50" @click="createOrder">
                        {{ busy === 'order' ? 'Membuat pesanan...' : 'Buat pesanan' }}
                    </button>
                    <p class="text-xs text-slate-500">Pesanan dibuat sebagai Menunggu Pembayaran. Metode pembayaran akan dipilih pada alur pembayaran.</p>
                    <p v-if="errors.checkout" class="text-sm text-red-300">{{ errors.checkout[0] }}</p>
                </aside>
            </section>

            <section v-if="checkoutResult" class="space-y-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-5">
                <h2 class="text-xl font-semibold text-emerald-200">Pesanan berhasil dibuat</h2>
                <p class="text-sm">Nomor pesanan: <strong>{{ checkoutResult.order_number }}</strong></p>
                <p v-if="checkoutResult.access_code" class="break-all text-sm">Kode akses guest: <strong>{{ checkoutResult.access_code }}</strong></p>
                <p v-if="checkoutResult.access_code" class="text-xs text-amber-200">Simpan kode akses ini untuk mengecek pesanan dari perangkat lain.</p>
                <a :href="checkoutResult.status_url" class="inline-block rounded-lg bg-emerald-300 px-4 py-2 font-semibold text-slate-950">Lihat status pesanan</a>
            </section>
        </div>
    </main>
</template>
