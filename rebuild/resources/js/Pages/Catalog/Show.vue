<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';
import CheckoutPaymentMethods from '../../Components/Checkout/PaymentMethods.vue';
import CheckoutVoucherModal from '../../Components/Checkout/VoucherModal.vue';
import '../../../css/checkout.css';

const props = defineProps({
    product: Object,
    packages: Array,
    fields: Array,
    customer: Object,
    paymentChannels: Array,
    checkoutReservationMinutes: { type: Number, required: true },
    notices: Array,
    savedAccounts: Array,
    reviews: Array,
    reviewStats: Object,
    faqs: Array,
    initialPackageId: { type: String, default: '' },
});


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
const checkoutClockTick = ref(Date.now());
const checkoutExpiryOffset = ref(0);
let checkoutClockTimer;
const checkoutExpiresWib = computed(() => {
    const date = Date.parse(checkoutResult.value?.expires_at || '');
    return Number.isFinite(date) ? new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(date) : null;
});
const checkoutSecondsLeft = computed(() => {
    const date = Date.parse(checkoutResult.value?.expires_at || '');
    return Number.isFinite(date) ? Math.max(0, Math.ceil((date - checkoutClockTick.value - checkoutExpiryOffset.value) / 1000)) : null;
});
const checkoutCountdown = computed(() => checkoutSecondsLeft.value === null ? '' :
    String(Math.floor(checkoutSecondsLeft.value / 60)).padStart(2, '0') + ':' +
    String(checkoutSecondsLeft.value % 60).padStart(2, '0'));
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
let dialogReturnFocus;
let dialogPreviousOverflow = '';
function onDialogKey(event) {
    if (event.key === 'Escape') { if (busy.value === 'order' || busy.value === 'payment') return; voucherOpen.value = false; closeReview(); return; }
    if (event.key !== 'Tab') return;
    const dialog = document.querySelector('.lfx-offer-modal, .lfx-modal[role="dialog"]');
    const focusable = [...(dialog?.querySelectorAll('button:not(:disabled), input:not(:disabled), a[href], select:not(:disabled), [tabindex="0"]') || [])];
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length-1];
    if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
}
watch(() => summaryOpen.value || confirmOpen.value || voucherOpen.value, async open => {
    if (open) {
        dialogReturnFocus = document.activeElement;
        dialogPreviousOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        window.addEventListener('keydown', onDialogKey);
        await nextTick();
        document.querySelector('.lfx-offer-modal button, .lfx-modal[role="dialog"] button')?.focus();
    } else {
        window.removeEventListener('keydown', onDialogKey);
        document.documentElement.style.overflow = dialogPreviousOverflow;
        dialogReturnFocus?.focus?.();
    }
});
const noticeOpen = ref(false);
const noticeIndex = ref(0);
const hideNotice = ref(false);
const selectedSavedId = ref('');
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
const canQuote = computed(() => Boolean(selectedPackage.value && selectedPaymentChannel.value && (props.customer || !voucherCode.value.trim() || guestContactComplete.value)));
const summarySubtotal = computed(() => quote.value?.subtotal_idr ?? selectedPackage.value?.price_idr ?? 0);
const displayTotal = computed(() => quote.value?.total_idr ?? null);
const isVoucherProduct = computed(() => String(props.product?.category_slug || '').toLowerCase() === 'voucher');
const hasAccountStep = computed(() => !isVoucherProduct.value && (props.fields || []).length > 0);
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
        wallet: ['LFAMILIA Coin', 'Bayar memakai LFAMILIA Coin'],
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
        if (field.type === 'tel' && !/^(?=(?:[^0-9]*[0-9]){10})[0-9+().\-\s]{6,32}$/.test(value)) {
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
        signal: AbortSignal.timeout(20000),
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            ...(options.headers || {}),
        },
        ...options,
    }).catch(error => {
        const message = ['TimeoutError', 'AbortError'].includes(error.name)
            ? 'Koneksi terlalu lama. Periksa jaringan lalu coba lagi.'
            : 'Tidak dapat terhubung. Periksa jaringan lalu coba lagi.';
        const failure = new Error(message);
        failure.validation = { checkout: [message] };
        throw failure;
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = (response.status === 419 ? 'Sesi berakhir. Muat ulang halaman sebelum melanjutkan.' : null) || data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Permintaan gagal.';
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
    if (item.is_available === false || busy.value === 'order' || busy.value === 'payment') return;
    selectedPackageId.value = String(item.id);
    invalidateQuote();
    if (canQuote.value) void loadQuote();
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
        if (voucherCode.value && !props.customer && !guestContactComplete.value) errors.value = {voucher_code:['Lengkapi email dan WhatsApp sebelum memakai voucher.']};
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
            body: JSON.stringify({
                ...basePayload(),
                include_channel_totals: true,
                // The fee-inclusive quote is available before buyer details are entered.
                // Only send identity once complete, so typing a partial address cannot invalidate the quote.
                ...(!props.customer && !guestContactComplete.value ? { guest_email: null, guest_phone: null } : {}),
            }),
        });

        if (requestId !== quoteRequest || signature !== currentQuoteSignature()) return null;

        quote.value = result;
        channelQuotes.value = result.channel_quotes || {};
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
    if (busy.value) return;
    summaryOpen.value = false;
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
    busy.value = 'order';

    try {
        checkoutResult.value = await requestJson('/checkout/orders', {
            method: 'POST',
            body: JSON.stringify({
                ...basePayload(),
                customer_input: { ...customerInput },
                idempotency_key: confirmationSnapshot.value.idempotency_key,
            }),
        });
        checkoutExpiryOffset.value = Date.parse(checkoutResult.value.server_now) - Date.now();
        checkoutClockTick.value = Date.now();
    } catch (error) {
        errors.value = error.validation || { checkout: [error.message || 'Pesanan gagal dibuat.'] };
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

    busy.value = 'payment';
    try {
        await requestJson('/payments/orders/' + encodeURIComponent(checkoutResult.value.order_number), {
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
    checkoutClockTimer = window.setInterval(() => { checkoutClockTick.value = Date.now(); }, 1000);

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

    if (canQuote.value) void loadQuote();

    if ((props.notices || []).length) {
        noticeIndex.value = 0;
        hideNotice.value = false;
        const key = `lfamilia-notice:${props.product.slug}:${noticeVersion(props.notices || [])}`;
        const hiddenUntil = Number(window.localStorage.getItem(key) || 0);
        noticeOpen.value = hiddenUntil < Date.now();
    }
});

onBeforeUnmount(() => {
    if (checkoutClockTimer) window.clearInterval(checkoutClockTimer);
    if (summaryOpen.value || confirmOpen.value || voucherOpen.value) document.documentElement.style.overflow = dialogPreviousOverflow;
    window.removeEventListener('keydown', onDialogKey);
    if (contactQuoteTimer) window.clearTimeout(contactQuoteTimer);
    if (nicknameAutoTimer) window.clearTimeout(nicknameAutoTimer);

});

let contactQuoteTimer;
watch([guestEmail, guestPhone], () => {
    invalidateQuote();
    if (contactQuoteTimer) window.clearTimeout(contactQuoteTimer);
    if (canQuote.value) contactQuoteTimer = window.setTimeout(() => { void loadQuote(); }, 350);
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

const channelQuotes = ref({});
watch(() => currentQuoteSignature(), () => { channelQuotes.value = {}; });
const reviewQuote = computed(() => confirmOpen.value ? confirmationSnapshot.value?.quote : quote.value);
function closeReview() { summaryOpen.value = false; closeConfirmation(); }
</script>

<template>

<Head :title="product.name" />
<CustomerShell checkout>
<div class="lfx" data-checkout-boundary>
  <main>
    <div class="lfx-hero"><img v-if="product.banner_url" :src="product.banner_url" :alt="'Banner '+product.name"/><span class="lfx-hero-overlay"></span></div>
    <section class="lfx-product-header"><div class="lfx-container lfx-product-header-inner">
      <div class="lfx-product-cover"><div class="lfx-cover-frame"><img v-if="product.image_url" :src="product.image_url" :alt="product.name"/><span v-else>LF</span></div></div>
      <div class="lfx-product-intro"><div class="lfx-breadcrumb"><Link href="/">Beranda</Link><span>›</span><span>{{product.category_name}}</span><span>›</span><strong>{{product.name}}</strong></div><div class="lfx-overline">DIGITAL TOP UP · PREMIUM EXPERIENCE</div><h1>{{product.name}}</h1><p>{{product.publisher||'LFAMILIA STORE'}}</p></div><div class="lfx-trust"><span><b>⚡</b> Proses Cepat</span><span><b>✧</b> {{customerText("checkout.support_label", "Layanan Chat 24/7")}}</span><span><b>◷</b> Pembayaran Aman</span></div>

    </div></section>
    <div class="lfx-container lfx-content"><p v-if="firstCheckoutError" role="alert" class="lfx-error">{{firstCheckoutError}}</p>
      <div class="lfx-mobile-tabs"><button :class="{active:activeTab==='transaction'}" @click="activeTab='transaction'">Transaksi</button><button :class="{active:activeTab==='details'}" @click="activeTab='details'">Keterangan</button></div>
      <div class="lfx-layout">
        <div class="lfx-sections">
          <template v-if="activeTab==='transaction'">
            <section v-if="hasAccountStep" id="lfx-account" class="lfx-panel"><header class="lfx-panel-header"><span class="lfx-number">1</span><div><h2>{{customerText("checkout.account_title", "Data Akun")}}</h2></div></header><div class="lfx-panel-content"><label v-if="savedAccountItems.length" class="lfx-field lfx-saved-account"><span>Akun tersimpan</span><select v-model="selectedSavedId" @change="chooseSavedAccount(selectedSavedId)"><option value="">Isi manual</option><option v-for="saved in savedAccountItems" :key="saved.id" :value="String(saved.id)">{{saved.label}}{{saved.nickname?' · '+saved.nickname:''}}</option></select></label><div class="lfx-field-grid" :class="{single:fields.length===1}"><label v-for="field in fields" :key="field.field_key" class="lfx-field"><span>{{field.label}} <b v-if="field.is_required">*</b><i title="Pastikan data akun benar">ⓘ</i></span><input v-model="customerInput[field.field_key]" :placeholder="field.placeholder||'Masukkan '+field.label" :type="['email','tel'].includes(field.type)?field.type:'text'" :inputmode="field.type==='number'?'numeric':undefined" :required="field.is_required" maxlength="255" autocomplete="off"/></label></div><p v-for="field in fields.filter(x=>fieldError(x.field_key))" :key="field.field_key" class="lfx-error">{{fieldError(field.field_key)}}</p><div v-if="busy==='nickname'" class="lfx-hint">Memeriksa akun…</div><div v-else-if="nicknameResult?.verified" class="lfx-hint">✓ {{nicknameResult.nickname}}</div><div v-else-if="nicknameResult?.warning" class="lfx-hint">{{nicknameResult.warning}}</div><div class="lfx-hint"><span>i</span> {{customerText("checkout.account_hint", "Pastikan ID atau Server sudah sesuai sebelum memesan.")}}</div></div></section>
            <section id="lfx-nominal" class="lfx-panel">
              <header class="lfx-panel-header"><span class="lfx-number">2</span><div><h2>{{customerText("checkout.nominal_title", "Pilih Nominal")}}</h2></div></header>
              <div class="lfx-panel-content">
                <div v-if="packageTabs.length" class="lfx-category-tabs" role="tablist" aria-label="Kategori dari LFAMILIA"><button v-for="item in packageTabs" :key="item" role="tab" :aria-selected="(activePackageTab||packageTabs[0])===item" :class="{active:(activePackageTab||packageTabs[0])===item}" @click="activePackageTab=item">{{item}}</button></div>
                <section v-for="itemsGroup in visiblePackageGroups" :key="itemsGroup.name||'default'" class="lfx-nominal-group">
                  <div v-if="itemsGroup.name" class="lfx-subtitle"><span class="lfx-mini-star">✦</span><h3>{{itemsGroup.name}}</h3><small>{{itemsGroup.items.length}} pilihan</small></div>
                  <div class="lfx-nominal-grid" role="radiogroup" :aria-label="itemsGroup.name || 'Pilih nominal'">
                    <div v-for="item in itemsGroup.items" :key="item.id"
                      class="lfx-nominal" :class="{selected:String(selectedPackageId)===String(item.id),unavailable:item.is_available===false}"
                      role="radio" :aria-checked="String(selectedPackageId)===String(item.id)"
                      :aria-disabled="item.is_available===false" :tabindex="item.is_available===false?-1:0"
                      @click="item.is_available!==false&&choosePackage(item)"
                      @keydown.enter.prevent="item.is_available!==false&&choosePackage(item)"
                      @keydown.space.prevent="item.is_available!==false&&choosePackage(item)">
                      <span class="lfx-check" aria-hidden="true">{{String(selectedPackageId)===String(item.id)?'✓':''}}</span>
                      <span class="lfx-nominal-top">
                        <span class="lfx-nominal-name"><strong>{{item.name}}</strong><small>{{item.group_name||product.name}}</small></span>
                        <img v-if="item.image_url" class="lfx-item-art" :src="item.image_url" alt="" loading="lazy"/>
                        <span v-else class="lfx-diamond" aria-hidden="true">✦</span>
                      </span>
                      <span class="lfx-nominal-bottom"><b>{{item.is_available===false?'Tidak tersedia':formatIdr(item.price_idr)}}</b><small v-if="String(selectedPackageId)===String(item.id)">DIPILIH</small></span>
                    </div>
                  </div>
                </section>
                <div v-if="!visiblePackageGroups.length" class="lfx-empty">Nominal belum tersedia.</div>
              </div>
            </section>
            <section id="lfx-payment" class="lfx-panel">
              <header class="lfx-panel-header"><span class="lfx-number">3</span><div><h2>{{customerText("checkout.payment_title", "Pilih Pembayaran")}}</h2></div></header>
              <CheckoutPaymentMethods :payment-channels="paymentChannels" :selected-code="paymentChannelCode" :quotes="channelQuotes" :current-quote="quote" :locked="busy==='order'||busy==='payment'" :has-package="Boolean(selectedPackage)" :loading-quotes="busy==='quote'" :customer="customer" @select="choosePayment"/>
            </section>
            <section class="lfx-panel" id="lfx-voucher"><header class="lfx-panel-header"><span class="lfx-number">4</span><div><h2>{{customerText("checkout.voucher_title", "Voucher Diskon")}}</h2></div></header><div class="lfx-panel-content"><div class="lfx-voucher-input"><span>⌗</span><input  :value="voucherCode" @input="updateVoucherInput($event.target.value)" maxlength="100" placeholder="Masukkan kode voucher" aria-label="Kode voucher diskon" autocapitalize="characters" @keyup.enter="loadQuote"/><button @click="loadQuote">Gunakan</button></div><p v-if="errors.voucher_code" class="lfx-error">{{errors.voucher_code[0]}}</p><div v-if="quote?.voucher_code" class="lfx-applied"><span>✓</span><div><strong>{{quote.voucher_code}} berhasil dipakai</strong><small>Diskon {{formatIdr(reviewQuote?.voucher_discount_idr || 0)}}</small></div><button @click="updateVoucherInput('');loadQuote()">Hapus</button></div><button class="lfx-voucher-browse" @click="openVoucherPicker"><span>♧</span><strong>Lihat voucher yang tersedia</strong><b>→</b></button></div></section>
            <section id="lfx-contact" class="lfx-panel"><header class="lfx-panel-header"><span class="lfx-number">5</span><div><h2>{{customerText("checkout.contact_title", "Detail Kontak")}}</h2></div></header><div class="lfx-panel-content"><div class="lfx-field-grid"><label class="lfx-field"><span>Nomor WhatsApp <b>*</b></span><input :value="guestPhone" @input="guestPhone=normalizeWhatsapp($event.target.value)" :readonly="Boolean(customer)" type="tel" inputmode="tel" placeholder="081234567890"/></label><label class="lfx-field"><span>Email <b>*</b></span><input v-model="guestEmail" :readonly="Boolean(customer)" type="email" placeholder="nama@email.com"/></label></div><div class="lfx-hint"><span>i</span> {{customerText("pages.catalog.show.efbf2ce0", "Kami hanya memakai kontak untuk invoice dan status transaksi.")}}</div></div></section>
          </template>
<section v-else class="lfx-panel lfx-details lf-product-details">
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

                        <input v-model="reviewTitle" maxlength="100" :placeholder="customerText('pages.catalog.show.attribute.placeholder.5f3aed05', 'Masukkan judul singkat (opsional)')">
                        <textarea v-model="reviewBody" required minlength="5" maxlength="1200" :placeholder="customerText('pages.catalog.show.attribute.placeholder.98e9ee9', 'Ceritakan pengalaman transaksimu')"></textarea>
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


            </div>
    </div>
  </main>
  <div class="lfx-bottom-bar" role="region" aria-label="Ringkasan dan pembayaran">
    <div class="lfx-bottom-inner">
      <button id="lfx-bottom-summary" type="button" class="lfx-bottom-summary" @click="summaryOpen=true" aria-label="Lihat rincian ringkasan pesanan">
        <span class="lfx-bottom-thumb" aria-hidden="true"><img v-if="selectedPackage?.image_url || product.image_url" :src="selectedPackage?.image_url || product.image_url" alt=""/><span v-else>✦</span></span>
        <span class="lfx-bottom-copy"><span class="lfx-bottom-caption">Ringkasan Pesanan · {{product.name}}</span><strong>{{selectedPackage?.name || 'Belum ada nominal dipilih'}}</strong></span>
        <span class="lfx-bottom-amount"><span>Total Bayar</span><strong>{{selectedPackage ? (displayTotal===null ? 'Menghitung…' : formatIdr(displayTotal)) : 'Rp 0'}}</strong></span>
        <span class="lfx-bottom-chevron" aria-hidden="true">⌃</span>
      </button>
      <button :disabled="Boolean(busy)" id="lfx-bottom-pay" class="lfx-bottom-pay" type="button" @click="prepareOrder">{{busy==='order'||busy==='payment'?'Memproses…':'Bayar Sekarang'}} <b aria-hidden="true">→</b></button>
    </div>
  </div>

    <div v-if="noticeOpen && notices?.length" class="lfx-modal-backdrop" @click.self="closeNotice">
        <div class="lfx-modal lf-product-notice-modal">
            <header>
                <span>{{noticeIndex + 1}}/{{notices.length}}</span>
                <button type="button" :aria-label="customerText('pages.catalog.show.attribute.aria-label.e4840d5b', 'Tutup informasi')" @click="closeNotice">{{ customerText("pages.catalog.show.520b4356", "×") }}</button>
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


  <CheckoutVoucherModal v-if="voucherOpen" :vouchers="vouchers" :loading="voucherLoading" :error="voucherError" @close="voucherOpen=false" @apply="applyVoucher"/>
  <div v-if="summaryOpen || confirmOpen" class="lfx-modal-backdrop" @click.self="closeReview">
    <div class="lfx-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
      <div class="lfx-modal-header">
        <div><small>{{confirmOpen ? 'FINAL CHECK' : 'ORDER OVERVIEW'}}</small><h2 id="confirm-title">{{confirmOpen ? 'Konfirmasi Pesanan' : 'Ringkasan Pesanan'}}</h2></div>
        <button type="button" aria-label="Tutup ringkasan" @click="closeReview">×</button>
      </div>
      <p class="lfx-modal-description">{{confirmOpen ? 'Periksa kembali rincian pesanan sebelum melanjutkan.' : 'Rincian produk dan estimasi pembayaran sebelum checkout.'}}</p>
       <div class="lfx-modal-description text-xs leading-5">
         <p v-if="checkoutExpiresWib">Bayar sebelum {{checkoutExpiresWib}} WIB · Sisa {{checkoutCountdown}}</p>
         <p v-else>Batas pembayaran: {{checkoutReservationMinutes}} menit sejak pesanan dibuat. Jam pasti dan countdown tersedia di halaman pembayaran.</p>
         <p>Pembayaran terlambat tidak diproses otomatis dan masuk peninjauan admin.</p>
       </div>
      <div class="lfx-confirm-rows">
        <div><span>Produk</span><b>{{product.name}}</b></div>
        <div v-for="field in fields" :key="field.field_key" v-show="String(customerInput[field.field_key]||'').trim()"><span>{{field.label}}</span><b>{{customerInput[field.field_key]}}</b></div>
        <div><span>Nominal</span><b>{{selectedPackage?.name || 'Belum dipilih'}}</b></div>
        <div><span>Pembayaran</span><b>{{selectedPaymentChannel?.name || 'Belum dipilih'}}</b></div>
        <div v-if="guestPhone"><span>WhatsApp</span><b>{{guestPhone}}</b></div>
        <div v-if="selectedPackage"><span>Harga produk</span><b>{{formatIdr(reviewQuote?.subtotal_idr ?? summarySubtotal)}}</b></div>
        <div v-if="selectedPackage && reviewQuote?.voucher_discount_idr"><span>Diskon {{reviewQuote?.voucher_code}}</span><b class="lfx-saved">− {{formatIdr(reviewQuote?.voucher_discount_idr || 0)}}</b></div>
        <div v-if="selectedPackage && selectedPaymentChannel"><span>Biaya pembayaran</span><b>{{formatIdr(reviewQuote?.fee_idr || 0)}}</b></div>
        <div v-if="reviewQuote?.member_discount_idr"><span>Diskon {{reviewQuote.member_tier_code || 'Member'}}</span><b class="lfx-saved">− {{formatIdr(reviewQuote.member_discount_idr)}}</b></div><div class="lfx-confirm-final"><span>Total Pembayaran</span><b>{{selectedPackage ? (displayTotal===null ? 'Menghitung…' : formatIdr(displayTotal)) : 'Rp 0'}}</b></div>
      </div>
      <p v-if="firstCheckoutError" role="alert" class="lfx-error">{{firstCheckoutError}}</p><label v-if="confirmOpen" class="lfx-agreement"><input v-model="agreed" type="checkbox"/> Dengan melanjutkan, saya menyetujui <Link href="/terms">syarat & ketentuan</Link>.</label><div class="lfx-modal-buttons">
        <button type="button" @click="closeReview">{{confirmOpen ? 'Kembali' : 'Tutup'}}</button>
        <button v-if="confirmOpen" type="button" :disabled="Boolean(busy)||!agreed" @click="createOrder">{{busy?'Memproses…':'Bayar Sekarang →'}}</button>
        <button v-else type="button" :disabled="Boolean(busy)" @click="prepareOrder">Bayar Sekarang →</button>
      </div>

    </div>
  </div>
</div>
</CustomerShell>
</template>
