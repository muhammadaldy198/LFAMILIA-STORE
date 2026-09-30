<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';
import CustomerShell from '../../Components/CustomerShell.vue';

const props = defineProps({
    product: Object,
    packages: Array,
    fields: Array,
    customer: Object,
    paymentChannels: Array,
    notices: Array,
    savedAccounts: Array,
    reviews: Array,
    reviewStats: Object,
    faqs: Array,
    faviconUrl: String,
});

const page = usePage();
const security = computed(() => page.props.security || {});

const selectedPackageId = ref('');
const savedAccountItems = ref([...(props.savedAccounts || [])]);
const customerInput = reactive(Object.fromEntries((props.fields || []).map((field) => [field.field_key, ''])));
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
const voucherOpen = ref(false);
const vouchers = ref([]);
const voucherLoading = ref(false);
const voucherError = ref('');
const confirmOpen = ref(false);
const agreed = ref(false);
const summaryOpen = ref(false);
const noticeOpen = ref(false);
const noticeIndex = ref(0);
const hideNotice = ref(false);
const selectedSavedId = ref('');
const turnstile = ref(null);
const turnstileToken = ref('');
const reviewRating = ref(5);
const reviewTitle = ref('');
const reviewBody = ref('');
const reviewOrderNumber = ref('');
const reviewPhone = ref('');
const reviewSaving = ref(false);
const reviewMessage = ref('');
const reviewError = ref('');

const selectedPackage = computed(() => (props.packages || []).find((item) => String(item.id) === String(selectedPackageId.value)));
const requiredFieldsComplete = computed(() => (props.fields || []).filter((field) => field.is_required)
    .every((field) => String(customerInput[field.field_key] || '').trim() !== ''));
const guestContactComplete = computed(() => props.customer || (
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail.value.trim())
    && guestPhone.value.replace(/\D/g, '').length >= 8
));
const canQuote = computed(() => Boolean(selectedPackage.value && paymentChannelCode.value && guestContactComplete.value));
const displayTotal = computed(() => quote.value?.total_idr ?? selectedPackage.value?.price_idr ?? 0);
const isVoucherProduct = computed(() => String(props.product?.category_slug || '').toLowerCase() === 'voucher');
const hasAccountStep = computed(() => !isVoucherProduct.value && (props.fields || []).length > 0);
const nominalStep = computed(() => hasAccountStep.value ? 2 : 1);
const paymentStep = computed(() => nominalStep.value + 1);
const contactStep = computed(() => nominalStep.value + 2);
const promoStep = computed(() => nominalStep.value + 3);
const firstCheckoutError = computed(() => {
    for (const messages of Object.values(errors.value || {})) {
        if (Array.isArray(messages) && messages.length) return messages[0];
        if (typeof messages === 'string' && messages) return messages;
    }
    return '';
});

const packageGroups = computed(() => {
    const groups = new Map();
    for (const item of props.packages || []) {
        const name = item.group_name || '';
        if (!groups.has(name)) groups.set(name, []);
        groups.get(name).push(item);
    }
    return [...groups.entries()].map(([name, items]) => ({ name, items }));
});

const paymentGroups = computed(() => {
    const labels = {
        wallet: ['LFAMILIA Cash', 'Bayar memakai saldo LFAMILIA Cash'],
        qris: ['QRIS', 'Scan dari semua aplikasi yang mendukung QRIS'],
        ewallet: ['E-Wallet', 'Bayar melalui aplikasi e-wallet yang tersedia'],
        va: ['Virtual Account', 'Transfer bank dengan nomor VA unik'],
        retail: ['Retail', 'Bayar melalui gerai retail yang tersedia'],
        other: ['Metode Lainnya', 'Metode pembayaran tersedia'],
    };
    const map = new Map();
    for (const channel of props.paymentChannels || []) {
        const key = channel.group || 'other';
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(channel);
    }
    const order = ['wallet', 'qris', 'ewallet', 'va', 'retail', 'other'];
    return [...map.entries()]
        .map(([key, items]) => ({
            key,
            title: labels[key]?.[0] || labels.other[0],
            description: labels[key]?.[1] || labels.other[1],
            items,
        }))
        .sort((left, right) => order.indexOf(left.key) - order.indexOf(right.key));
});

function noticeVersion(items) {
    let hash = 2166136261;
    for (const character of (items || []).map((item) => `${item.title}\n${item.body}`).join('\n---\n')) {
        hash ^= character.charCodeAt(0);
        hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(36);
}

function closeNotice() {
    if (hideNotice.value) {
        window.localStorage.setItem(
            `lfamilia-notice:${props.product.slug}:${noticeVersion(props.notices || [])}`,
            String(Date.now() + (7 * 24 * 60 * 60 * 1000)),
        );
    }
    noticeOpen.value = false;
}

function newIdempotencyKey() {
    return globalThis.crypto?.randomUUID?.() || ('checkout-' + Date.now() + '-' + Math.random().toString(36).slice(2));
}

function formatIdr(value) {
    return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
}

function formatDateId(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

function normalizeWhatsapp(value) {
    const text = String(value || '');
    const hasPlus = text.trim().startsWith('+');
    const digits = text.replace(/\D/g, '').slice(0, 16);
    return (hasPlus ? '+' : '') + digits;
}

function normalizePromo(value) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9_-]/g, '');
}

function nominalLabel(label, productName) {
    const cleanLabel = String(label || '').trim();
    const cleanProductName = String(productName || '').trim();
    if (!cleanProductName) return cleanLabel;

    const lowerLabel = cleanLabel.toLowerCase();
    const lowerProductName = cleanProductName.toLowerCase();
    const boundary = cleanLabel.slice(cleanProductName.length, cleanProductName.length + 1);
    if (lowerLabel.startsWith(lowerProductName) && (!boundary || /\s|[-–—|:]/.test(boundary))) {
        const directLabel = cleanLabel
            .slice(cleanProductName.length)
            .trimStart()
            .replace(/^(?:-|–|—|\||:)\s*/, '')
            .trim();
        if (directLabel) return directLabel;
    }

    const separated = cleanLabel.match(/^(.+?)\s+(?:-|–|—|\|)\s+(.+)$/);
    if (!separated) return cleanLabel;
    const normalize = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, '').replace(/s$/, '');
    return normalize(separated[1]) === normalize(cleanProductName) ? separated[2].trim() : cleanLabel;
}

function fieldError(key) {
    return errors.value['customer_input.' + key]?.[0];
}

async function submitReview() {
    reviewError.value = '';
    reviewMessage.value = '';
    if (reviewBody.value.trim().length < 5) {
        reviewError.value = 'Ulasan minimal 5 karakter.';
        return;
    }
    reviewSaving.value = true;
    try {
        await requestJson('/reviews', {
            method: 'POST',
            body: JSON.stringify({
                product_slug: props.product.slug,
                rating: Number(reviewRating.value),
                title: reviewTitle.value.trim() || null,
                body: reviewBody.value.trim(),
                ...(props.customer ? {} : {
                    order_number: reviewOrderNumber.value.trim().toUpperCase(),
                    phone: normalizeWhatsapp(reviewPhone.value),
                }),
            }),
        });
        reviewTitle.value = '';
        reviewBody.value = '';
        reviewOrderNumber.value = '';
        reviewPhone.value = '';
        reviewRating.value = 5;
        reviewMessage.value = 'Ulasanmu berhasil ditampilkan sebagai pembelian terverifikasi.';
        router.reload({ only: ['reviews', 'reviewStats'], preserveScroll: true, preserveState: true });
    } catch (error) {
        reviewError.value = error.message || 'Ulasan gagal disimpan.';
    } finally {
        reviewSaving.value = false;
    }
}

async function requestJson(url, options = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const response = await fetch(url, {
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            ...(options.headers || {}),
        },
        ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Permintaan gagal.';
        const error = new Error(message);
        error.validation = data.errors || { checkout: [message] };
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

function chooseSavedAccount(id) {
    selectedSavedId.value = id;
    const saved = savedAccountItems.value.find((item) => String(item.id) === String(id));
    if (!saved) return;
    Object.keys(customerInput).forEach((key) => { customerInput[key] = saved.customer_input?.[key] || ''; });
    nicknameResult.value = saved.nickname ? { verified: true, nickname: saved.nickname } : null;
}

async function checkNickname() {
    if (isVoucherProduct.value || !requiredFieldsComplete.value) return;
    errors.value = {};
    nicknameResult.value = null;
    busy.value = 'nickname';
    try {
        nicknameResult.value = await requestJson('/checkout/nickname', {
            method: 'POST',
            body: JSON.stringify({ product_id: props.product.id, customer_input: { ...customerInput } }),
        });
    } catch (error) {
        errors.value = error.validation || {};
    } finally {
        busy.value = '';
    }
}

function choosePackage(item) {
    if (!item.is_available) return;
    selectedPackageId.value = String(item.id);
    quote.value = null;
}

async function choosePayment(code) {
    paymentChannelCode.value = code;
    quote.value = null;
    if (canQuote.value) await loadQuote();
}

async function loadQuote() {
    if (!canQuote.value) return null;
    errors.value = {};
    busy.value = 'quote';
    try {
        quote.value = await requestJson('/checkout/quote', {
            method: 'POST',
            body: JSON.stringify(basePayload()),
        });
        return quote.value;
    } catch (error) {
        errors.value = error.validation || {};
        return null;
    } finally {
        busy.value = '';
    }
}

async function openVoucherPicker() {
    if (!selectedPackage.value) {
        errors.value = { package_id: ['Pilih nominal terlebih dahulu.'] };
        return;
    }
    voucherOpen.value = true;
    voucherLoading.value = true;
    voucherError.value = '';
    try {
        const data = await requestJson('/checkout/vouchers?package_id=' + encodeURIComponent(selectedPackage.value.id));
        vouchers.value = data.vouchers || [];
    } catch (error) {
        voucherError.value = error.message || 'Voucher gagal dimuat.';
    } finally {
        voucherLoading.value = false;
    }
}

async function applyVoucher(code) {
    voucherCode.value = code;
    voucherOpen.value = false;
    if (canQuote.value) await loadQuote();
}

async function prepareOrder() {
    errors.value = {};
    if (!requiredFieldsComplete.value) {
        errors.value = { checkout: ['Lengkapi data akun terlebih dahulu.'] };
        return;
    }
    if (!selectedPackage.value) {
        errors.value = { package_id: ['Pilih nominal terlebih dahulu.'] };
        return;
    }
    if (!paymentChannelCode.value) {
        errors.value = { payment_channel_code: ['Pilih metode pembayaran terlebih dahulu.'] };
        return;
    }
    if (!guestContactComplete.value) {
        errors.value = { guest_phone: ['Lengkapi email dan nomor WhatsApp dengan benar.'] };
        return;
    }
    if (!isVoucherProduct.value && props.product.nickname_check_enabled && !nicknameResult.value?.verified) {
        await checkNickname();
        if (!nicknameResult.value?.verified) {
            errors.value = { checkout: ['Nickname harus berhasil diverifikasi sebelum checkout.'] };
            return;
        }
    }
    const currentQuote = await loadQuote();
    if (!currentQuote) return;
    agreed.value = false;
    confirmOpen.value = true;
}

async function createOrder() {
    if (!agreed.value) return;
    confirmOpen.value = false;
    errors.value = {};
    checkoutResult.value = null;
    paymentResult.value = null;
    busy.value = 'order';
    try {
        checkoutResult.value = await requestJson('/checkout/orders', {
            method: 'POST',
            body: JSON.stringify({
                ...basePayload(),
                customer_input: { ...customerInput },
                idempotency_key: idempotencyKey.value,
                turnstile_token: turnstileToken.value || null,
            }),
        });
        quote.value = { ...(quote.value || {}), total_idr: checkoutResult.value.total_idr };

        paymentResult.value = await requestJson('/payments/orders/' + encodeURIComponent(checkoutResult.value.order_number), {
            method: 'POST',
            body: JSON.stringify({
                idempotency_key: paymentIdempotencyKey.value,
                access_code: checkoutResult.value.access_code || null,
            }),
        });

        window.location.assign('/payment?invoice=' + encodeURIComponent(checkoutResult.value.order_number));
    } catch (error) {
        errors.value = error.validation || { checkout: [error.message || 'Pesanan atau pembayaran gagal dibuat.'] };
    } finally {
        turnstile.value?.reset();
        busy.value = '';
    }
}

onMounted(() => {
    const preferredGroups = ['qris', 'ewallet', 'va', 'retail', 'other', 'wallet'];
    if (!paymentChannelCode.value) {
        for (const key of preferredGroups) {
            const group = paymentGroups.value.find((item) => item.key === key);
            const first = group?.items?.find((item) => item.available !== false);
            if (first?.code) {
                paymentChannelCode.value = first.code;
                break;
            }
        }
    }

    if ((props.notices || []).length) {
        noticeIndex.value = 0;
        hideNotice.value = false;
        const key = `lfamilia-notice:${props.product.slug}:${noticeVersion(props.notices || [])}`;
        const hiddenUntil = Number(window.localStorage.getItem(key) || 0);
        noticeOpen.value = hiddenUntil < Date.now();
    }
});

watch([guestEmail, guestPhone], () => {
    quote.value = null;
});

let nicknameAutoTimer;
watch(() => props.fields.map((field) => String(customerInput[field.field_key] || '')), () => {
    nicknameResult.value = null;
    selectedSavedId.value = '';
    if (nicknameAutoTimer) window.clearTimeout(nicknameAutoTimer);
    if (isVoucherProduct.value || !props.product.nickname_check_enabled || !requiredFieldsComplete.value) return;
    nicknameAutoTimer = window.setTimeout(() => {
        void checkNickname();
    }, 700);
});
</script>

<template>
<Head :title="product.name" />
<CustomerShell>
<main class="lf-checkout-page">
    <section class="lf-product-hero">
        <img v-if="product.banner_url || product.image_url" :src="product.banner_url || product.image_url" :alt="'Banner '+product.name">
        <div v-else class="lf-product-hero-placeholder"></div>
    </section>

    <section class="lf-product-identity">
        <div class="lf-container lf-product-identity-inner">
            <div class="lf-product-cover-3d">
                <img v-if="product.image_url" :src="product.image_url" :alt="product.name">
                <span v-else>{{product.name.slice(0,1)}}</span>
            </div>
            <div class="lf-product-title">
                <h1>{{product.name}}</h1>
                <p>{{product.publisher || product.category_name}}</p>
                <div v-if="reviewStats?.total" class="lf-product-rating"><span>★</span><strong>{{reviewStats.average}}</strong><small>{{reviewStats.total}} ulasan</small></div>
            </div>
            <div class="lf-product-perks">
                <div>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
                    <strong>Proses cepat</strong>
                </div>
                <div>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3z"/><path d="m9 12 2 2 4-4"/></svg></span>
                    <strong>Chat 24/7</strong>
                </div>
                <div>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></span>
                    <strong>Pembayaran aman</strong>
                </div>
            </div>
        </div>
    </section>

    <div class="lf-container">
        <div class="lf-checkout-tabs">
            <button type="button" :class="{active:activeTab==='transaction'}" @click="activeTab='transaction'">Transaksi</button>
            <button type="button" :class="{active:activeTab==='details'}" @click="activeTab='details'">Keterangan</button>
        </div>

        <div v-if="activeTab==='transaction'" class="lf-checkout-grid">
            <div class="lf-checkout-panels">
                <section v-if="hasAccountStep" class="lf-checkout-panel lf-checkout-account-panel">
                    <header><span>1</span><div><h2>Masukkan Data Akun</h2><p>Isi ID tujuan dengan benar. Nickname diperiksa otomatis jika didukung.</p></div></header>
                    <div class="lf-panel-body">
                        <div class="lf-account-product-mobile">
                            <span class="lf-account-product-art">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name">
                                <span v-else>{{product.name.slice(0,1)}}</span>
                            </span>
                            <div class="lf-account-product-copy">
                                <strong>{{product.name}}</strong>
                                <Link href="/#produk">Ganti produk</Link>
                            </div>
                        </div>
                        <label v-if="savedAccountItems.length" class="lf-saved-account-select">
                            <span>Akun game tersimpan</span>
                            <select v-model="selectedSavedId" @change="chooseSavedAccount(selectedSavedId)">
                                <option value="">Isi manual</option>
                                <option v-for="saved in savedAccountItems" :key="saved.id" :value="String(saved.id)">{{saved.label}}{{saved.nickname?' · '+saved.nickname:''}}</option>
                            </select>
                        </label>

                        <div class="lf-account-fields">
                            <label v-for="field in fields" :key="field.field_key">
                                <span>{{field.label}}{{field.is_required ? '' : ' (opsional)'}}</span>
                                <input v-model="customerInput[field.field_key]" :type="field.type==='email'?'email':field.type==='tel'?'tel':'text'" :required="field.is_required" maxlength="255" :placeholder="'Masukkan '+field.label">
                                <small v-if="fieldError(field.field_key)" class="lf-field-error">{{fieldError(field.field_key)}}</small>
                            </label>
                        </div>

                        <div v-if="product.nickname_check_enabled && busy==='nickname'" class="lf-nickname-state lf-nickname-loading">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/></svg>
                            <span>Memeriksa ID dan Server…</span>
                        </div>
                        <div v-else-if="nicknameResult?.verified" class="lf-nickname-state lf-nickname-success">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            <div><small>Akun ditemukan</small><strong>{{nicknameResult.nickname}}</strong><span v-if="nicknameResult.country">dari {{nicknameResult.country}}</span></div>
                        </div>
                        <div v-else-if="nicknameResult?.warning" class="lf-nickname-state lf-nickname-error">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                            <div><strong>Akun belum terverifikasi</strong><span>{{nicknameResult.warning}}</span></div>
                        </div>
                        <div v-else-if="product.nickname_check_enabled" class="lf-checkout-info-note">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                            <span>Nickname akan tampil otomatis setelah User ID dan Server yang diperlukan terisi.</span>
                        </div>
                        <div v-else class="lf-checkout-info-note">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                            <span>Verifikasi nickname otomatis belum tersedia. Periksa kembali data sebelum membayar.</span>
                        </div>
                        <div v-if="product.manual_instructions" class="lf-manual-instructions">
                            <strong>Instruksi produk manual</strong>
                            <p>{{product.manual_instructions}}</p>
                        </div>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-nominal-panel">
                    <header><span>{{nominalStep}}</span><div><h2>Pilih Nominal</h2><p>{{product.checkout_nominal_description || 'Pesanan diproses otomatis setelah pembayaran.'}}</p></div></header>
                    <div class="lf-panel-body lf-package-sections">
                        <section v-for="group in packageGroups" :key="group.name || 'all'">
                            <div v-if="group.name" class="lf-package-group-head"><h3>{{group.name}}</h3><span></span></div>
                            <div class="lf-nominal-grid">
                                <button v-for="item in group.items" :key="item.id" type="button" :disabled="!item.is_available" :class="{selected:String(selectedPackageId)===String(item.id)}" @click="choosePackage(item)">
                                    <span class="lf-nominal-top">
                                        <span class="lf-nominal-copy"><strong>{{nominalLabel(item.name, product.name)}}</strong><small v-if="item.note" class="lf-nominal-note">{{item.note}}</small></span>
                                        <img v-if="item.image_url" :src="item.image_url" :alt="nominalLabel(item.name, product.name)">
                                    </span>
                                    <b>{{item.is_available?formatIdr(item.price_idr):'Tidak tersedia'}}</b>
                                </button>
                            </div>
                        </section>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-payment-panel">
                    <header><span>{{paymentStep}}</span><div><h2>Pilih Pembayaran</h2><p>Pilih metode pembayaran yang ingin digunakan.</p></div></header>
                    <div class="lf-panel-body lf-payment-groups">
                        <div
                            v-for="group in paymentGroups"
                            :key="group.key"
                            class="lf-payment-group-card"
                            :class="{selected:group.items.some(x=>x.code===paymentChannelCode)}"
                        >
                            <button
                                type="button"
                                class="lf-payment-group-main"
                                :disabled="group.items.every(x=>x.available===false)"
                                @click="choosePayment(group.items.find(x=>x.code===paymentChannelCode)?.code || group.items.find(x=>x.available!==false)?.code)"
                            >
                                <span v-if="group.key==='wallet'" class="lf-payment-wallet-art">
                                    <img :src="'/payment/lfamilia-cash.webp'" alt="LFAMILIA Cash">
                                </span>
                                <span class="lf-payment-group-copy">
                                    <strong>{{group.title}}</strong>
                                    <small v-if="group.key==='wallet' && customer">Saldo {{formatIdr(customer.balance_idr)}}</small>
                                    <small v-else-if="group.key==='wallet'">Masuk akun untuk memakai saldo</small>
                                    <small v-else>{{group.description}}</small>
                                </span>
                                <span v-if="group.items.some(x=>x.code===paymentChannelCode)" class="lf-payment-check"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></span>
                            </button>

                            <div v-if="group.key!=='wallet'" class="lf-payment-brand-strip">
                                <span v-if="group.key==='qris'">QRIS • DANA • ShopeePay</span>
                                <template v-else>
                                    <span v-for="channel in group.items.slice(0,7)" :key="channel.code">{{channel.name}}</span>
                                </template>
                            </div>

                            <div
                                v-if="group.key!=='wallet' && group.key!=='qris' && group.items.some(x=>x.code===paymentChannelCode)"
                                class="lf-payment-channel-grid"
                            >
                                <button
                                    v-for="channel in group.items"
                                    :key="channel.code"
                                    type="button"
                                    :disabled="channel.available===false"
                                    :class="{selected:paymentChannelCode===channel.code}"
                                    @click="choosePayment(channel.code)"
                                >
                                    <span>{{channel.name}}</span>
                                </button>
                            </div>
                        </div>
                        <p v-if="!paymentChannels?.length" class="lf-warning-note">Belum ada metode pembayaran aktif.</p>
                        <p v-if="!customer" class="lf-payment-login-hint">Ingin membayar memakai saldo? <Link href="/login">Masuk atau daftar akun</Link>.</p>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-contact-panel">
                    <header><span>{{contactStep}}</span><div><h2>Data Pembeli</h2><p>Email dan WhatsApp digunakan untuk invoice serta status transaksi.</p></div></header>
                    <div class="lf-panel-body">
                        <div v-if="!customer" class="lf-account-fields">
                            <label><span>Email</span><input v-model="guestEmail" type="email" maxlength="255" placeholder="nama@email.com"></label>
                            <label><span>Nomor WhatsApp</span><input :value="guestPhone" type="tel" inputmode="tel" autocomplete="tel" maxlength="17" pattern="\\+?[0-9]{8,16}" placeholder="081234567890" @input="guestPhone=normalizeWhatsapp($event.target.value)"></label>
                        </div>
                        <div v-else class="lf-customer-checkout-note">
                            <span class="lf-account-avatar">{{customer.name?.slice(0,1)?.toUpperCase()}}</span>
                            <div><strong>{{customer.name}}</strong><small>{{customer.email}} · {{customer.phone || 'Nomor HP belum lengkap'}}</small></div>
                        </div>
                        <p class="lf-contact-privacy"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3z"/><path d="m9 12 2 2 4-4"/></svg><span>Kami hanya memakai kontak untuk invoice dan status transaksi.</span></p>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-promo-panel">
                    <header><span>{{promoStep}}</span><div><h2>Kode Promo</h2><p>Masukkan kode promo atau voucher diskon yang tersedia.</p></div></header>
                    <div class="lf-panel-body">
                        <div class="lf-promo-input">
                            <input :value="voucherCode" maxlength="100" placeholder="Ketik kode promo kamu" @input="voucherCode=normalizePromo($event.target.value)">
                            <button type="button" :disabled="busy==='quote'||!canQuote" @click="loadQuote">{{busy==='quote'?'Memeriksa...':'Gunakan'}}</button>
                        </div>
                        <button type="button" class="lf-available-promo" @click="openVoucherPicker"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 9a3 3 0 0 0 0 6v4a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-4a3 3 0 0 0 0-6V5a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg><span>Pakai Voucher Yang Tersedia</span></button>
                        <div v-if="quote?.voucher_code" class="lf-success-note">Voucher <strong>{{quote.voucher_code}}</strong> aktif · Hemat {{formatIdr(quote.discount_idr)}}</div>
                    </div>
                </section>

                <TurnstileWidget
                    v-if="security.turnstile_required"
                    ref="turnstile"
                    :site-key="security.turnstile_site_key"
                    :action="security.turnstile_action"
                    appearance="interaction-only"
                    @token="turnstileToken=$event"
                />

                <div v-if="Object.keys(errors).length" class="lf-checkout-errors lf-checkout-error-legacy">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span><strong>Periksa kembali checkout</strong><small>{{firstCheckoutError}}</small></span>
                </div>

            </div>

            <aside class="lf-order-summary">
                <h2>Ringkasan Pesanan</h2>
                <div class="lf-summary-product">
                    <img v-if="selectedPackage?.image_url || product.image_url" :src="selectedPackage?.image_url || product.image_url" :alt="product.name">
                    <span v-else class="lf-mobile-package-fallback">LF</span>
                    <div><strong>{{product.name}}</strong><small>{{selectedPackage ? nominalLabel(selectedPackage.name, product.name) : 'Pilih nominal'}}</small></div>
                </div>
                <dl>
                    <div><dt>Harga</dt><dd>{{formatIdr(selectedPackage?.price_idr)}}</dd></div>
                    <div v-if="quote?.discount_idr"><dt>Diskon</dt><dd class="lf-discount">-{{formatIdr(quote.discount_idr)}}</dd></div>
                    <div><dt>Biaya pembayaran</dt><dd>{{formatIdr(quote?.fee_idr)}}</dd></div>
                    <div class="total"><dt>Total</dt><dd>{{formatIdr(selectedPackage?.price_idr)}}</dd></div>
                </dl>
                <button type="button" class="lf-order-button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="prepareOrder">{{busy==='order'?'Memproses...':'Pesan Sekarang'}}</button>
                <p class="lf-summary-security">🔒 Harga dihitung server-side dan dikunci saat pesanan dibuat.</p>
            </aside>
        </div>

        <section v-else class="lf-product-details">
            <article class="lf-product-description lf-product-description-legacy">
                <h2>Deskripsi {{product.name}}</h2>
                <p>{{product.description || ('Top up ' + product.name + ' cepat, aman, dan diproses otomatis setelah pembayaran berhasil.')}}</p>
            </article>

            <section class="lf-product-review-section lf-review-legacy">
                <div class="lf-review-legacy-head">
                    <div>
                        <p class="lf-eyebrow">PENILAIAN PELANGGAN</p>
                        <h2>Ulasan & rating</h2>
                        <p>Ulasan hanya dapat dibuat setelah pembelian berhasil. Akun dan pembeli guest sama-sama bisa memberi ulasan terverifikasi.</p>
                    </div>
                    <div class="lf-review-average">
                        <span>★</span>
                        <strong>{{reviewStats?.total ? Number(reviewStats.average || 0).toFixed(1) : '–'}}</strong>
                        <small>{{reviewStats?.total || 0}} ulasan</small>
                    </div>
                </div>

                <div class="lf-review-legacy-grid">
                    <form class="lf-review-form" @submit.prevent="submitReview">
                        <div class="lf-review-form-intro">
                            <span class="lf-review-message-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
                            </span>
                            <div>
                                <h3>Bagikan pengalamanmu</h3>
                                <p>{{customer ? 'Pembelian dari akunmu akan diverifikasi otomatis.' : 'Tidak punya akun? Verifikasi pembelian dengan invoice dan nomor kontak checkout.'}}</p>
                            </div>
                        </div>

                        <div v-if="!customer" class="lf-review-guest-fields">
                            <input v-model="reviewOrderNumber" required maxlength="80" placeholder="Nomor invoice, contoh LFABC123">
                            <input :value="reviewPhone" required inputmode="tel" autocomplete="tel" maxlength="17" placeholder="Nomor kontak saat checkout" @input="reviewPhone=normalizeWhatsapp($event.target.value)">
                            <p>Invoice harus sudah lunas dan nomor kontak harus sama dengan data transaksi. Satu invoice hanya bisa memberi satu ulasan.</p>
                        </div>

                        <div class="lf-review-stars-input">
                            <button v-for="value in [1,2,3,4,5]" :key="value" type="button" :aria-label="value + ' bintang'" :class="{active:value<=reviewRating}" @click="reviewRating=value">★</button>
                        </div>

                        <input v-model="reviewTitle" maxlength="100" placeholder="Judul singkat (opsional)">
                        <textarea v-model="reviewBody" required minlength="5" maxlength="1200" placeholder="Ceritakan kecepatan proses dan pengalamanmu..."></textarea>
                        <p v-if="reviewMessage" class="lf-review-success">{{reviewMessage}}</p>
                        <p v-if="reviewError" class="lf-review-error">{{reviewError}}</p>
                        <button class="lf-review-submit" :disabled="reviewSaving">{{reviewSaving ? 'Menyimpan...' : 'Simpan ulasan'}}</button>
                    </form>

                    <div class="lf-review-list">
                        <article v-for="review in reviews" :key="review.id">
                            <div class="lf-review-card-head">
                                <div>
                                    <strong>{{review.display_name}}</strong>
                                    <span v-if="review.verified_purchase" class="lf-review-verified">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 12 2 2 4-4"/><path d="M12 2l2.1 2.1 3-.1.9 2.8 2.5 1.7-1 2.8 1 2.8-2.5 1.7-.9 2.8-3-.1L12 22l-2.1-2.1-3 .1-.9-2.8-2.5-1.7 1-2.8-1-2.8L6 7.2 6.9 4l3 .1z"/></svg>
                                        Pembelian terverifikasi
                                    </span>
                                </div>
                                <span class="lf-review-rating">★ {{review.rating}}</span>
                            </div>
                            <h3 v-if="review.title">{{review.title}}</h3>
                            <p>{{review.body}}</p>
                            <time v-if="review.published_at || review.created_at">{{formatDateId(review.published_at || review.created_at)}}</time>
                        </article>
                        <div v-if="!reviews?.length" class="lf-review-empty">Belum ada ulasan untuk produk ini.</div>
                    </div>
                </div>
            </section>

            <article v-if="faqs?.length" class="lf-product-faq lf-product-faq-legacy">
                <h2>Pertanyaan umum</h2>
                <div>
                    <details v-for="faq in faqs.slice(0,4)" :key="faq.id">
                        <summary>{{faq.question}}</summary>
                        <p>{{faq.answer}}</p>
                    </details>
                </div>
            </article>
        </section>
    </div>

    <div v-if="activeTab==='transaction'" class="lf-mobile-checkout-bar">
        <div class="lf-mobile-checkout-inner">
            <div v-if="summaryOpen" class="lf-mobile-summary-card">
                <button type="button" class="lf-mobile-summary-head" aria-expanded="true" @click="summaryOpen=false">
                    <span class="lf-mobile-summary-art">
                        <img v-if="selectedPackage?.image_url || product.image_url" :src="selectedPackage?.image_url || product.image_url" alt="">
                        <span v-else class="lf-mobile-package-fallback">LF</span>
                    </span>
                    <span class="lf-mobile-summary-copy">
                        <strong>Ringkasan pesanan</strong>
                        <small>{{product.name}} · {{selectedPackage ? nominalLabel(selectedPackage.name, product.name) : 'Pilih nominal'}}</small>
                    </span>
                    <strong class="lf-mobile-summary-price">{{formatIdr(selectedPackage?.price_idr)}}</strong>
                    <span class="lf-mobile-summary-chevron">⌄</span>
                </button>
                <dl class="lf-mobile-summary-lines">
                    <div><dt>Harga Satuan</dt><dd>{{formatIdr(selectedPackage?.price_idr)}}</dd></div>
                    <div><dt>Subtotal</dt><dd>{{formatIdr(selectedPackage?.price_idr)}}</dd></div>
                    <div><dt>Biaya Pembayaran</dt><dd>{{formatIdr(quote?.fee_idr)}}</dd></div>
                    <div class="total"><dt>Total Pembayaran</dt><dd>{{formatIdr(displayTotal)}}</dd></div>
                </dl>
            </div>
            <button v-else type="button" class="lf-mobile-summary-toggle" aria-expanded="false" @click="summaryOpen=true">
                <span><strong>Ringkasan pesanan</strong><small>Ketuk untuk melihat rincian</small></span>
                <span><strong>{{formatIdr(selectedPackage?.price_idr)}}</strong><b>⌃</b></span>
            </button>
            <button type="button" class="lf-mobile-order-button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="prepareOrder">
                <template v-if="busy==='order'">Memproses...</template>
                <template v-else>
                    <svg class="lf-mobile-order-lock" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    <span>Pesan Sekarang</span>
                </template>
            </button>
        </div>
    </div>

    <div v-if="noticeOpen && notices?.length" class="lf-modal-backdrop" @click.self="closeNotice">
        <div class="lf-product-notice-modal">
            <header>
                <span>{{noticeIndex + 1}}/{{notices.length}}</span>
                <button type="button" aria-label="Tutup informasi" @click="closeNotice">×</button>
            </header>
            <div class="lf-product-notice-body">
                <h2>{{notices[noticeIndex]?.title}}</h2>
                <p>{{notices[noticeIndex]?.body}}</p>
                <div v-if="notices.length > 1" class="lf-product-notice-actions">
                    <button type="button" class="lf-secondary" :disabled="noticeIndex===0" @click="noticeIndex=Math.max(0,noticeIndex-1)">Sebelumnya</button>
                    <button type="button" class="lf-primary" :disabled="noticeIndex===notices.length-1" @click="noticeIndex=Math.min(notices.length-1,noticeIndex+1)">Berikutnya</button>
                </div>
            </div>
            <label class="lf-product-notice-dismiss">
                <input v-model="hideNotice" type="checkbox">
                Jangan tampilkan lagi dalam 7 hari
            </label>
        </div>
    </div>

    <div v-if="voucherOpen" class="lf-modal-backdrop" @click.self="voucherOpen=false">
        <div class="lf-voucher-modal lf-voucher-legacy">
            <header>
                <div>
                    <h2>Voucher Yang Tersedia</h2>
                    <p>Pilih voucher untuk langsung menghitung diskon pada nominal pesananmu.</p>
                </div>
                <button type="button" aria-label="Tutup voucher" @click="voucherOpen=false">×</button>
            </header>
            <div v-if="voucherLoading" class="lf-voucher-state">Memuat voucher...</div>
            <div v-else-if="voucherError" class="lf-voucher-state lf-voucher-error">{{voucherError}}</div>
            <div v-else-if="vouchers.length" class="lf-voucher-list">
                <button
                    v-for="voucher in vouchers"
                    :key="voucher.code"
                    type="button"
                    :disabled="Number(selectedPackage?.price_idr || 0) < Number(voucher.minimum_total_idr || 0)"
                    @click="applyVoucher(voucher.code)"
                >
                    <span class="lf-voucher-copy">
                        <span class="lf-voucher-topline">
                            <strong>Voucher {{voucher.code}}</strong>
                            <b>{{voucher.code}}</b>
                        </span>
                        <small>{{voucher.discount_type==='PERCENT' ? 'Potongan '+voucher.discount_value+'%' : 'Potongan '+formatIdr(voucher.discount_value)}}</small>
                        <em>Minimum {{formatIdr(voucher.minimum_total_idr)}}<template v-if="voucher.ends_at"> · Berlaku hingga {{formatDateId(voucher.ends_at)}}</template><template v-if="Number(selectedPackage?.price_idr || 0) < Number(voucher.minimum_total_idr || 0)"> · Belum memenuhi minimum</template></em>
                    </span>
                </button>
            </div>
            <div v-else class="lf-voucher-state">Belum ada voucher yang aktif saat ini.</div>
        </div>
    </div>

    <div v-if="confirmOpen" class="lf-modal-backdrop" @click.self="confirmOpen=false">
        <div class="lf-confirm-modal lf-confirm-legacy">
            <div class="lf-confirm-status-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
            </div>
            <h2>Buat Pesanan</h2>
            <p class="lf-confirm-copy">{{isVoucherProduct ? 'Pastikan produk, nominal, dan pembayaran yang kamu pilih sudah sesuai.' : 'Pastikan data akun dan produk yang kamu pilih sudah valid dan sesuai.'}}</p>
            <dl>
                <div v-if="nicknameResult?.nickname"><dt>Username</dt><dd>{{nicknameResult.nickname}}</dd></div>
                <div v-for="field in fields" :key="field.field_key"><dt>{{field.label}}</dt><dd>{{customerInput[field.field_key] || '-'}}</dd></div>
                <div><dt>Item</dt><dd>{{selectedPackage ? nominalLabel(selectedPackage.name, product.name) : '-'}}</dd></div>
                <div><dt>Produk</dt><dd>{{product.name}}</dd></div>
                <div><dt>Payment</dt><dd>{{paymentGroups.find(group=>group.items.some(item=>item.code===paymentChannelCode))?.title || '-'}}</dd></div>
                <div><dt>Biaya Pembayaran</dt><dd>{{formatIdr(quote?.fee_idr)}}</dd></div>
                <div class="total"><dt>Total Bayar</dt><dd>{{formatIdr(quote?.total_idr)}}</dd></div>
            </dl>
            <label class="lf-confirm-agreement">
                <input v-model="agreed" type="checkbox">
                <span>Dengan melanjutkan, saya menyetujui syarat &amp; ketentuan yang berlaku.</span>
            </label>
            <div class="lf-confirm-actions">
                <button class="lf-primary" :disabled="busy==='order'||!agreed" @click="createOrder">{{busy==='order'?'Memproses...':'Pesan Sekarang'}}</button>
                <button class="lf-secondary" @click="confirmOpen=false">Batalkan</button>
            </div>
        </div>
    </div></main>
</CustomerShell>
</template>
