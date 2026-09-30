<script setup>
import { Head, Link } from '@inertiajs/vue3';
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
const summaryOpen = ref(false);
const noticeOpen = ref(false);
const noticeIndex = ref(0);
const hideNotice = ref(false);
const selectedSavedId = ref('');
const turnstile = ref(null);
const turnstileToken = ref('');

const selectedPackage = computed(() => (props.packages || []).find((item) => String(item.id) === String(selectedPackageId.value)));
const requiredFieldsComplete = computed(() => (props.fields || []).filter((field) => field.is_required)
    .every((field) => String(customerInput[field.field_key] || '').trim() !== ''));
const guestContactComplete = computed(() => props.customer || (
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail.value.trim())
    && guestPhone.value.replace(/\D/g, '').length >= 8
));
const canQuote = computed(() => Boolean(selectedPackage.value && paymentChannelCode.value && guestContactComplete.value));
const displayTotal = computed(() => quote.value?.total_idr ?? selectedPackage.value?.price_idr ?? 0);

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
    return [...map.entries()].map(([key, items]) => ({
        key,
        title: labels[key]?.[0] || labels.other[0],
        description: labels[key]?.[1] || labels.other[1],
        items,
    }));
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

function fieldError(key) {
    return errors.value['customer_input.' + key]?.[0];
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

async function saveGameAccount() {
    if (!props.customer || !requiredFieldsComplete.value) return;
    const defaultLabel = nicknameResult.value?.nickname || props.product.name;
    const label = window.prompt('Nama akun tersimpan', defaultLabel);
    if (!label?.trim()) return;
    busy.value = 'save-account';
    errors.value = {};
    try {
        const saved = await requestJson('/account/game-accounts', {
            method: 'POST',
            body: JSON.stringify({
                product_id: props.product.id,
                label: label.trim(),
                customer_input: { ...customerInput },
            }),
        });
        savedAccountItems.value.unshift(saved);
        selectedSavedId.value = String(saved.id);
    } catch (error) {
        errors.value = error.validation || { checkout: [error.message] };
    } finally {
        busy.value = '';
    }
}

async function checkNickname() {
    if (!requiredFieldsComplete.value) return;
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
    if (props.product.nickname_check_enabled && !nicknameResult.value?.verified) {
        await checkNickname();
        if (!nicknameResult.value?.verified) {
            errors.value = { checkout: ['Nickname harus berhasil diverifikasi sebelum checkout.'] };
            return;
        }
    }
    const currentQuote = await loadQuote();
    if (!currentQuote) return;
    confirmOpen.value = true;
}

async function createOrder() {
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
            const first = group?.items?.[0];
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
    if (!props.product.nickname_check_enabled || !requiredFieldsComplete.value) return;
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
                <div><span>⚡</span><strong>Proses cepat</strong></div>
                <div><span>◉</span><strong>Chat 24/7</strong></div>
                <div><span>✓</span><strong>Pembayaran aman</strong></div>
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
                <section v-if="fields.length" class="lf-checkout-panel">
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
                                <span>{{field.label}}{{field.is_required?' *':''}}</span>
                                <input v-model="customerInput[field.field_key]" :type="field.type==='email'?'email':field.type==='tel'?'tel':'text'" :required="field.is_required" maxlength="255" :placeholder="'Masukkan '+field.label">
                                <small v-if="fieldError(field.field_key)" class="lf-field-error">{{fieldError(field.field_key)}}</small>
                            </label>
                        </div>

                        <div v-if="product.nickname_check_enabled" class="lf-nickname-actions">
                            <button type="button" :disabled="busy==='nickname'||!requiredFieldsComplete" class="lf-secondary" @click="checkNickname">{{busy==='nickname'?'Memeriksa...':'Cek Nickname'}}</button>
                            <button v-if="customer && nicknameResult?.verified" type="button" :disabled="busy==='save-account'" class="lf-secondary" @click="saveGameAccount">{{busy==='save-account'?'Menyimpan...':'Simpan Akun Game'}}</button>
                        </div>
                        <div v-if="nicknameResult?.verified" class="lf-success-note">Nickname ditemukan: <strong>{{nicknameResult.nickname}}</strong><span v-if="nicknameResult.country"> · {{nicknameResult.country}}</span></div>
                        <div v-else-if="nicknameResult?.warning" class="lf-warning-note">{{nicknameResult.warning}}</div>
                        <div v-else-if="!product.nickname_check_enabled" class="lf-checkout-info-note">ⓘ Verifikasi nickname otomatis belum tersedia. Periksa kembali data sebelum membayar.</div>
                    </div>
                </section>

                <section v-if="notices?.length" class="lf-product-notices">
                    <article v-for="notice in notices" :key="notice.id"><strong>{{notice.title}}</strong><p>{{notice.body}}</p></article>
                </section>

                <section class="lf-checkout-panel">
                    <header><span>2</span><div><h2>Pilih Nominal</h2><p>Pilih paket sesuai kebutuhanmu.</p></div></header>
                    <div class="lf-panel-body lf-package-sections">
                        <section v-for="group in packageGroups" :key="group.name || 'all'">
                            <h3 v-if="group.name">{{group.name}}</h3>
                            <div class="lf-nominal-grid">
                                <button v-for="item in group.items" :key="item.id" type="button" :disabled="!item.is_available" :class="{selected:String(selectedPackageId)===String(item.id)}" @click="choosePackage(item)">
                                    <img v-if="item.image_url" :src="item.image_url" :alt="item.name">
                                    <span v-else class="lf-nominal-fallback">◆</span>
                                    <span class="lf-nominal-copy"><strong>{{item.name}}</strong></span>
                                    <b>{{item.is_available?formatIdr(item.price_idr):'Tidak tersedia'}}</b>
                                </button>
                            </div>
                        </section>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-payment-panel">
                    <header><span>3</span><div><h2>Pilih Pembayaran</h2><p>Pilih metode pembayaran yang ingin digunakan.</p></div></header>
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
                                @click="choosePayment(group.items.find(x=>x.code===paymentChannelCode)?.code || group.items[0]?.code)"
                            >
                                <span v-if="group.key==='wallet'" class="lf-payment-wallet-art">
                                    <img src="/payment/lfamilia-cash.webp" alt="LFAMILIA Cash">
                                </span>
                                <span class="lf-payment-group-copy">
                                    <strong>{{group.title}}</strong>
                                    <small v-if="group.key==='wallet' && customer">Saldo {{formatIdr(customer.balance_idr)}}</small>
                                    <small v-else-if="group.key==='wallet'">Masuk akun untuk memakai saldo</small>
                                    <small v-else>{{group.description}}</small>
                                </span>
                                <span v-if="group.items.some(x=>x.code===paymentChannelCode)" class="lf-payment-check">✓</span>
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

                <section class="lf-checkout-panel">
                    <header><span>4</span><div><h2>Data Pembeli</h2><p>Email dan WhatsApp digunakan untuk invoice serta status transaksi.</p></div></header>
                    <div class="lf-panel-body">
                        <div v-if="!customer" class="lf-account-fields">
                            <label><span>Email *</span><input v-model="guestEmail" type="email" maxlength="255" placeholder="example@gmail.com"></label>
                            <label><span>No. WhatsApp *</span><input v-model="guestPhone" type="tel" maxlength="32" placeholder="08xxxxxxxxxx"></label>
                        </div>
                        <div v-else class="lf-customer-checkout-note">
                            <span class="lf-account-avatar">{{customer.name?.slice(0,1)?.toUpperCase()}}</span>
                            <div><strong>{{customer.name}}</strong><small>{{customer.email}} · {{customer.phone || 'Nomor HP belum lengkap'}}</small></div>
                        </div>
                        <p class="lf-contact-privacy">✓ Kami hanya memakai kontak untuk invoice dan status transaksi.</p>
                    </div>
                </section>

                <section class="lf-checkout-panel">
                    <header><span>5</span><div><h2>Kode Promo</h2><p>Masukkan kode voucher atau pilih promo yang tersedia.</p></div></header>
                    <div class="lf-panel-body">
                        <div class="lf-promo-input">
                            <input v-model="voucherCode" maxlength="100" placeholder="Ketik Kode Promo Kamu">
                            <button type="button" :disabled="busy==='quote'||!canQuote" @click="loadQuote">{{busy==='quote'?'Memeriksa...':'Gunakan'}}</button>
                        </div>
                        <button type="button" class="lf-available-promo" @click="openVoucherPicker">Pakai Voucher Yang Tersedia →</button>
                        <div v-if="quote?.voucher_code" class="lf-success-note">Voucher <strong>{{quote.voucher_code}}</strong> aktif · Hemat {{formatIdr(quote.discount_idr)}}</div>
                    </div>
                </section>

                <TurnstileWidget ref="turnstile" v-model="turnstileToken" action="checkout" />

                <div v-if="Object.keys(errors).length" class="lf-checkout-errors">
                    <strong>Periksa kembali checkout</strong>
                    <p v-for="(messages,key) in errors" :key="key">{{Array.isArray(messages)?messages[0]:messages}}</p>
                </div>

            </div>

            <aside class="lf-order-summary">
                <h2>Ringkasan Pesanan</h2>
                <div class="lf-summary-product">
                    <img v-if="selectedPackage?.image_url || product.image_url" :src="selectedPackage?.image_url || product.image_url" :alt="product.name">
                    <span v-else class="lf-mobile-package-fallback">LF</span>
                    <div><strong>{{product.name}}</strong><small>{{selectedPackage?.name || 'Pilih nominal'}}</small></div>
                </div>
                <dl>
                    <div><dt>Harga</dt><dd>{{formatIdr(selectedPackage?.price_idr)}}</dd></div>
                    <div v-if="quote?.discount_idr"><dt>Diskon</dt><dd class="lf-discount">-{{formatIdr(quote.discount_idr)}}</dd></div>
                    <div><dt>Biaya pembayaran</dt><dd>{{formatIdr(quote?.fee_idr)}}</dd></div>
                    <div class="total"><dt>Total</dt><dd>{{formatIdr(displayTotal)}}</dd></div>
                </dl>
                <button type="button" class="lf-order-button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="prepareOrder">{{busy==='order'?'Memproses...':'Pesan Sekarang'}}</button>
                <p class="lf-summary-security">🔒 Harga dihitung server-side dan dikunci saat pesanan dibuat.</p>
            </aside>
        </div>

        <section v-else class="lf-product-details">
            <article class="lf-product-description">
                <p class="lf-eyebrow">TENTANG PRODUK</p>
                <h2>{{product.name}}</h2>
                <p>{{product.description || 'Top up cepat dan aman melalui LFAMILIA STORE.'}}</p>
            </article>

            <section class="lf-product-review-section">
                <div class="lf-review-head">
                    <div><p class="lf-eyebrow">ULASAN PELANGGAN</p><h2>Apa kata pembeli?</h2></div>
                    <div v-if="reviewStats?.total" class="lf-review-score"><strong>{{reviewStats.average}}</strong><span>★★★★★</span><small>{{reviewStats.total}} ulasan terverifikasi</small></div>
                </div>
                <div v-if="reviews?.length" class="lf-review-grid">
                    <article v-for="review in reviews" :key="review.id">
                        <div><strong>{{review.display_name}}</strong><span>{{'★'.repeat(review.rating)}}{{'☆'.repeat(5-review.rating)}}</span></div>
                        <p>{{review.body}}</p>
                    </article>
                </div>
                <div v-else class="lf-empty">Belum ada ulasan untuk produk ini.</div>
            </section>

            <section v-if="faqs?.length" class="lf-product-faq">
                <p class="lf-eyebrow">PERTANYAAN UMUM</p><h2>Kamu punya pertanyaan?</h2>
                <details v-for="faq in faqs" :key="faq.id"><summary>{{faq.question}}<b>+</b></summary><p>{{faq.answer}}</p></details>
            </section>
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
                        <small>{{product.name}} · {{selectedPackage?.name || 'Pilih nominal'}}</small>
                    </span>
                    <strong class="lf-mobile-summary-price">{{formatIdr(displayTotal)}}</strong>
                    <span class="lf-mobile-summary-chevron">⌄</span>
                </button>
                <dl class="lf-mobile-summary-lines">
                    <div><dt>Harga</dt><dd>{{formatIdr(selectedPackage?.price_idr)}}</dd></div>
                    <div v-if="quote?.discount_idr"><dt>Diskon</dt><dd>-{{formatIdr(quote.discount_idr)}}</dd></div>
                    <div><dt>Biaya Pembayaran</dt><dd>{{formatIdr(quote?.fee_idr)}}</dd></div>
                    <div class="total"><dt>Total Pembayaran</dt><dd>{{formatIdr(displayTotal)}}</dd></div>
                </dl>
            </div>
            <button v-else type="button" class="lf-mobile-summary-toggle" aria-expanded="false" @click="summaryOpen=true">
                <span><strong>Ringkasan pesanan</strong><small>Ketuk untuk melihat rincian</small></span>
                <span><strong>{{formatIdr(displayTotal)}}</strong><b>⌃</b></span>
            </button>
            <button type="button" class="lf-mobile-order-button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="prepareOrder">
                {{busy==='order'?'Memproses...':'🔒 Pesan Sekarang'}}
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
        <div class="lf-voucher-modal">
            <header><div><p class="lf-eyebrow">VOUCHER TERSEDIA</p><h2>Pilih promo</h2></div><button @click="voucherOpen=false">×</button></header>
            <div v-if="voucherLoading" class="lf-empty">Memuat voucher...</div>
            <div v-else-if="voucherError" class="lf-warning-note">{{voucherError}}</div>
            <div v-else-if="vouchers.length" class="lf-voucher-list">
                <button v-for="voucher in vouchers" :key="voucher.code" @click="applyVoucher(voucher.code)">
                    <span><strong>{{voucher.code}}</strong><small>Minimum {{formatIdr(voucher.minimum_total_idr)}}</small></span>
                    <b>{{voucher.discount_type==='PERCENT'?voucher.discount_value+'%':formatIdr(voucher.discount_value)}}</b>
                </button>
            </div>
            <div v-else class="lf-empty">Belum ada voucher yang cocok untuk nominal ini.</div>
        </div>
    </div>

    <div v-if="confirmOpen" class="lf-modal-backdrop" @click.self="confirmOpen=false">
        <div class="lf-confirm-modal">
            <header><div><p class="lf-eyebrow">KONFIRMASI</p><h2>Periksa pesananmu</h2></div><button @click="confirmOpen=false">×</button></header>
            <dl>
                <div><dt>Produk</dt><dd>{{product.name}}</dd></div>
                <div><dt>Nominal</dt><dd>{{selectedPackage?.name}}</dd></div>
                <div v-if="nicknameResult?.nickname"><dt>Nickname</dt><dd>{{nicknameResult.nickname}}</dd></div>
                <div><dt>Pembayaran</dt><dd>{{paymentChannels.find(x=>x.code===paymentChannelCode)?.name}}</dd></div>
                <div v-if="quote?.discount_idr"><dt>Diskon</dt><dd>-{{formatIdr(quote.discount_idr)}}</dd></div>
                <div><dt>Biaya</dt><dd>{{formatIdr(quote?.fee_idr)}}</dd></div>
                <div class="total"><dt>Total</dt><dd>{{formatIdr(quote?.total_idr)}}</dd></div>
            </dl>
            <div class="lf-confirm-actions"><button class="lf-secondary" @click="confirmOpen=false">Kembali</button><button class="lf-primary" :disabled="busy==='order'" @click="createOrder">Buat Pesanan</button></div>
        </div>
    </div>
</main>
</CustomerShell>
</template>
