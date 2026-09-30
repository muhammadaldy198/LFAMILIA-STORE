<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';
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
const nominalSection = ref(null);
const paymentSection = ref(null);
const autoScrolledToNominal = ref(false);

const selectedPackage = computed(() => props.packages.find((item) => String(item.id) === String(selectedPackageId.value)));
const requiredFieldsComplete = computed(() => props.fields
    .filter((field) => field.is_required)
    .every((field) => String(customerInput[field.field_key] || '').trim() !== ''));

watch([selectedPackageId, voucherCode, guestEmail, guestPhone, paymentChannelCode], () => {
    quote.value = null;
    checkoutResult.value = null;
});

watch(
    () => props.fields.map((field) => String(customerInput[field.field_key] || '').trim()),
    async () => {
        if (!props.fields.length || !requiredFieldsComplete.value || autoScrolledToNominal.value) return;
        autoScrolledToNominal.value = true;
        await nextTick();
        nominalSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
);

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
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = data.message || 'Permintaan gagal.';
        const error = new Error(message);
        error.validation = data.errors || { checkout: [message] };
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

async function choosePackage(item) {
    if (!item.is_available) return;
    selectedPackageId.value = item.id;
    await nextTick();
    paymentSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function choosePayment(code) {
    paymentChannelCode.value = code;
    await nextTick();
    if (selectedPackage.value) await loadQuote();
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
    if (!selectedPackage.value || !paymentChannelCode.value) return;
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
        quote.value = { ...(quote.value || {}), total_idr: checkoutResult.value.total_idr };
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
        const redirect = paymentResult.value?.instructions?.redirect_url || paymentResult.value?.instructions?.payment_url;
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
                        <p>{{product.category_name}}</p>
                    </div>
                    <div class="lf-product-perks">
                        <div><span>⚡</span><strong>Proses Cepat</strong></div>
                        <div><span>◉</span><strong>Layanan Chat 24/7</strong></div>
                        <div><span>✓</span><strong>Pembayaran Aman!</strong></div>
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
                            <header><span>1</span><div><h2>Masukkan Data Akun</h2><p>Pastikan data tujuan sudah benar sebelum melanjutkan.</p></div></header>
                            <div class="lf-panel-body">
                                <div class="lf-account-fields">
                                    <label v-for="field in fields" :key="field.field_key">
                                        <span>{{field.label}}{{field.is_required?' *':''}}</span>
                                        <input v-model="customerInput[field.field_key]" :type="field.type==='email'?'email':field.type==='tel'?'tel':'text'" :required="field.is_required" maxlength="255" :placeholder="'Masukkan '+field.label">
                                        <small v-if="fieldError(field.field_key)" class="text-red-300">{{fieldError(field.field_key)}}</small>
                                    </label>
                                </div>
                                <button v-if="product.nickname_check_enabled" type="button" :disabled="busy==='nickname'" class="lf-secondary mt-3" @click="checkNickname">{{busy==='nickname'?'Memeriksa...':'Cek Nickname'}}</button>
                                <div v-if="nicknameResult?.verified" class="lf-success-note">Nickname: <strong>{{nicknameResult.nickname}}</strong><span v-if="nicknameResult.country"> · {{nicknameResult.country}}</span></div>
                                <div v-else-if="nicknameResult?.warning" class="lf-warning-note">{{nicknameResult.warning}}</div>
                            </div>
                        </section>

                        <section ref="nominalSection" class="lf-checkout-panel lf-scroll-anchor">
                            <header><span>2</span><div><h2>Pilih Nominal</h2><p>Pilih nominal yang ingin dibeli.</p></div></header>
                            <div class="lf-panel-body">
                                <div v-if="packages.length" class="lf-nominal-grid">
                                    <button v-for="item in packages" :key="item.id" type="button" :disabled="!item.is_available" :class="{selected:String(selectedPackageId)===String(item.id)}" @click="choosePackage(item)">
                                        <img v-if="item.image_url" :src="item.image_url" :alt="item.name">
                                        <span v-else class="lf-nominal-fallback">{{item.name.slice(0,1)}}</span>
                                        <span class="lf-nominal-copy"><strong>{{item.name}}</strong><small v-if="item.nominal_value">{{item.nominal_value}}</small></span>
                                        <b>{{item.is_available?formatIdr(item.price_idr):'Tidak tersedia'}}</b>
                                    </button>
                                </div>
                                <p v-else class="lf-empty">Belum ada nominal aktif.</p>
                            </div>
                        </section>

                        <section ref="paymentSection" class="lf-checkout-panel lf-scroll-anchor">
                            <header><span>3</span><div><h2>Pilih Pembayaran</h2><p>Pilih metode pembayaran yang tersedia untuk pesanan ini.</p></div></header>
                            <div class="lf-panel-body">
                                <div class="lf-payment-list">
                                    <button v-for="channel in paymentChannels" :key="channel.code" type="button" :class="{selected:paymentChannelCode===channel.code}" @click="choosePayment(channel.code)">
                                        <span class="lf-payment-icon">▣</span>
                                        <span><strong>{{channel.name}}</strong><small>Biaya dihitung otomatis oleh server</small></span>
                                        <b v-if="paymentChannelCode===channel.code">{{quote?formatIdr(quote.total_idr):'Dipilih'}}</b>
                                    </button>
                                </div>
                                <p v-if="!paymentChannels.length" class="lf-warning-note">Belum ada metode pembayaran aktif.</p>
                            </div>
                        </section>

                        <section class="lf-checkout-panel">
                            <header><span>4</span><div><h2>Detail Kontak</h2><p>Kontak digunakan jika ada kendala pada transaksi.</p></div></header>
                            <div class="lf-panel-body">
                                <div v-if="!customer" class="lf-account-fields">
                                    <label><span>Email</span><input v-model="guestEmail" type="email" maxlength="255" placeholder="example@gmail.com"></label>
                                    <label><span>No. WhatsApp</span><input v-model="guestPhone" type="tel" maxlength="32" placeholder="628XXXXXXXXXX"></label>
                                </div>
                                <div v-else class="lf-customer-checkout-note">Checkout sebagai <strong>{{customer.name}}</strong><br>{{customer.email}} · {{customer.phone||'Nomor HP belum lengkap'}}</div>
                                <p class="lf-panel-hint">Nomor ini akan dihubungi jika terjadi masalah pada pesanan.</p>
                            </div>
                        </section>

                        <section class="lf-checkout-panel">
                            <header><span>5</span><div><h2>Kode Promo</h2><p>Gunakan voucher jika tersedia dan memenuhi syarat.</p></div></header>
                            <div class="lf-panel-body">
                                <div class="lf-promo-input">
                                    <input v-model="voucherCode" maxlength="100" placeholder="Ketik Kode Promo Kamu">
                                    <button type="button" :disabled="busy==='quote'||!selectedPackage||!paymentChannelCode" @click="loadQuote">{{busy==='quote'?'Memeriksa...':'Gunakan'}}</button>
                                </div>
                                <Link href="/promo" class="lf-available-promo">Pakai Promo Yang Tersedia →</Link>
                            </div>
                        </section>

                        <section v-if="checkoutResult" class="lf-result-panel">
                            <h2>Pesanan berhasil dibuat</h2>
                            <p>Nomor pesanan: <strong>{{checkoutResult.order_number}}</strong></p>
                            <p v-if="checkoutResult.access_code">Kode akses guest: <strong class="break-all">{{checkoutResult.access_code}}</strong></p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button class="lf-primary" :disabled="busy==='payment'" @click="startPayment">{{busy==='payment'?'Menyiapkan...':'Bayar sekarang'}}</button>
                                <a :href="checkoutResult.status_url" class="lf-secondary">Lihat status</a>
                            </div>
                        </section>

                        <section v-if="paymentResult" class="lf-result-panel">
                            <h2>Pembayaran</h2>
                            <p>Status: <strong>{{paymentResult.status}}</strong></p>
                            <img v-if="paymentResult.instructions?.qr_url" :src="paymentResult.instructions.qr_url" alt="QR pembayaran" class="mt-3 max-h-64 rounded-lg bg-white p-2">
                            <p v-if="paymentResult.instructions?.va_number">Nomor VA: <strong>{{paymentResult.instructions.va_number}}</strong></p>
                            <a v-if="paymentResult.instructions?.payment_url" :href="paymentResult.instructions.payment_url" class="lf-primary mt-3">Buka pembayaran</a>
                        </section>
                    </div>

                    <aside class="lf-order-summary">
                        <h2>Ringkasan Pesanan</h2>
                        <div v-if="selectedPackage" class="lf-summary-product">
                            <img v-if="selectedPackage.image_url" :src="selectedPackage.image_url" alt="">
                            <div><strong>{{product.name}}</strong><small>{{selectedPackage.name}}</small></div>
                        </div>
                        <dl>
                            <div><dt>Harga</dt><dd>{{formatIdr(quote?.subtotal_idr??selectedPackage?.price_idr)}}</dd></div>
                            <div><dt>Diskon</dt><dd>- {{formatIdr(quote?.discount_idr||0)}}</dd></div>
                            <div><dt>Biaya pembayaran</dt><dd>{{formatIdr(quote?.fee_idr||0)}}</dd></div>
                            <div class="total"><dt>Total</dt><dd>{{formatIdr(quote?.total_idr??selectedPackage?.price_idr)}}</dd></div>
                        </dl>
                        <TurnstileWidget v-if="security.turnstile_required" ref="turnstile" class="mt-3" :site-key="security.turnstile_site_key" :action="security.turnstile_action" @token="turnstileToken=$event"/>
                        <button type="button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" class="lf-order-button" @click="createOrder">{{busy==='order'?'Membuat pesanan...':'Pesan Sekarang!'}}</button>
                        <p v-if="errors.checkout" class="mt-2 text-[10px] text-red-300">{{errors.checkout[0]}}</p>
                    </aside>
                </div>

                <section v-else class="lf-product-description">
                    <h2>Deskripsi {{product.name}}</h2>
                    <p>{{product.description||'Informasi produk akan ditampilkan di sini.'}}</p>
                </section>
            </div>

            <div v-if="activeTab==='transaction'" class="lf-mobile-checkout-bar">
                <div class="lf-mobile-selected">
                    <img v-if="selectedPackage?.image_url" :src="selectedPackage.image_url" alt="">
                    <span v-else class="lf-mobile-package-fallback">LF</span>
                    <div><strong>{{product.name}}</strong><small>{{selectedPackage?.name||'Pilih nominal terlebih dahulu'}}</small></div>
                </div>
                <div class="lf-mobile-total">
                    <span>{{formatIdr(quote?.total_idr??selectedPackage?.price_idr)}}</span>
                    <button type="button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="createOrder">{{busy==='order'?'Memproses...':'Pesan Sekarang!'}}</button>
                </div>
            </div>
        </main>
    </CustomerShell>
</template>
