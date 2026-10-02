<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
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
    initialPackageId: { type: String, default: '' },
});

const page = usePage();
const security = computed(() => page.props.security || {});

const selectedPackageId = ref(props.initialPackageId || '');
const savedAccountItems = ref([...(props.savedAccounts || [])]);
const customerInput = reactive(Object.fromEntries((props.fields || []).map((field) => [field.field_key, ''])));
const guestEmail = ref(props.customer?.email || '');
const guestPhone = ref(props.customer?.phone || '');
const voucherCode = ref('');
const paymentChannelCode = ref('');
const quote = ref(null);
const nicknameResult = ref(null);
const accountValidationSignature = ref('');
let accountValidationRequest = 0;
let quoteRequest = 0;
const checkoutResult = ref(null);
const paymentResult = ref(null);
const errors = ref({});
const busy = ref('');
const activeTab = ref('transaction');
const idempotencyKey = ref('');
const paymentIdempotencyKey = ref(newIdempotencyKey());
const voucherOpen = ref(false);
const vouchers = ref([]);
const voucherLoading = ref(false);
const voucherError = ref('');
const confirmOpen = ref(false);
const agreed = ref(false);
const confirmationSnapshot = ref(null);
const summaryOpen = ref(false);
const checkoutBarVisible = ref(true);
const noticeOpen = ref(false);
const noticeIndex = ref(0);
const hideNotice = ref(false);
const selectedSavedId = ref('');
const turnstile = ref(null);
const turnstileToken = ref('');
const reviewRating = ref(5);
const reviewTitle = ref('');
const reviewBody = ref('');
const reviewSaving = ref(false);
const reviewMessage = ref('');
const reviewError = ref('');

const selectedPackage = computed(() => (props.packages || []).find((item) =>
    item.is_available !== false && String(item.id) === String(selectedPackageId.value)
));
const requiredFieldsComplete = computed(() => (props.fields || []).filter((field) => field.is_required)
    .every((field) => String(customerInput[field.field_key] || '').trim() !== ''));
const guestContactComplete = computed(() => props.customer || (
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail.value.trim())
    && guestPhone.value.replace(/\D/g, '').length >= 8
));
const selectedPaymentChannel = computed(() => (props.paymentChannels || []).find((channel) =>
    channel.available !== false && channel.code === paymentChannelCode.value
));
const canQuote = computed(() => Boolean(selectedPackage.value && selectedPaymentChannel.value && guestContactComplete.value));
const summarySubtotal = computed(() => quote.value?.subtotal_idr ?? selectedPackage.value?.price_idr ?? 0);
const summaryFee = computed(() => quote.value ? Number(quote.value.fee_idr || 0) : null);
const displayTotal = computed(() => quote.value?.total_idr ?? summarySubtotal.value);
const isVoucherProduct = computed(() => String(props.product?.category_slug || '').toLowerCase() === 'voucher');
const hasAccountStep = computed(() => !isVoucherProduct.value && (props.fields || []).length > 0);
const nominalStep = computed(() => hasAccountStep.value ? 2 : 1);
const paymentStep = computed(() => nominalStep.value + 1);
const contactStep = computed(() => nominalStep.value + 2);
const promoStep = computed(() => nominalStep.value + 3);
const hasExternalPaymentOption = computed(() => paymentGroups.value.some((group) =>
    group.key !== 'wallet' && group.items.some((item) => item.available !== false)
));
const hasWalletPaymentOption = computed(() => paymentGroups.value.some((group) =>
    group.key === 'wallet'
));
const hasAvailableWalletPaymentOption = computed(() => paymentGroups.value.some((group) =>
    group.key === 'wallet' && group.items.some((item) => item.available !== false)
));
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
    const rows = [...groups.entries()].map(([name, items]) => ({ name, items }));
    const configured = props.product?.package_tabs_enabled ? (props.product?.package_tabs || []) : [];
    if (!configured.length) return rows;
    return [...rows].sort((left, right) => {
        const leftIndex = configured.indexOf(left.name);
        const rightIndex = configured.indexOf(right.name);
        return (leftIndex < 0 ? Number.MAX_SAFE_INTEGER : leftIndex)
            - (rightIndex < 0 ? Number.MAX_SAFE_INTEGER : rightIndex);
    });
});
const packageTabs = computed(() => props.product?.package_tabs_enabled
    ? (props.product?.package_tabs || []).filter((name) => packageGroups.value.some((group) => group.name === name))
    : []);
const activePackageTab = ref(packageTabs.value[0] || '');
watch(packageTabs, (tabs) => {
    if (!tabs.includes(activePackageTab.value)) activePackageTab.value = tabs[0] || '';
}, { immediate: true });
const visiblePackageGroups = computed(() => packageTabs.value.length
    ? packageGroups.value.filter((group) => group.name === activePackageTab.value)
    : packageGroups.value);

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

function formatNotice(value) {
    const zone = props.product?.manual_timezone === 'Asia/Makassar'
        ? 'WITA'
        : props.product?.manual_timezone === 'Asia/Jayapura'
            ? 'WIT'
            : 'WIB';

    return String(value || '')
        .replaceAll('{{jam_buka}}', props.product?.manual_open_time || '-')
        .replaceAll('{{jam_tutup}}', props.product?.manual_close_time || '-')
        .replaceAll('{{zona_waktu}}', zone);
}

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

function updateCheckoutBarVisibility() {
    if (window.innerWidth >= 640) {
        checkoutBarVisible.value = true;
        return;
    }

    const main = document.querySelector('.lf-checkout-page');
    const accountPanel = document.querySelector('.lf-checkout-account-panel');
    if (!main) return;

    const mainStillActive = main.getBoundingClientRect().bottom > window.innerHeight + 8;
    const accountCleared = !accountPanel
        || accountPanel.getBoundingClientRect().bottom <= window.innerHeight - 128;

    checkoutBarVisible.value = mainStillActive && accountCleared;
}

function newIdempotencyKey() {
    return globalThis.crypto?.randomUUID?.() || ('checkout-' + Date.now() + '-' + Math.random().toString(36).slice(2));
}

function checkoutAttemptStorageKey() {
    return 'lfamilia:checkout-attempt:' + props.product.id;
}

function ensureCheckoutAttemptKey(signature) {
    try {
        const stored = JSON.parse(window.sessionStorage.getItem(checkoutAttemptStorageKey()) || 'null');
        if (
            stored?.signature === signature
            && typeof stored?.key === 'string'
            && /^[A-Za-z0-9:_-]{16,120}$/.test(stored.key)
        ) {
            idempotencyKey.value = stored.key;
            return stored.key;
        }

        const key = newIdempotencyKey();
        window.sessionStorage.setItem(checkoutAttemptStorageKey(), JSON.stringify({ signature, key }));
        idempotencyKey.value = key;
        return key;
    } catch {
        const key = newIdempotencyKey();
        idempotencyKey.value = key;
        return key;
    }
}

function clearCheckoutAttempt() {
    try {
        window.sessionStorage.removeItem(checkoutAttemptStorageKey());
    } catch {
        // Storage can be unavailable in hardened/private browsers.
    }
    idempotencyKey.value = '';
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

function currentQuoteSignature() {
    return JSON.stringify([
        selectedPackage.value?.id ?? null,
        selectedPaymentChannel.value?.code ?? null,
        normalizePromo(voucherCode.value),
        props.customer?.id ?? null,
        props.customer ? null : guestEmail.value.trim().toLowerCase(),
        props.customer ? null : normalizeWhatsapp(guestPhone.value),
    ]);
}

function financialSummarySignature(source = quote.value) {
    if (!source) return '';

    return JSON.stringify([
        Number(source.subtotal_idr || 0),
        Number(source.member_discount_idr || 0),
        Number(source.voucher_discount_idr || 0),
        Number(source.discount_idr || 0),
        Number(source.fee_idr || 0),
        Number(source.total_idr || 0),
        source.member_tier_code || null,
        source.voucher_code || null,
        source.payment_channel_code || null,
    ]);
}

function confirmationStateSignature() {
    return JSON.stringify([
        currentQuoteSignature(),
        accountInputSignature(),
    ]);
}

function buildConfirmationSnapshot(currentQuote) {
    const stateSignature = confirmationStateSignature();
    const attemptKey = ensureCheckoutAttemptKey(stateSignature);

    return {
        state_signature: stateSignature,
        quote_signature: financialSummarySignature(currentQuote),
        idempotency_key: attemptKey,
        quote: { ...currentQuote },
        customer_input: { ...customerInput },
        nickname: nicknameResult.value?.nickname || null,
        product_name: props.product.name,
        item_name: selectedPackage.value ? nominalLabel(selectedPackage.value.name, props.product.name) : '-',
        payment_code: selectedPaymentChannel.value?.code || null,
        payment_name: selectedPaymentChannel.value?.name
            || selectedPaymentChannel.value?.label
            || selectedPaymentChannel.value?.code
            || '-',
        email: guestEmail.value.trim(),
        phone: normalizeWhatsapp(guestPhone.value),
        voucher_code: currentQuote?.voucher_code || null,
    };
}

function closeConfirmation() {
    confirmOpen.value = false;
    agreed.value = false;
    confirmationSnapshot.value = null;
}

function invalidateConfirmation() {
    if (!confirmOpen.value && !confirmationSnapshot.value) return;
    closeConfirmation();
}

function invalidateQuote() {
    quoteRequest += 1;
    quote.value = null;
    invalidateConfirmation();
    if (busy.value === 'quote') busy.value = '';
}

function updateVoucherInput(value) {
    const normalized = normalizePromo(value);
    if (voucherCode.value === normalized) return;

    voucherCode.value = normalized;
    invalidateQuote();
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

function accountInputSignature() {
    return JSON.stringify((props.fields || []).map((field) => [
        field.field_key,
        String(customerInput[field.field_key] || '').trim(),
    ]));
}

function localAccountErrors() {
    const validation = {};

    for (const field of props.fields || []) {
        const value = String(customerInput[field.field_key] || '').trim();
        const key = 'customer_input.' + field.field_key;

        if (field.is_required && value === '') {
            validation[key] = [field.label + ' wajib diisi.'];
            continue;
        }
        if (value === '') continue;
        if (value.length > 255) {
            validation[key] = [field.label + ' terlalu panjang.'];
            continue;
        }
        if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            validation[key] = [field.label + ' harus berupa email valid.'];
            continue;
        }
        if (field.type === 'tel' && !/^[0-9+().\-\s]{6,32}$/.test(value)) {
            validation[key] = [field.label + ' tidak valid.'];
        }
    }

    return validation;
}

function withoutAccountErrors(source = errors.value) {
    return Object.fromEntries(Object.entries(source || {}).filter(([key]) => !key.startsWith('customer_input.')));
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

            }),
        });
        reviewTitle.value = '';
        reviewBody.value = '';
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
        package_id: selectedPackage.value ? Number(selectedPackage.value.id) : null,
        payment_channel_code: selectedPaymentChannel.value?.code || null,
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
    if (isVoucherProduct.value || !hasAccountStep.value) return true;

    const validation = localAccountErrors();
    if (Object.keys(validation).length) {
        accountValidationSignature.value = '';
        nicknameResult.value = null;
        errors.value = { ...withoutAccountErrors(), ...validation };
        return false;
    }

    const signature = accountInputSignature();
    const requestId = ++accountValidationRequest;
    errors.value = withoutAccountErrors();
    nicknameResult.value = null;
    busy.value = 'nickname';

    try {
        const result = await requestJson('/checkout/nickname', {
            method: 'POST',
            body: JSON.stringify({ product_id: props.product.id, customer_input: { ...customerInput } }),
        });

        if (requestId !== accountValidationRequest || signature !== accountInputSignature()) return false;

        nicknameResult.value = result;
        accountValidationSignature.value = signature;
        return true;
    } catch (error) {
        if (requestId !== accountValidationRequest || signature !== accountInputSignature()) return false;

        accountValidationSignature.value = '';
        errors.value = { ...withoutAccountErrors(), ...(error.validation || {}) };
        return false;
    } finally {
        if (requestId === accountValidationRequest && busy.value === 'nickname') busy.value = '';
    }
}

function choosePackage(item) {
    if (!item.is_available) return;
    selectedPackageId.value = String(item.id);
    invalidateQuote();
}

async function choosePayment(code) {
    const channel = (props.paymentChannels || []).find((item) => item.code === code && item.available !== false);
    if (!channel) return;

    paymentChannelCode.value = channel.code;
    invalidateQuote();
    if (canQuote.value) await loadQuote();
}

async function loadQuote() {
    if (!canQuote.value) {
        invalidateQuote();
        return null;
    }

    const signature = currentQuoteSignature();
    const requestId = ++quoteRequest;
    quote.value = null;
    errors.value = {};
    busy.value = 'quote';

    try {
        const result = await requestJson('/checkout/quote', {
            method: 'POST',
            body: JSON.stringify(basePayload()),
        });

        if (requestId !== quoteRequest || signature !== currentQuoteSignature()) return null;

        quote.value = result;
        return result;
    } catch (error) {
        if (requestId !== quoteRequest || signature !== currentQuoteSignature()) return null;

        errors.value = error.validation || {};
        return null;
    } finally {
        if (requestId === quoteRequest && busy.value === 'quote') busy.value = '';
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
        const data = await requestJson('/checkout/vouchers', {
            method: 'POST',
            body: JSON.stringify({
                package_id: selectedPackage.value.id,
                ...(props.customer ? {} : {
                    guest_email: guestEmail.value.trim() || null,
                    guest_phone: guestPhone.value.trim() || null,
                }),
            }),
        });
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
    invalidateQuote();
    if (canQuote.value) await loadQuote();
}

async function prepareOrder() {
    errors.value = {};

    if (hasAccountStep.value) {
        const validation = localAccountErrors();
        if (Object.keys(validation).length) {
            errors.value = validation;
            return;
        }

        if (accountValidationSignature.value !== accountInputSignature()) {
            const valid = await checkNickname();
            if (!valid) return;
        }
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
    const currentQuote = await loadQuote();
    if (!currentQuote) return;

    confirmationSnapshot.value = buildConfirmationSnapshot(currentQuote);
    agreed.value = false;
    confirmOpen.value = true;
}

async function createOrder() {
    if (!agreed.value || !confirmationSnapshot.value || busy.value) return;

    errors.value = {};

    if (confirmationSnapshot.value.state_signature !== confirmationStateSignature()) {
        closeConfirmation();
        errors.value = { checkout: ['Data checkout berubah. Periksa kembali ringkasan sebelum membuat pesanan.'] };
        return;
    }

    const freshQuote = await loadQuote();
    if (!freshQuote) {
        agreed.value = false;
        return;
    }

    if (confirmationSnapshot.value.quote_signature !== financialSummarySignature(freshQuote)) {
        confirmationSnapshot.value = buildConfirmationSnapshot(freshQuote);
        agreed.value = false;
        confirmOpen.value = true;
        errors.value = {
            checkout: ['Harga, promo, atau biaya pembayaran berubah. Periksa ringkasan terbaru lalu setujui kembali.'],
        };
        return;
    }

    checkoutResult.value = null;
    paymentResult.value = null;
    busy.value = 'order';

    try {
        checkoutResult.value = await requestJson('/checkout/orders', {
            method: 'POST',
            body: JSON.stringify({
                ...basePayload(),
                customer_input: { ...customerInput },
                idempotency_key: confirmationSnapshot.value.idempotency_key,
                turnstile_token: turnstileToken.value || null,
            }),
        });
    } catch (error) {
        errors.value = error.validation || { checkout: [error.message || 'Pesanan gagal dibuat.'] };
        turnstile.value?.reset();
        busy.value = '';
        return;
    }

    quote.value = {
        subtotal_idr: checkoutResult.value.subtotal_idr,
        member_discount_idr: checkoutResult.value.member_discount_idr,
        voucher_discount_idr: checkoutResult.value.voucher_discount_idr,
        discount_idr: checkoutResult.value.discount_idr,
        member_tier_code: checkoutResult.value.member_tier_code,
        member_discount_bps: checkoutResult.value.member_discount_bps,
        fee_idr: checkoutResult.value.fee_idr,
        total_idr: checkoutResult.value.total_idr,
        voucher_code: checkoutResult.value.voucher_code,
        payment_channel_code: checkoutResult.value.payment_channel_code,
    };
    clearCheckoutAttempt();
    closeConfirmation();
    turnstile.value?.reset();

    busy.value = 'payment';
    try {
        paymentResult.value = await requestJson('/payments/orders/' + encodeURIComponent(checkoutResult.value.order_number), {
            method: 'POST',
            body: JSON.stringify({
                idempotency_key: paymentIdempotencyKey.value,
                access_code: checkoutResult.value.access_code || null,
            }),
        });

        window.location.assign('/payment?invoice=' + encodeURIComponent(checkoutResult.value.order_number));
    } catch {
        window.location.assign('/payment?invoice=' + encodeURIComponent(checkoutResult.value.order_number) + '&resume=1');
    } finally {
        busy.value = '';
    }
}

onMounted(() => {
    updateCheckoutBarVisibility();
    window.addEventListener('scroll', updateCheckoutBarVisibility, { passive: true });
    window.addEventListener('resize', updateCheckoutBarVisibility);

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

onBeforeUnmount(() => {
    window.removeEventListener('scroll', updateCheckoutBarVisibility);
    window.removeEventListener('resize', updateCheckoutBarVisibility);
});

watch([guestEmail, guestPhone], () => {
    invalidateQuote();
});

let nicknameAutoTimer;
watch(() => props.fields.map((field) => String(customerInput[field.field_key] || '')), () => {
    accountValidationRequest += 1;
    accountValidationSignature.value = '';
    nicknameResult.value = null;
    selectedSavedId.value = '';
    invalidateConfirmation();
    errors.value = withoutAccountErrors();
    if (busy.value === 'nickname') busy.value = '';
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
                    <strong>{{ customerText("pages.catalog.show.ec3aa9dc", "Proses cepat") }}</strong>
                </div>
                <div>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3z"/><path d="m9 12 2 2 4-4"/></svg></span>
                    <strong>{{ customerText("pages.catalog.show.365fc5ed", "Chat 24/7") }}</strong>
                </div>
                <div>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></span>
                    <strong>{{ customerText("pages.catalog.show.1b6840bc", "Pembayaran aman") }}</strong>
                </div>
            </div>
        </div>
    </section>

    <div class="lf-container">
        <div class="lf-checkout-tabs">
            <button type="button" :class="{active:activeTab==='transaction'}" @click="activeTab='transaction'">{{ customerText("pages.catalog.show.2b1e660d", "Transaksi") }}</button>
            <button type="button" :class="{active:activeTab==='details'}" @click="activeTab='details'">{{ customerText("pages.catalog.show.f107e79f", "Keterangan") }}</button>
        </div>

        <div v-if="activeTab==='transaction'" class="lf-checkout-grid">
            <div class="lf-checkout-panels">
                <section v-if="hasAccountStep" class="lf-checkout-panel lf-checkout-account-panel">
                    <header><div><h2>{{ customerText("pages.catalog.show.ec1c9711", "Masukkan Data Akun") }}</h2><p>{{ customerText("pages.catalog.show.b691e78b", "Isi ID tujuan dengan benar. Nickname diperiksa otomatis jika didukung.") }}</p></div></header>
                    <div class="lf-panel-body">
                        <div class="lf-account-product-mobile">
                            <span class="lf-account-product-art">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name">
                                <span v-else>{{product.name.slice(0,1)}}</span>
                            </span>
                            <div class="lf-account-product-copy">
                                <strong>{{product.name}}</strong>
                                <Link href="/#produk">{{ customerText("pages.catalog.show.f100ee83", "Ganti produk") }}</Link>
                            </div>
                        </div>
                        <label v-if="savedAccountItems.length" class="lf-saved-account-select">
                            <span>{{ customerText("pages.catalog.show.85989a9d", "Akun game tersimpan") }}</span>
                            <select v-model="selectedSavedId" @change="chooseSavedAccount(selectedSavedId)">
                                <option value="">{{ customerText("pages.catalog.show.e9a9962a", "Isi manual") }}</option>
                                <option v-for="saved in savedAccountItems" :key="saved.id" :value="String(saved.id)">{{saved.label}}{{saved.nickname?' · '+saved.nickname:''}}</option>
                            </select>
                        </label>

                        <div class="lf-account-fields">
                            <label v-for="field in fields" :key="field.field_key">
                                <span>{{field.label}}{{field.is_required ? '' : ' (opsional)'}}</span>
                                <input v-model="customerInput[field.field_key]" :type="field.type==='email'?'email':field.type==='tel'?'tel':'text'" :required="field.is_required" maxlength="255" :placeholder="field.placeholder || ('Masukkan '+field.label)">
                                <small v-if="fieldError(field.field_key)" class="lf-field-error">{{fieldError(field.field_key)}}</small>
                            </label>
                        </div>

                        <div v-if="product.nickname_check_enabled && busy==='nickname'" class="lf-nickname-state lf-nickname-loading">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/></svg>
                            <span>{{ customerText("pages.catalog.show.923fbe22", "Memeriksa ID dan Server…") }}</span>
                        </div>
                        <div v-else-if="nicknameResult?.verified" class="lf-nickname-state lf-nickname-success">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            <div><small>{{ customerText("pages.catalog.show.86a69022", "Akun ditemukan") }}</small><strong>{{nicknameResult.nickname}}</strong><span v-if="nicknameResult.country">dari {{nicknameResult.country}}</span></div>
                        </div>
                        <div v-else-if="nicknameResult?.warning" class="lf-nickname-state lf-nickname-error">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                            <div><strong>{{ customerText("pages.catalog.show.18d22dbd", "Akun belum terverifikasi") }}</strong><span>{{nicknameResult.warning}}</span></div>
                        </div>
                        <div v-else-if="product.nickname_check_enabled" class="lf-checkout-info-note">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                            <span>{{ customerText("pages.catalog.show.5b999fc2", "Nickname akan tampil otomatis setelah data akun yang diperlukan terisi.") }}</span>
                        </div>
                        <div v-else class="lf-checkout-info-note">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                            <span>{{ customerText("pages.catalog.show.a38dd539", "Verifikasi nickname otomatis belum tersedia. Periksa kembali data sebelum membayar.") }}</span>
                        </div>

                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-nominal-panel">
                    <header><div><h2>{{ customerText("pages.catalog.show.75d5eb47", "Pilih Nominal") }}</h2><p>{{product.checkout_nominal_description || 'Pesanan diproses otomatis setelah pembayaran.'}}</p></div></header>
                    <div class="lf-panel-body lf-package-sections">
                        <nav v-if="packageTabs.length" class="lf-filters mb-3">
                            <button v-for="tabName in packageTabs" :key="tabName" type="button" class="lf-chip" :class="{active:activePackageTab===tabName}" @click="activePackageTab=tabName">{{tabName}}</button>
                        </nav>
                        <section v-for="group in visiblePackageGroups" :key="group.name || 'all'">
                            <div v-if="group.name" class="lf-package-group-head"><h3>{{group.name}}</h3><span></span></div>
                            <div class="lf-nominal-grid">
                                <button v-for="item in group.items" :key="item.id" type="button" :disabled="!item.is_available" :class="{selected:selectedPackage && String(selectedPackage.id)===String(item.id)}" @click="choosePackage(item)">
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
                    <header><div><h2>{{ customerText("pages.catalog.show.c08763ef", "Pilih Pembayaran") }}</h2><p>{{ customerText("pages.catalog.show.e7920111", "Pilih metode pembayaran yang ingin digunakan.") }}</p></div></header>
                    <div class="lf-panel-body lf-payment-groups">
                        <div v-if="!paymentChannels?.length" class="lf-payment-unavailable">
                            {{ customerText("pages.catalog.show.51de2747", "Belum ada metode pembayaran aktif.") }}
                        </div>
                        <div v-else-if="!hasExternalPaymentOption" class="lf-payment-unavailable">
                            <template v-if="customer && hasAvailableWalletPaymentOption">{{ customerText("pages.catalog.show.4bfa0eb", "Pembayaran otomatis belum tersedia. Kamu masih bisa memakai LFAMILIA Cash bila saldo mencukupi.") }}</template>
                            <template v-else>{{ customerText("pages.catalog.show.f4a728b6", "Belum ada metode pembayaran yang dapat digunakan saat ini.") }}</template>
                        </div>
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
                                    <img :src="'/payment/lfamilia-cash.webp'" :alt="customerText(&quot;pages.catalog.show.attribute.alt.d8a0146d&quot;, &quot;LFAMILIA Cash&quot;)">
                                </span>
                                <span class="lf-payment-group-copy">
                                    <strong>{{group.title}}</strong>
                                    <small v-if="group.key==='wallet' && customer">Saldo {{formatIdr(customer.balance_idr)}}</small>
                                    <small v-else-if="group.key==='wallet'">{{ customerText("pages.catalog.show.994e335c", "Masuk akun untuk memakai saldo") }}</small>
                                    <small v-else>{{group.description}}</small>
                                </span>
                                <span v-if="group.items.some(x=>x.code===paymentChannelCode)" class="lf-payment-check"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></span>
                            </button>

                            <div v-if="group.key!=='wallet'" class="lf-payment-brand-strip">
                                <span v-if="group.key==='qris'">{{ customerText("pages.catalog.show.c64a26ec", "QRIS • DANA • ShopeePay") }}</span>
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
                                    <span class="lf-payment-channel-copy">
                                        <strong>{{channel.name}}</strong>
                                        <small v-if="Number(channel.fee_percent_bps || 0) > 0 || Number(channel.fee_flat_idr || 0) > 0">
                                            {{ customerText("pages.catalog.show.228d43a3", "Fee") }} <template v-if="Number(channel.fee_percent_bps || 0) > 0">{{(Number(channel.fee_percent_bps || 0) / 100).toLocaleString('id-ID', {maximumFractionDigits:2})}}%</template><template v-if="Number(channel.fee_percent_bps || 0) > 0 && Number(channel.fee_flat_idr || 0) > 0"> + </template><template v-if="Number(channel.fee_flat_idr || 0) > 0">{{formatIdr(channel.fee_flat_idr)}}</template>
                                        </small>
                                    </span>
                                </button>
                            </div>
                        </div>
                        <p v-if="!customer && hasWalletPaymentOption" class="lf-payment-login-hint">{{ customerText("pages.catalog.show.f76c80cf", "Ingin membayar memakai saldo?") }} <Link href="/login">{{ customerText("pages.catalog.show.2f2a90b2", "Masuk atau daftar akun") }}</Link>.</p>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-contact-panel">
                    <header><div><h2>{{ customerText("pages.catalog.show.19af3a35", "Data Pembeli") }}</h2><p>{{ customerText("pages.catalog.show.1e6ddd4f", "Email dan WhatsApp digunakan untuk invoice serta status transaksi.") }}</p></div></header>
                    <div class="lf-panel-body">
                        <div v-if="!customer" class="lf-account-fields">
                            <label><span>{{ customerText("pages.catalog.show.43352167", "Email") }}</span><input v-model="guestEmail" type="email" maxlength="255" :placeholder="customerText(&quot;pages.catalog.show.attribute.placeholder.f333f780&quot;, &quot;Contoh: nama@email.com&quot;)"></label>
                            <label><span>{{ customerText("pages.catalog.show.8aa48a22", "Nomor WhatsApp") }}</span><input :value="guestPhone" type="tel" inputmode="tel" autocomplete="tel" maxlength="17" pattern="\\+?[0-9]{8,16}" :placeholder="customerText(&quot;pages.catalog.show.attribute.placeholder.a02514d1&quot;, &quot;Contoh: 081234567890&quot;)" @input="guestPhone=normalizeWhatsapp($event.target.value)"></label>
                        </div>
                        <div v-else class="lf-customer-checkout-note">
                            <span class="lf-account-avatar">{{customer.name?.slice(0,1)?.toUpperCase()}}</span>
                            <div><strong>{{customer.name}}</strong><small>{{customer.email}} · {{customer.phone || 'Nomor HP belum lengkap'}}</small></div>
                        </div>
                        <p class="lf-contact-privacy"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3z"/><path d="m9 12 2 2 4-4"/></svg><span>{{ customerText("pages.catalog.show.efbf2ce0", "Kami hanya memakai kontak untuk invoice dan status transaksi.") }}</span></p>
                    </div>
                </section>

                <section class="lf-checkout-panel lf-checkout-compact-panel lf-checkout-promo-panel">
                    <header><div><h2>{{ customerText("pages.catalog.show.a2cdbf5b", "Kode Promo") }}</h2><p>{{ customerText("pages.catalog.show.98e0f6bb", "Masukkan kode promo atau voucher diskon yang tersedia.") }}</p></div></header>
                    <div class="lf-panel-body">
                        <div class="lf-promo-input">
                            <input :value="voucherCode" maxlength="100" :placeholder="customerText(&quot;pages.catalog.show.attribute.placeholder.1d49aca8&quot;, &quot;Masukkan kode promo&quot;)" @input="updateVoucherInput($event.target.value)">
                            <button type="button" :disabled="busy==='quote'||!canQuote" @click="loadQuote">{{busy==='quote'?'Memeriksa...':'Gunakan'}}</button>
                        </div>
                        <button type="button" class="lf-available-promo" @click="openVoucherPicker"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 9a3 3 0 0 0 0 6v4a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-4a3 3 0 0 0 0-6V5a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2M13 17v2M13 11v2"/></svg><span>{{ customerText("pages.catalog.show.d64c85af", "Pakai Voucher Yang Tersedia") }}</span></button>
                        <div v-if="quote?.voucher_code" class="lf-success-note">{{ customerText("pages.catalog.show.3fb6f639", "Voucher") }} <strong>{{quote.voucher_code}}</strong> aktif · Hemat {{formatIdr(quote.voucher_discount_idr)}}</div>
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
                    <span><strong>{{ customerText("pages.catalog.show.e37b9457", "Periksa kembali checkout") }}</strong><small>{{firstCheckoutError}}</small></span>
                </div>

            </div>

            <aside class="lf-order-summary">
                <h2>{{ customerText("pages.catalog.show.e728eee3", "Ringkasan Pesanan") }}</h2>
                <div class="lf-summary-product">
                    <img v-if="selectedPackage?.image_url || product.image_url" :src="selectedPackage?.image_url || product.image_url" :alt="product.name">
                    <span v-else class="lf-mobile-package-fallback">{{ customerText("pages.catalog.show.6ae24f17", "LF") }}</span>
                    <div><strong>{{product.name}}</strong><small>{{selectedPackage ? nominalLabel(selectedPackage.name, product.name) : 'Pilih nominal'}}</small></div>
                </div>
                <dl>
                    <div><dt>{{ customerText("pages.catalog.show.724d11bc", "Harga") }}</dt><dd>{{formatIdr(summarySubtotal)}}</dd></div>
                    <div v-if="quote?.member_discount_idr"><dt>Diskon {{quote.member_tier_code || 'Member'}}</dt><dd class="lf-discount">-{{formatIdr(quote.member_discount_idr)}}</dd></div>
                    <div v-if="quote?.voucher_discount_idr"><dt>{{ customerText("pages.catalog.show.6af5335f", "Diskon Voucher") }}</dt><dd class="lf-discount">-{{formatIdr(quote.voucher_discount_idr)}}</dd></div>
                    <div><dt>{{ customerText("pages.catalog.show.c217fcd5", "Biaya pembayaran") }}</dt><dd>{{summaryFee===null ? '—' : formatIdr(summaryFee)}}</dd></div>
                    <div class="total"><dt>{{ customerText("pages.catalog.show.ad066d9d", "Total") }}</dt><dd>{{formatIdr(displayTotal)}}</dd></div>
                </dl>
                <button type="button" class="lf-order-button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="prepareOrder">{{busy==='order'?'Memproses...':'Pesan Sekarang'}}</button>
                <p class="lf-summary-security">{{ customerText("pages.catalog.show.22bd992a", "🔒 Harga dihitung server-side dan dikunci saat pesanan dibuat.") }}</p>
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
                        <p class="lf-eyebrow">{{ customerText("pages.catalog.show.cd75c0db", "PENILAIAN PELANGGAN") }}</p>
                        <h2>{{ customerText("pages.catalog.show.d4d282c6", "Ulasan & rating") }}</h2>
                        <p>{{ customerText("pages.catalog.show.c47f9331", "Ulasan hanya dapat dibuat setelah pembelian berhasil. Akun dan pembeli guest sama-sama bisa memberi ulasan terverifikasi.") }}</p>
                    </div>
                    <div class="lf-review-average">
                        <span>★</span>
                        <strong>{{reviewStats?.total ? Number(reviewStats.average || 0).toFixed(1) : '–'}}</strong>
                        <small>{{reviewStats?.total || 0}} ulasan</small>
                    </div>
                </div>

                <div class="lf-review-legacy-grid">
                    <form v-if="customer" class="lf-review-form" @submit.prevent="submitReview">
                        <div class="lf-review-form-intro">
                            <span class="lf-review-message-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
                            </span>
                            <div>
                                <h3>{{ customerText("pages.catalog.show.c8372e3c", "Bagikan pengalamanmu") }}</h3>
                                <p>{{ customerText("pages.catalog.show.a0486d80", "Pembelian dari akunmu akan diverifikasi otomatis.") }}</p>
                            </div>
                        </div>

                        <div class="lf-review-stars-input">
                            <button v-for="value in [1,2,3,4,5]" :key="value" type="button" :aria-label="value + ' bintang'" :class="{active:value<=reviewRating}" @click="reviewRating=value">★</button>
                        </div>

                        <input v-model="reviewTitle" maxlength="100" :placeholder="customerText(&quot;pages.catalog.show.attribute.placeholder.5f3aed05&quot;, &quot;Masukkan judul singkat (opsional)&quot;)">
                        <textarea v-model="reviewBody" required minlength="5" maxlength="1200" :placeholder="customerText(&quot;pages.catalog.show.attribute.placeholder.98e9ee9&quot;, &quot;Ceritakan pengalaman transaksimu&quot;)"></textarea>
                        <p v-if="reviewMessage" class="lf-review-success">{{reviewMessage}}</p>
                        <p v-if="reviewError" class="lf-review-error">{{reviewError}}</p>
                        <button class="lf-review-submit" :disabled="reviewSaving">{{reviewSaving ? 'Menyimpan...' : 'Simpan ulasan'}}</button>
                    </form>
                    <div v-else class="lf-review-form lf-review-guest-secure">
                        <div class="lf-review-form-intro">
                            <span class="lf-review-message-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
                            </span>
                            <div>
                                <h3>{{ customerText("pages.catalog.show.2f7670c2", "Ulasan guest tetap tersedia") }}</h3>
                                <p>{{ customerText("pages.catalog.show.958a1e28", "Buka status pesanan memakai invoice + kode akses aman. Setelah pesanan Berhasil, formulir ulasan tersedia di halaman pesanan itu.") }}</p>
                            </div>
                        </div>
                        <Link href="/orders/check" class="lf-review-submit lf-review-secure-link">{{ customerText("pages.catalog.show.19b24f16", "Buka status pesanan") }}</Link>
                    </div>

                    <div class="lf-review-list">
                        <article v-for="review in reviews" :key="review.id">
                            <div class="lf-review-card-head">
                                <div>
                                    <strong>{{review.display_name}}</strong>
                                    <span v-if="review.verified_purchase" class="lf-review-verified">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 12 2 2 4-4"/><path d="M12 2l2.1 2.1 3-.1.9 2.8 2.5 1.7-1 2.8 1 2.8-2.5 1.7-.9 2.8-3-.1L12 22l-2.1-2.1-3 .1-.9-2.8-2.5-1.7 1-2.8-1-2.8L6 7.2 6.9 4l3 .1z"/></svg>
                                        {{ customerText("pages.catalog.show.5c206dfe", "Pembelian terverifikasi") }}
                                    </span>
                                </div>
                                <span class="lf-review-rating">★ {{review.rating}}</span>
                            </div>
                            <h3 v-if="review.title">{{review.title}}</h3>
                            <p>{{review.body}}</p>
                            <time v-if="review.published_at || review.created_at">{{formatDateId(review.published_at || review.created_at)}}</time>
                        </article>
                        <div v-if="!reviews?.length" class="lf-review-empty">{{ customerText("pages.catalog.show.97359d9e", "Belum ada ulasan untuk produk ini.") }}</div>
                    </div>
                </div>
            </section>

            <article v-if="faqs?.length" class="lf-product-faq lf-product-faq-legacy">
                <h2>{{ customerText("pages.catalog.show.cc3c98ca", "Pertanyaan umum") }}</h2>
                <div>
                    <details v-for="faq in faqs.slice(0,4)" :key="faq.id">
                        <summary>{{faq.question}}</summary>
                        <p>{{faq.answer}}</p>
                    </details>
                </div>
            </article>
        </section>
    </div>

    <div v-if="activeTab==='transaction'" class="lf-mobile-checkout-bar" :class="{'is-hidden': !checkoutBarVisible}">
        <div class="lf-mobile-checkout-inner">
            <div v-if="summaryOpen" class="lf-mobile-summary-card">
                <button type="button" class="lf-mobile-summary-head" aria-expanded="true" @click="summaryOpen=false">
                    <span class="lf-mobile-summary-art">
                        <img v-if="selectedPackage?.image_url || product.image_url" :src="selectedPackage?.image_url || product.image_url" alt="">
                        <span v-else class="lf-mobile-package-fallback">{{ customerText("pages.catalog.show.6ae24f17", "LF") }}</span>
                    </span>
                    <span class="lf-mobile-summary-copy">
                        <strong>{{ customerText("pages.catalog.show.eb589e83", "Ringkasan pesanan") }}</strong>
                        <small>{{product.name}} · {{selectedPackage ? nominalLabel(selectedPackage.name, product.name) : 'Pilih nominal'}}</small>
                    </span>
                    <strong class="lf-mobile-summary-price">{{formatIdr(displayTotal)}}</strong>
                    <span class="lf-mobile-summary-chevron">⌄</span>
                </button>
                <dl class="lf-mobile-summary-lines">
                    <div><dt>{{ customerText("pages.catalog.show.f95874", "Harga Satuan") }}</dt><dd>{{formatIdr(summarySubtotal)}}</dd></div>
                    <div><dt>{{ customerText("pages.catalog.show.d361488d", "Subtotal") }}</dt><dd>{{formatIdr(summarySubtotal)}}</dd></div>
                    <div v-if="quote?.member_discount_idr"><dt>Diskon {{quote.member_tier_code || 'Member'}}</dt><dd>-{{formatIdr(quote.member_discount_idr)}}</dd></div>
                    <div v-if="quote?.voucher_discount_idr"><dt>{{ customerText("pages.catalog.show.6af5335f", "Diskon Voucher") }}</dt><dd>-{{formatIdr(quote.voucher_discount_idr)}}</dd></div>
                    <div><dt>{{ customerText("pages.catalog.show.2688b9b5", "Biaya Pembayaran") }}</dt><dd>{{summaryFee===null ? '—' : formatIdr(summaryFee)}}</dd></div>
                    <div class="total"><dt>{{ customerText("pages.catalog.show.18fcae49", "Total Pembayaran") }}</dt><dd>{{formatIdr(displayTotal)}}</dd></div>
                </dl>
            </div>
            <button v-else type="button" class="lf-mobile-summary-toggle" aria-expanded="false" @click="summaryOpen=true">
                <span><strong>{{ customerText("pages.catalog.show.eb589e83", "Ringkasan pesanan") }}</strong><small>{{ customerText("pages.catalog.show.50fa1f6", "Ketuk untuk melihat rincian") }}</small></span>
                <span><strong>{{formatIdr(displayTotal)}}</strong><b>⌃</b></span>
            </button>
            <button type="button" class="lf-mobile-order-button" :disabled="busy==='order'||!selectedPackage||!paymentChannelCode" @click="prepareOrder">
                <template v-if="busy==='order'">{{ customerText("pages.catalog.show.aadacc10", "Memproses...") }}</template>
                <template v-else>
                    <svg class="lf-mobile-order-lock" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    <span>{{ customerText("pages.catalog.show.ca26b75a", "Pesan Sekarang") }}</span>
                </template>
            </button>
        </div>
    </div>

    <div v-if="noticeOpen && notices?.length" class="lf-modal-backdrop" @click.self="closeNotice">
        <div class="lf-product-notice-modal">
            <header>
                <span>{{noticeIndex + 1}}/{{notices.length}}</span>
                <button type="button" :aria-label="customerText(&quot;pages.catalog.show.attribute.aria-label.e4840d5b&quot;, &quot;Tutup informasi&quot;)" @click="closeNotice">{{ customerText("pages.catalog.show.520b4356", "×") }}</button>
            </header>
            <div class="lf-product-notice-body">
                <h2>{{formatNotice(notices[noticeIndex]?.title)}}</h2>
                <p>{{formatNotice(notices[noticeIndex]?.body)}}</p>
                <div v-if="notices.length > 1" class="lf-product-notice-actions">
                    <button type="button" class="lf-secondary" :disabled="noticeIndex===0" @click="noticeIndex=Math.max(0,noticeIndex-1)">{{ customerText("pages.catalog.show.b3ddf62a", "Sebelumnya") }}</button>
                    <button type="button" class="lf-primary" :disabled="noticeIndex===notices.length-1" @click="noticeIndex=Math.min(notices.length-1,noticeIndex+1)">{{ customerText("pages.catalog.show.c4f4ccf1", "Berikutnya") }}</button>
                </div>
            </div>
            <label class="lf-product-notice-dismiss">
                <input v-model="hideNotice" type="checkbox">
                {{ customerText("pages.catalog.show.d643d9c2", "Jangan tampilkan lagi dalam 7 hari") }}
            </label>
        </div>
    </div>

    <div v-if="voucherOpen" class="lf-modal-backdrop" @click.self="voucherOpen=false">
        <div class="lf-voucher-modal lf-voucher-legacy">
            <header>
                <div>
                    <h2>{{ customerText("pages.catalog.show.d3a9c781", "Voucher Yang Tersedia") }}</h2>
                    <p>{{ customerText("pages.catalog.show.220bf04d", "Pilih voucher untuk langsung menghitung diskon pada nominal pesananmu.") }}</p>
                </div>
                <button type="button" :aria-label="customerText(&quot;pages.catalog.show.attribute.aria-label.51cf4131&quot;, &quot;Tutup voucher&quot;)" @click="voucherOpen=false">{{ customerText("pages.catalog.show.520b4356", "×") }}</button>
            </header>
            <div v-if="voucherLoading" class="lf-voucher-state">{{ customerText("pages.catalog.show.a0485240", "Memuat voucher...") }}</div>
            <div v-else-if="voucherError" class="lf-voucher-state lf-voucher-error">{{voucherError}}</div>
            <div v-else-if="vouchers.length" class="lf-voucher-list">
                <button
                    v-for="voucher in vouchers"
                    :key="voucher.code"
                    type="button"
                    @click="applyVoucher(voucher.code)"
                >
                    <span class="lf-voucher-copy">
                        <span class="lf-voucher-topline">
                            <strong>Voucher {{voucher.code}}</strong>
                            <b>{{voucher.code}}</b>
                        </span>
                        <small>Hemat {{formatIdr(voucher.discount_idr)}}<template v-if="voucher.discount_type==='PERCENT'"> · {{voucher.discount_value}}%</template></small>
                        <em>Minimum {{formatIdr(voucher.minimum_total_idr)}}<template v-if="voucher.ends_at"> · Berlaku hingga {{formatDateId(voucher.ends_at)}}</template></em>
                    </span>
                </button>
            </div>
            <div v-else class="lf-voucher-state">{{ customerText("pages.catalog.show.65666d29", "Belum ada voucher yang aktif saat ini.") }}</div>
        </div>
    </div>

    <div v-if="confirmOpen && confirmationSnapshot" class="lf-modal-backdrop" @click.self="closeConfirmation">
        <div class="lf-confirm-modal lf-confirm-legacy">
            <div class="lf-confirm-status-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
            </div>
            <h2>{{ customerText("pages.catalog.show.e2da25fa", "Konfirmasi Pesanan") }}</h2>
            <p class="lf-confirm-copy">{{isVoucherProduct ? 'Pastikan produk, nominal, dan pembayaran yang kamu pilih sudah sesuai.' : 'Pastikan data akun dan produk yang kamu pilih sudah valid dan sesuai.'}}</p>
            <dl>
                <div v-if="confirmationSnapshot.nickname"><dt>{{ customerText("pages.catalog.show.1c08d4d9", "Username") }}</dt><dd>{{confirmationSnapshot.nickname}}</dd></div>
                <div v-for="field in fields" :key="field.field_key"><dt>{{field.label}}</dt><dd>{{confirmationSnapshot.customer_input[field.field_key] || '-'}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.1f648006", "Item") }}</dt><dd>{{confirmationSnapshot.item_name}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.ceb4d888", "Produk") }}</dt><dd>{{confirmationSnapshot.product_name}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.443c0671", "Metode Pembayaran") }}</dt><dd>{{confirmationSnapshot.payment_name}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.43352167", "Email") }}</dt><dd>{{confirmationSnapshot.email || '-'}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.b1332787", "WhatsApp") }}</dt><dd>{{confirmationSnapshot.phone || '-'}}</dd></div>
                <div v-if="confirmationSnapshot.voucher_code"><dt>{{ customerText("pages.catalog.show.3fb6f639", "Voucher") }}</dt><dd>{{confirmationSnapshot.voucher_code}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.d361488d", "Subtotal") }}</dt><dd>{{formatIdr(confirmationSnapshot.quote.subtotal_idr)}}</dd></div>
                <div v-if="confirmationSnapshot.quote.member_discount_idr"><dt>Diskon {{confirmationSnapshot.quote.member_tier_code || 'Member'}}</dt><dd>-{{formatIdr(confirmationSnapshot.quote.member_discount_idr)}}</dd></div>
                <div v-if="confirmationSnapshot.quote.voucher_discount_idr"><dt>{{ customerText("pages.catalog.show.6af5335f", "Diskon Voucher") }}</dt><dd>-{{formatIdr(confirmationSnapshot.quote.voucher_discount_idr)}}</dd></div>
                <div><dt>{{ customerText("pages.catalog.show.2688b9b5", "Biaya Pembayaran") }}</dt><dd>{{formatIdr(confirmationSnapshot.quote.fee_idr)}}</dd></div>
                <div class="total"><dt>{{ customerText("pages.catalog.show.212c4d6e", "Total Bayar") }}</dt><dd>{{formatIdr(confirmationSnapshot.quote.total_idr)}}</dd></div>
            </dl>
            <div v-if="errors.checkout?.length" class="lf-error">{{errors.checkout[0]}}</div>
            <label class="lf-confirm-agreement">
                <input v-model="agreed" type="checkbox">
                <span>{{ customerText("pages.catalog.show.69d0c398", "Dengan melanjutkan, saya menyetujui syarat & ketentuan yang berlaku.") }}</span>
            </label>
            <div class="lf-confirm-actions">
                <button class="lf-primary" :disabled="Boolean(busy)||!agreed" @click="createOrder">{{busy==='order'?'Memproses...':busy==='quote'?'Memeriksa harga...':'Pesan Sekarang'}}</button>
                <button class="lf-secondary" :disabled="Boolean(busy)" @click="closeConfirmation">{{ customerText("pages.catalog.show.860fb10d", "Batalkan") }}</button>
            </div>
        </div>
    </div></main>
</CustomerShell>
</template>
