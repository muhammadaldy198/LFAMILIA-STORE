<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { num, rupiah, wibDateTime, tone, copyText } from '../../lib/adminFormat';
import { confirmSensitive } from '../../lib/sensitivePrompt';

const props = defineProps({
    isSuperAdmin: Boolean,
    gateways: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    routes: { type: Array, default: () => [] },
    transactions: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    walletSettings: { type: Object, default: () => ({}) },
    manualQrisAsset: Object,
    manualPayments: { type: Array, default: () => [] },
    refundReviews: { type: Array, default: () => [] },
    pageSettings: { type: Object, default: () => ({}) },
    callbackUrls: { type: Object, default: () => ({}) },
    providerMethods: { type: Array, default: () => [] },
});

const tab = ref('settings');
const tabs = [
    ['settings', 'Metode Pembayaran'], ['transactions', 'Transaksi Pembayaran'],
    ['page', 'Halaman Pembayaran'],
];

const routes = reactive(props.routes.map(item => ({
    ...item,
    custom_fee: item.fee_flat_idr !== null || item.fee_percent_bps !== null,
    fee_percent: Number(item.fee_percent_bps || 0) / 100,
})));
watch(() => props.routes, items => {
    routes.splice(0, routes.length, ...items.map(item => ({
        ...item,
        custom_fee: item.fee_flat_idr !== null || item.fee_percent_bps !== null,
        fee_percent: Number(item.fee_percent_bps || 0) / 100,
    })));
}, { deep: true });

const categoryLabel = code => props.categories.find(item => item.code === code)?.name || code;
const methodLabel = value => ({
    QRIS: 'QRIS', VIRTUAL_ACCOUNT: 'Virtual Account', EWALLET: 'E-Wallet',
    RETAIL: 'Gerai Retail', WALLET: 'Saldo', OTHER: 'Lainnya',
}[value] || value);

const freeMethod = (method, code) => method === 'WALLET' || ['saldo', 'manual_qris'].includes(code);
const routeIsFree = row => freeMethod(row.channel_method, row.channel_code);
const routeFeeText = row => {
    if (routeIsFree(row)) return 'Gratis';
    const flat = row.custom_fee && row.fee_flat_idr !== null ? Number(row.fee_flat_idr) : Number(row.channel_fee_flat_idr || 0);
    const pct = row.custom_fee && row.fee_percent_bps !== null ? Number(row.fee_percent_bps) : Number(row.channel_fee_percent_bps || 0);
    const parts = [];
    if (pct) parts.push((pct / 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%');
    if (flat) parts.push(rupiah(flat));
    return parts.join(' + ') || 'Rp0';
};

const activeRoutesFor = channel => routes.filter(route => route.payment_channel_id === channel.id && route.is_active);
const gatewayRoutesFor = channel => routes.filter(route => route.payment_channel_id === channel.id && route.selectable);
const selectedGatewayId = (channel, purpose) => {
    const supportKey = purpose === 'order' ? 'supports_order' : 'supports_wallet_topup';
    const assigned = gatewayRoutesFor(channel).filter(route => route.is_active && route[supportKey]);
    return assigned.length === 1 ? String(assigned[0].id) : '';
};
const chooseGateway = (channel, purpose, routeId) => {
    if (!routeId) return;
    router.post('/admin/payments/channels/' + channel.id + '/select-gateway', {
        route_id: Number(routeId), purpose,
    }, { preserveScroll: true, onError: () => router.reload({ only: ['channels', 'routes'] }) });
};
const chargedFeeText = channel => {
    const active = activeRoutesFor(channel);
    if (!active.length) return 'Belum ada gateway aktif';
    return active.map(route => route.gateway_name + ': ' + routeFeeText(route)).join(' · ');
};

const statusLabel = value => ({
    CREATING: 'Menyiapkan', SENDING: 'Mengirim permintaan', PENDING: 'Menunggu', UNKNOWN: 'Belum pasti',
    PAID: 'Dibayar', FAILED: 'Gagal', EXPIRED: 'Kedaluwarsa', CANCELLED: 'Dibatalkan',
    REJECTED: 'Ditolak', REFUNDED: 'Dikembalikan',
}[value] || value);

// ---------- editor metode ----------
const categoryEditor = ref(null);
const categoryErrors = ref({});
const categorySaving = ref(false);
const categoryPendingDelete = ref(null);
const openCategory = (category = null) => {
    categoryEditor.value = category ? { ...category } : {
        code: '', name: '', description: '', sort_order: (props.categories.length + 1) * 10, is_active: true,
    };
    categoryErrors.value = {};
};
const saveCategory = () => {
    if (!categoryEditor.value || categorySaving.value) return;
    const row = categoryEditor.value;
    const payload = { code: row.code, name: row.name, description: row.description || null,
        sort_order: Number(row.sort_order || 0), is_active: Boolean(row.is_active) };
    categorySaving.value = true;
    const url = row.channels_count === undefined ? '/admin/payments/categories' : '/admin/payments/categories/' + encodeURIComponent(row.code);
    const method = row.channels_count === undefined ? 'post' : 'put';
    router[method](url, payload, { preserveScroll: true, onSuccess: () => { categoryEditor.value = null; },
        onError: errors => { categoryErrors.value = errors; }, onFinish: () => { categorySaving.value = false; } });
};
const deleteCategory = () => {
    if (!categoryPendingDelete.value) return;
    router.delete('/admin/payments/categories/' + encodeURIComponent(categoryPendingDelete.value.code), {
        preserveScroll: true,
        onSuccess: () => { categoryPendingDelete.value = null; },
        onError: errors => { categoryErrors.value = errors; categoryPendingDelete.value = null; },
    });
};
const editor = ref(null);
const logoFile = ref(null);
const logoPreview = ref('');
const saving = ref('');
const notice = ref('');
const errors = ref({});
const advanced = ref(false);

const openCreate = () => {
    editor.value = {
        id: null, code: '', method: 'VIRTUAL_ACCOUNT', category_code: 'va', name: '', description: '',
        gateway_code: 'MIDTRANS', provider_method: '',
        fee_flat_idr: 0, fee_percent: 0, supports_order: true, supports_wallet_topup: true,
        sort_order: props.channels.length ? Math.max(...props.channels.map(item => Number(item.sort_order || 0))) + 10 : 10,
        is_active: false,
    };
    logoFile.value = null;
    logoPreview.value = '';
    advanced.value = false;
    errors.value = {};
};
const onMethodChange = () => {
    if (!editor.value || editor.value.id) return;
    const defaultCodes = { WALLET: 'wallet', QRIS: 'qris', VIRTUAL_ACCOUNT: 'va', EWALLET: 'ewallet', RETAIL: 'retail', OTHER: 'other' };
    editor.value.category_code = defaultCodes[editor.value.method] || 'other';
    editor.value.provider_method = '';
};
const openEdit = channel => {
    editor.value = { ...channel, gateway_code: 'MIDTRANS', provider_method: '', fee_percent: Number(channel.fee_percent_bps || 0) / 100 };
    logoFile.value = null;
    logoPreview.value = channel.logo_url || '';
    advanced.value = false;
    errors.value = {};
};
const closeEditor = () => { editor.value = null; logoFile.value = null; logoPreview.value = ''; };

const chooseLogo = event => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        notice.value = 'Ukuran logo paling besar 2 MB.';
        event.target.value = '';
        return;
    }
    logoFile.value = file;
    const reader = new FileReader();
    reader.onload = () => { logoPreview.value = String(reader.result || ''); };
    reader.readAsDataURL(file);
};

const saveChannel = () => {
    if (!editor.value || saving.value) return;
    const row = editor.value;
    const free = freeMethod(row.method, row.code);
    const payload = {
        code: String(row.code || '').trim().toLowerCase(),
        method: row.method,
        category_code: row.category_code,
        name: row.name,
        description: row.description || null,
        fee_flat_idr: free ? 0 : Number(row.fee_flat_idr || 0),
        fee_percent_bps: free ? 0 : Math.max(0, Math.min(9999, Math.round(Number(row.fee_percent || 0) * 100))),
        supports_order: Boolean(row.supports_order),
        supports_wallet_topup: Boolean(row.supports_wallet_topup),
        sort_order: Number(row.sort_order || 0),
        is_active: Boolean(row.is_active),
        ...((!row.id || !routes.some(route => route.payment_channel_id === row.id))
            ? { gateway_code: row.gateway_code, provider_method: String(row.provider_method || '').trim() } : {}),
    };
    saving.value = 'channel';
    errors.value = {};
    const options = {
        preserveScroll: true,
        onSuccess: page => {
            const saved = (page.props.channels || []).find(item => item.code === payload.code);
            if (logoFile.value && saved?.id) {
                const file = logoFile.value;
                logoFile.value = null;
                router.post('/admin/payments/channels/' + saved.id + '/logo', { image: file }, {
                    forceFormData: true, preserveScroll: true, onFinish: closeEditor,
                });
                return;
            }
            closeEditor();
        },
        onError: bag => { errors.value = bag; },
        onFinish: () => { saving.value = ''; },
    };
    if (row.id) router.put('/admin/payments/channels/' + row.id, payload, options);
    else router.post('/admin/payments/channels', payload, options);
};

const pendingChannel = ref(null);
const removeChannel = () => {
    const id = pendingChannel.value.id;
    router.delete('/admin/payments/channels/' + id, {
        preserveScroll: true,
        onSuccess: () => { if (editor.value?.id === id) editor.value = null; },
        onFinish: () => { pendingChannel.value = null; },
    });
};

const syncChannels = () => router.post('/admin/payments/channels/sync', {}, { preserveScroll: true });

const routesForEditor = computed(() => editor.value
    ? routes.filter(route => route.payment_channel_id === editor.value.id)
    : []);
const providerMethodChoices = computed(() => (props.providerMethods || []).filter(item =>
    item.gateway === editor.value?.gateway_code && item.method === editor.value?.method));

const saveRoute = row => {
    router.put('/admin/payments/routes/' + row.id, {
        priority: Number(row.priority || 0),
        supports_order: Boolean(row.supports_order),
        supports_wallet_topup: Boolean(row.supports_wallet_topup),
        is_active: Boolean(row.is_active),
        fee_flat_idr: routeIsFree(row) ? 0 : (row.custom_fee ? Number(row.fee_flat_idr || 0) : null),
        fee_percent_bps: routeIsFree(row) ? 0 : (row.custom_fee ? Math.max(0, Math.min(9999, Math.round(Number(row.fee_percent || 0) * 100))) : null),
    }, { preserveScroll: true });
};

// ---------- QRIS manual ----------
const manualQrisMethod = computed(() => props.channels.find(channel => channel.code === 'manual_qris'));
const manualQrisReady = computed(() => Boolean(
    props.manualQrisAsset?.is_active && props.manualQrisAsset?.image_url
    && manualQrisMethod.value?.available_order
));
const uploadQris = event => {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post('/admin/payments/manual-qris/image', { image: file }, {
        forceFormData: true, preserveScroll: true,
        onFinish: () => { event.target.value = ''; },
    });
};
const toggleQris = () => {
    router.put('/admin/payments/manual-qris', { is_active: !manualQrisReady.value }, { preserveScroll: true });
};

// ---------- saldo ----------
const wallet = reactive({
    minimum_topup_idr: Number(props.walletSettings?.minimum_topup_idr || 10000),
    topup_enabled: props.walletSettings?.topup_enabled !== false,
});
const saveWallet = () => {
    router.put('/admin/payments/settings', {
        minimum_topup_idr: Number(wallet.minimum_topup_idr || 0),
        topup_enabled: Boolean(wallet.topup_enabled),
    }, { preserveScroll: true });
};

// ---------- konfirmasi manual ----------
const confirmManual = async id => {
    if (!(await confirmSensitive('Konfirmasi bahwa pembayaran QRIS manual ini benar-benar sudah diterima?'))) return;
    router.post('/admin/payments/manual/' + id + '/confirm', {}, { preserveScroll: true });
};

// ---------- tinjauan refund top up ----------
const review = reactive({ open: false, id: 0, customer: '', reason: '' });
const openReview = row => {
    review.open = true;
    review.id = row.id;
    review.customer = row.customer_name;
    review.reason = '';
};
const submitReview = () => {
    if (review.reason.trim().length < 3) return;
    router.post('/admin/payments/topups/' + review.id + '/close-review', { reason: review.reason.trim() }, {
        preserveScroll: true,
        onSuccess: () => { review.open = false; review.reason = ''; },
    });
};

// ---------- transaksi ----------
const filter = reactive({
    q: props.filters?.q || '', status: props.filters?.status || '',
    source: props.filters?.source || '', channel: Number(props.filters?.channel || 0),
    per_page: Number(props.filters?.per_page || 25),
});
const applyFilter = () => {
    router.get('/admin/payments', {
        q: filter.q || undefined, status: filter.status || undefined,
        source: filter.source || undefined, channel: filter.channel || undefined,
        per_page: filter.per_page,
    }, { preserveState: true, preserveScroll: true, onSuccess: () => { tab.value = 'transactions'; } });
};
const resetFilter = () => {
    Object.assign(filter, { q: '', status: '', source: '', channel: 0, per_page: 25 });
    applyFilter();
};
const selected = ref(null);
const copied = ref('');
const copy = async value => {
    if (!value) return;
    if (await copyText(value)) {
        copied.value = value;
        notice.value = 'Alamat notifikasi disalin.';
        setTimeout(() => { copied.value = ''; }, 1800);
    }
};

// ---------- halaman pembayaran ----------
const page = reactive({ ...(props.pageSettings || {}) });
watch(() => props.pageSettings, value => Object.assign(page, value || {}), { deep: true });
const savePage = () => {
    router.put('/admin/payments/page-settings', { ...page }, { preserveScroll: true });
};
const uploadHeader = event => {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post('/admin/payments/page-settings/header', { image: file }, {
        forceFormData: true, preserveScroll: true,
        onFinish: () => { event.target.value = ''; },
    });
};

const successRate = computed(() => {
    const total = Number(props.summary?.total || 0);
    return total ? Math.round((Number(props.summary?.paid || 0) / total) * 100) : 0;
});
</script>

<template>
    <Head title="Pembayaran" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Pembayaran</h1>
                <p>Kelola nama dan urutan metode, tarif tiap gateway, pembayaran pesanan, top up LFAMILIA Coin, dan transaksi dalam satu tempat.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
            </div>
        </header>

        <p v-if="notice" class="adm-ok" role="status">{{ notice }}</p>

        <div class="adm-tabs" role="tablist" aria-label="Bagian pembayaran">
            <button v-for="[key, text] in tabs" :key="key" type="button" role="tab" :aria-selected="tab === key" @click="tab = key">{{ text }}</button>
        </div>

        <!-- ============ TAB 1: METODE ============ -->
        <template v-if="tab === 'settings'">
            <section class="adm-stats">
                <div class="adm-stat"><span class="lb">Total pembayaran</span><strong class="vl">{{ num(summary.total) }}</strong></div>
                <div class="adm-stat"><span class="lb">Nilai berhasil</span><strong class="vl" style="font-size:16px">{{ rupiah(summary.paid_amount_idr) }}</strong><span class="nt">{{ num(summary.paid) }} pembayaran</span></div>
                <div class="adm-stat"><span class="lb">Menunggu</span><strong class="vl" style="color:var(--a-warn)">{{ num(summary.pending) }}</strong></div>
                <div class="adm-stat"><span class="lb">Berhasil</span><strong class="vl">{{ successRate }}%</strong></div>
                <div class="adm-stat"><span class="lb">Gagal / kedaluwarsa</span><strong class="vl" style="color:var(--a-danger)">{{ num(summary.failed) }}</strong><span class="nt">{{ num(summary.refunded) }} dikembalikan</span></div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Kategori Pembayaran</h2>
                        <p>Atur judul kategori, deskripsi, urutan, dan visibilitas di checkout game maupun top up LFAMILIA Cash.</p>
                    </div>
                    <button v-if="isSuperAdmin" type="button" class="adm-btn primary" @click="openCategory()">Tambah kategori</button>
                </div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>Kategori dan tindakan</th><th>Urutan</th><th>Metode</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-for="category in categories" :key="category.code">
                                <td><strong>{{ category.name }}</strong><div class="adm-note">{{ category.description || category.code }}</div>
                                    <div v-if="isSuperAdmin" style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
                                        <button class="adm-btn sm" type="button" @click="openCategory(category)">Ubah kategori</button>
                                        <button v-if="!['wallet','other'].includes(category.code) && !category.channels_count" class="adm-btn sm danger" type="button" @click="categoryPendingDelete = category">Hapus</button>
                                    </div>
                                </td>
                                <td>{{ category.sort_order }}</td>
                                <td>{{ category.channels_count }} metode</td>
                                <td><span class="adm-tag" :class="category.is_active ? 'ok' : 'bad'">{{ category.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Metode Pembayaran</h2>
                        <p>Admin dapat memilih gateway per metode untuk pesanan atau top up. Tidak ada gateway utama/cadangan atau perpindahan otomatis.</p>
                    </div>
                    <div v-if="isSuperAdmin" style="display:flex;gap:6px;flex-wrap:wrap">
                        <button type="button" class="adm-btn" @click="syncChannels">Pulihkan metode bawaan</button>
                        <button type="button" class="adm-btn primary" @click="openCreate">Tambah metode</button>
                    </div>
                </div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr><th>Metode dan tindakan</th><th>Kategori</th><th>Jenis teknis</th><th>Gateway terpilih</th><th>Biaya ditagih</th><th>Kesiapan</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="channel in channels" :key="channel.id">
                                <td>
                                    <div style="display:flex;gap:8px;align-items:center;min-width:180px">
                                        <span class="adm-logo">
                                            <img v-if="channel.logo_url" :src="channel.logo_url" :alt="channel.name">
                                            <span v-else class="adm-note">—</span>
                                        </span>
                                        <div>
                                            <strong>{{ channel.name }}</strong>
                                            <div class="adm-note">Posisi {{ channel.sort_order }} di pilihan pembayaran</div>
                                            <div class="adm-note">{{ channel.description || channel.code }}</div>
                                            <div v-if="isSuperAdmin" style="display:flex;gap:4px;margin-top:8px;flex-wrap:wrap">
                                                <button type="button" class="adm-btn sm" @click="openEdit(channel)">Ubah metode</button>
                                                <button type="button" class="adm-btn sm danger" @click="pendingChannel = channel">Hapus</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><strong>{{ categoryLabel(channel.category_code) }}</strong></td>
                                <td>{{ methodLabel(channel.method) }}</td>
                                <td>
                                    <div v-if="gatewayRoutesFor(channel).length > 0" style="display:grid;gap:6px;min-width:160px">
                                        <label v-if="channel.supports_order" class="adm-field">
                                            <span>Pesanan</span>
                                            <select class="adm-input" :value="selectedGatewayId(channel, 'order')" @change="chooseGateway(channel, 'order', $event.target.value)">
                                                <option value="" disabled>Belum dipilih</option>
                                                <option v-for="route in gatewayRoutesFor(channel)" :key="'order-'+route.id" :value="route.id">{{ route.gateway_name }}</option>
                                            </select>
                                        </label>
                                        <label v-if="channel.supports_wallet_topup" class="adm-field">
                                            <span>Top up saldo</span>
                                            <select class="adm-input" :value="selectedGatewayId(channel, 'topup')" @change="chooseGateway(channel, 'topup', $event.target.value)">
                                                <option value="" disabled>Belum dipilih</option>
                                                <option v-for="route in gatewayRoutesFor(channel)" :key="'topup-'+route.id" :value="route.id">{{ route.gateway_name }}</option>
                                            </select>
                                        </label>
                                    </div>
                                    <span v-else class="adm-note">{{ channel.route_count ? 'Gateway internal' : 'Belum terhubung — klik Ubah' }}</span>
                                </td>
                                <td>
                                    <strong>{{ chargedFeeText(channel) }}</strong>
                                    <div class="adm-note">Dasar: {{ (Number(channel.fee_percent_bps || 0) / 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) }}% + {{ rupiah(channel.fee_flat_idr) }}</div>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                                        <span class="adm-tag" :class="channel.available_order ? 'ok' : 'bad'">Pesanan {{ channel.available_order ? 'siap' : 'belum siap' }}</span>
                                        <span v-if="channel.supports_wallet_topup" class="adm-tag" :class="channel.available_topup ? 'ok' : 'bad'">Top up {{ channel.available_topup ? 'siap' : 'belum siap' }}</span>
                                        <p v-if="channel.supports_order && !channel.available_order" class="adm-note" style="width:100%">{{ channel.order_readiness_reason }}</p>
                                        <p v-if="channel.supports_wallet_topup && !channel.available_topup" class="adm-note" style="width:100%">Top up: {{ channel.topup_readiness_reason }}</p>
                                    </div>
                                </td>
                                <td><span class="adm-tag" :class="channel.is_active ? 'ok' : 'bad'">{{ channel.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!channels.length" class="adm-empty">Belum ada metode pembayaran.</p>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>QRIS manual</h2>
                        <p>QRIS manual berbeda dari QRIS gateway: pembayaran harus dikonfirmasi admin sebelum pesanan diproses, dan gambar QRIS wajib aktif.</p>
                    </div>
                    <span class="adm-tag" :class="manualQrisReady ? 'ok' : 'bad'">{{ manualQrisReady ? 'Siap di checkout' : 'Belum siap di checkout' }}</span>
                </div>
                <div class="adm-card-body" style="display:grid;gap:10px">
                    <div class="adm-media-preview wide">
                        <img v-if="manualQrisAsset?.image_url" :src="manualQrisAsset.image_url" alt="Gambar QRIS manual">
                        <span v-else class="adm-note">Gambar QRIS belum diunggah.</span>
                    </div>
                    <p class="adm-note">Rekomendasi: 1000 × 1000 px (1:1), PNG/WebP tajam. Gambar ini dikirim ke halaman pembayaran pelanggan.</p>
                    <p v-if="!manualQrisReady" class="adm-note">{{ manualQrisMethod?.order_readiness_reason || 'Aktifkan QRIS Manual setelah mengunggah gambar QR.' }}</p>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="adm-input" style="padding:4px;max-width:260px" @change="uploadQris">
                        <button type="button" class="adm-btn" :disabled="!manualQrisAsset" @click="toggleQris">
                            {{ manualQrisReady ? 'Nonaktifkan QRIS manual' : 'Aktifkan QRIS manual' }}
                        </button>
                    </div>
                    <p class="adm-note">Logo QRIS di daftar metode diatur lewat tombol Ubah pada baris QRIS, bukan di sini.</p>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Top up LFAMILIA Coin</h2>
                    </div>
                </div>
                <div class="adm-card-body">
                    <div class="adm-grid">
                        <label class="adm-field">
                            <span>Minimum top up</span>
                            <input v-model.number="wallet.minimum_topup_idr" type="number" min="1" max="100000000" class="adm-input" :disabled="!isSuperAdmin">
                        </label>
                        <label class="adm-switch-row" style="align-self:end">
                            <button type="button" class="adm-switch" role="switch" :aria-checked="wallet.topup_enabled" :disabled="!isSuperAdmin" @click="wallet.topup_enabled = !wallet.topup_enabled" />
                            Top up saldo aktif
                        </label>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:10px">
                        <button v-if="isSuperAdmin" type="button" class="adm-btn primary" @click="saveWallet">Simpan top up saldo</button>
                        <p v-else class="adm-note">Pengaturan top up hanya dapat diubah Super Admin.</p>
                    </div>
                </div>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Alamat notifikasi gateway</h2>
                        <p>Alamat yang dipasang di dashboard gateway agar status pembayaran masuk otomatis. Kredensial gateway diatur di menu Integrasi.</p>
                    </div>
                </div>
                <div class="adm-card-body" style="display:grid;gap:6px">
                    <button v-for="(url, key) in callbackUrls" :key="key" type="button" class="adm-copyrow" @click="copy(url)">
                        <strong>{{ key === 'midtrans' ? 'Midtrans' : 'DOKU' }}</strong>
                        <span class="mono">{{ url }}</span>
                        <span class="adm-note">{{ copied === url ? 'Tersalin' : 'Salin' }}</span>
                    </button>
                </div>
            </section>
        </template>

        <!-- ============ TAB 2: TRANSAKSI ============ -->
        <template v-else-if="tab === 'transactions'">
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Transaksi Pembayaran</h2>
                    </div>
                </div>
                <form class="adm-filters" @submit.prevent="applyFilter">
                    <label class="adm-field search">
                        <span>Cari</span>
                        <input v-model="filter.q" class="adm-input" maxlength="100" placeholder="Invoice, referensi, nama, email, atau WhatsApp">
                    </label>
                    <label class="adm-field narrow">
                        <span>Status</span>
                        <select v-model="filter.status" class="adm-select">
                            <option value="">Semua status</option>
                            <option value="CREATING">Menyiapkan</option><option value="SENDING">Mengirim permintaan</option>
                            <option value="PENDING">Menunggu</option><option value="UNKNOWN">Belum pasti</option>
                            <option value="PAID">Dibayar</option><option value="FAILED">Gagal</option>
                            <option value="EXPIRED">Kedaluwarsa</option><option value="CANCELLED">Dibatalkan</option>
                            <option value="REJECTED">Ditolak</option><option value="REFUNDED">Dikembalikan</option>
                        </select>
                    </label>
                    <label class="adm-field narrow">
                        <span>Sumber</span>
                        <select v-model="filter.source" class="adm-select">
                            <option value="">Semua</option><option value="order">Pesanan</option><option value="topup">Top up saldo</option>
                        </select>
                    </label>
                    <label class="adm-field narrow">
                        <span>Metode</span>
                        <select v-model.number="filter.channel" class="adm-select">
                            <option :value="0">Semua metode</option>
                            <option v-for="channel in channels" :key="channel.id" :value="channel.id">{{ channel.name }}</option>
                        </select>
                    </label>
                    <div class="adm-filter-actions">
                        <button type="submit" class="adm-btn primary">Terapkan</button>
                        <button type="button" class="adm-btn" @click="resetFilter">Hapus filter</button>
                    </div>
                </form>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>Referensi</th><th>Pelanggan</th><th>Metode</th><th>Total</th><th>Status</th><th>Waktu</th><th>Tindakan</th></tr></thead>
                        <tbody>
                            <tr v-for="row in transactions.data" :key="row.id">
                                <td>
                                    <strong>{{ row.order_number || row.merchant_reference || ('#' + row.id) }}</strong>
                                    <div class="adm-note">{{ row.source === 'ORDER' ? 'Pesanan' : 'Top up saldo' }}</div>
                                </td>
                                <td>
                                    {{ row.customer_name }}
                                    <div class="adm-note">{{ row.customer_email || row.customer_phone || '—' }}</div>
                                </td>
                                <td>{{ row.channel_name }}</td>
                                <td>{{ rupiah(row.amount_idr) }}</td>
                                <td><span class="adm-tag" :class="tone(row.status)">{{ statusLabel(row.status) }}</span></td>
                                <td class="adm-note">{{ wibDateTime(row.created_at) }}</td>
                                <td><button type="button" class="adm-btn sm" @click="selected = row">Detail</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!transactions.data?.length" class="adm-empty">Belum ada transaksi yang sesuai filter.</p>
                <div class="adm-pager">
                    <span>{{ transactions.from || 0 }}–{{ transactions.to || 0 }} dari {{ num(transactions.total) }} transaksi</span>
                    <span class="sp" />
                    <button type="button" class="adm-btn sm" :disabled="!transactions.prev_page_url" @click="router.get(transactions.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                    <button type="button" class="adm-btn sm" :disabled="!transactions.next_page_url" @click="router.get(transactions.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
                </div>
            </section>

            <section v-if="refundReviews.length" class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Top up perlu ditinjau</h2>
                        <p>Gateway melaporkan refund. Menutup tinjauan TIDAK mengubah saldo pelanggan; penyelesaian dana lewat penyesuaian saldo manual.</p>
                    </div>
                </div>
                <div class="adm-card-body" style="display:grid;gap:6px">
                    <div v-for="row in refundReviews" :key="row.id" class="adm-list-row">
                        <div>
                            <strong>{{ row.customer_name }}</strong>
                            <div class="adm-note">{{ rupiah(row.amount_idr) }} · {{ wibDateTime(row.created_at) }} · {{ row.customer_email }}</div>
                        </div>
                        <button type="button" class="adm-btn sm" @click="openReview(row)">Selesaikan tinjauan</button>
                    </div>
                </div>
            </section>

            <section v-if="manualPayments.length" class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>QRIS manual menunggu konfirmasi</h2>
                        <p>Konfirmasi hanya setelah dana benar-benar diterima.</p>
                    </div>
                </div>
                <div class="adm-card-body" style="display:grid;gap:6px">
                    <div v-for="payment in manualPayments" :key="payment.id" class="adm-list-row">
                        <div>
                            <strong>{{ payment.order_number }}</strong>
                            <div class="adm-note">{{ rupiah(payment.amount_idr) }} · {{ wibDateTime(payment.created_at) }}</div>
                        </div>
                        <button type="button" class="adm-btn sm primary" @click="confirmManual(payment.id)">Konfirmasi diterima</button>
                    </div>
                </div>
            </section>

            <AdminModal v-model:open="review.open" title="Selesaikan tinjauan top up" description="Tidak ada uang yang dipindahkan. Saldo pelanggan tetap seperti sekarang.">
                <p class="adm-note">Top up milik <strong>{{ review.customer }}</strong> akan ditandai selesai ditinjau, sehingga pembayaran saldo pelanggan terbuka kembali.</p>
                <label class="adm-field"><span>Alasan / hasil pemeriksaan (wajib)</span>
                    <textarea v-model="review.reason" class="adm-textarea" maxlength="500" placeholder="mis. dana sudah dibalik gateway, saldo disesuaikan manual" /></label>
                <template #footer>
                    <button type="button" class="adm-btn" @click="review.open = false">Batal</button>
                    <button type="button" class="adm-btn primary" :disabled="review.reason.trim().length < 3" @click="submitReview">Tandai selesai</button>
                </template>
            </AdminModal>

        </template>

        <!-- ============ TAB 3: HALAMAN PEMBAYARAN ============ -->
        <template v-else>
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Tampilan halaman pembayaran</h2>
                    </div>
                </div>
                <div class="adm-card-body">
                    <h3 class="adm-subhead">Warna &amp; gambar</h3>
                    <div class="adm-grid">
                        <label class="adm-field">
                            <span>Warna aksen</span>
                            <div style="display:flex;gap:6px;align-items:center">
                                <input v-model="page.accentColor" type="color" class="adm-color" aria-label="Warna aksen">
                                <input v-model="page.accentColor" class="adm-input mono" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                            </div>
                        </label>
                        <div class="adm-field">
                            <span>Banner halaman</span>
                            <div class="adm-media-preview wide">
                                <img v-if="page.headerImageUrl" :src="page.headerImageUrl" alt="Banner halaman pembayaran">
                                <span v-else class="adm-note">Belum ada banner.</span>
                            </div>
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="adm-input" style="padding:4px" @change="uploadHeader">
                            <span class="hint">Rekomendasi 1600 × 500 px (16:5). Maks 5 MB.</span>
                        </div>
                    </div>

                    <h3 class="adm-subhead">Teks kepala</h3>
                    <div class="adm-grid">
                        <label class="adm-field"><span>Teks kecil di atas judul</span><input v-model="page.eyebrow" class="adm-input" maxlength="60"></label>
                        <label class="adm-field"><span>Judul saat menunggu</span><input v-model="page.pendingTitle" class="adm-input" maxlength="100"></label>
                        <label class="adm-field"><span>Judul saat lunas</span><input v-model="page.paidTitle" class="adm-input" maxlength="100"></label>
                        <label class="adm-field"><span>Judul saat gagal</span><input v-model="page.failedTitle" class="adm-input" maxlength="100"></label>
                        <label class="adm-field full"><span>Subjudul</span><textarea v-model="page.subtitle" class="adm-textarea" rows="2" maxlength="240" /></label>
                    </div>

                    <h3 class="adm-subhead">Pemberitahuan invoice</h3>
                    <div class="adm-grid">
                        <label class="adm-field full"><span>Judul pemberitahuan</span><input v-model="page.invoiceNoticeTitle" class="adm-input" maxlength="120"></label>
                        <label class="adm-field full"><span>Isi pemberitahuan</span><textarea v-model="page.invoiceNoticeText" class="adm-textarea" rows="2" maxlength="500" /></label>
                    </div>

                    <h3 class="adm-subhead">Teks status</h3>
                    <div class="adm-grid">
                        <label class="adm-field full"><span>Status menunggu</span><textarea v-model="page.pendingStatusText" class="adm-textarea" rows="2" maxlength="300" /></label>
                        <label class="adm-field full"><span>Status lunas</span><textarea v-model="page.paidStatusText" class="adm-textarea" rows="2" maxlength="300" /></label>
                        <label class="adm-field full"><span>Status gagal</span><textarea v-model="page.failedStatusText" class="adm-textarea" rows="2" maxlength="300" /></label>
                    </div>

                    <h3 class="adm-subhead">Teks tombol</h3>
                    <div class="adm-grid">
                        <label class="adm-field"><span>Tombol bayar</span><input v-model="page.payButtonText" class="adm-input" maxlength="80"></label>
                        <label class="adm-field"><span>Tombol cek status</span><input v-model="page.checkStatusButtonText" class="adm-input" maxlength="80"></label>
                        <label class="adm-field"><span>Tombol cek invoice</span><input v-model="page.checkInvoiceButtonText" class="adm-input" maxlength="80"></label>
                    </div>

                    <h3 class="adm-subhead">Bantuan</h3>
                    <div class="adm-grid">
                        <label class="adm-field"><span>Teks bantuan</span><input v-model="page.supportText" class="adm-input" maxlength="120"></label>
                        <label class="adm-field"><span>Tautan bantuan</span><input v-model="page.supportUrl" class="adm-input mono" maxlength="500" placeholder="/contact"><span class="hint">Harus diawali / atau http(s)://.</span></label>
                    </div>

                    <h3 class="adm-subhead">Bagian yang tampil</h3>
                    <div class="adm-grid two">
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="page.showStoreBrand" @click="page.showStoreBrand = !page.showStoreBrand" /> Tampilkan merek toko</label>
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="page.showInvoiceNotice" @click="page.showInvoiceNotice = !page.showInvoiceNotice" /> Tampilkan pemberitahuan invoice</label>
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="page.showOrderSummary" @click="page.showOrderSummary = !page.showOrderSummary" /> Tampilkan ringkasan pesanan</label>
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="page.showStatusBox" @click="page.showStatusBox = !page.showStatusBox" /> Tampilkan kotak status</label>
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="page.showSupport" @click="page.showSupport = !page.showSupport" /> Tampilkan bantuan</label>
                    </div>
                </div>
                <div class="adm-card-body" style="border-top:1px solid var(--a-line);display:flex;justify-content:flex-end">
                    <button type="button" class="adm-btn primary" @click="savePage">Simpan tampilan</button>
                </div>
            </section>
        </template>

        <AdminModal :open="!!categoryEditor" :title="categoryEditor?.channels_count === undefined ? 'Tambah kategori pembayaran' : 'Ubah kategori pembayaran'"
            @update:open="value => { if (!value) categoryEditor = null; }">
            <div v-if="categoryEditor" class="adm-grid">
                <label class="adm-field"><span>Nama kategori *</span><input class="adm-input" v-model="categoryEditor.name" maxlength="100"><span v-if="categoryErrors.name" class="err">{{ categoryErrors.name }}</span></label>
                <label class="adm-field"><span>Kode tetap *</span><input class="adm-input mono" v-model="categoryEditor.code" maxlength="40" :disabled="categoryEditor.channels_count !== undefined" placeholder="contoh: transfer_bank"><span v-if="categoryErrors.code" class="err">{{ categoryErrors.code }}</span></label>
                <label class="adm-field full"><span>Deskripsi di checkout</span><input class="adm-input" v-model="categoryEditor.description" maxlength="160"></label>
                <label class="adm-field"><span>Urutan tampil</span><input type="number" min="0" max="9999" class="adm-input" v-model.number="categoryEditor.sort_order"></label>
                <label class="adm-switch-row"><button type="button" role="switch" class="adm-switch" :aria-checked="categoryEditor.is_active" @click="categoryEditor.is_active = !categoryEditor.is_active" /> Tampilkan kategori</label>
                <p class="adm-note full">Kategori nonaktif menyembunyikan seluruh metode di dalamnya dari checkout dan top up, tanpa mengubah konfigurasi gateway.</p>
            </div>
            <template #footer><button type="button" class="adm-btn" @click="categoryEditor = null">Batal</button><button type="button" class="adm-btn primary" :disabled="categorySaving" @click="saveCategory">Simpan kategori</button></template>
        </AdminModal>
        <AdminModal :open="!!categoryPendingDelete" title="Hapus kategori pembayaran" :description="'Kategori '+(categoryPendingDelete?.name || '')+' akan dihapus jika tidak digunakan.'"
            @update:open="value => { if (!value) categoryPendingDelete = null; }">
            <p>Metode pembayaran dan transaksi tidak akan dihapus.</p>
            <template #footer><button type="button" class="adm-btn" @click="categoryPendingDelete = null">Batal</button><button type="button" class="adm-btn danger" @click="deleteCategory">Hapus kategori</button></template>
        </AdminModal>

        <!-- pop-up metode -->
        <AdminModal :open="!!editor" :title="editor?.id ? 'Ubah metode pembayaran' : 'Tambah metode pembayaran'"
            description="Pilih metode bawaan yang sudah punya route gateway. Metode baru dengan kode bebas belum otomatis terhubung ke Midtrans atau DOKU."
            wide @update:open="value => { if (!value) closeEditor(); }">
            <div v-if="editor" class="adm-grid">
                <label class="adm-field">
                    <span>Nama tampilan <b class="req">*</b></span>
                    <input v-model="editor.name" class="adm-input" maxlength="100">
                    <span v-if="errors.name" class="err">{{ errors.name }}</span>
                </label>
                <label class="adm-field">
                    <span>Kode metode <b class="req">*</b></span>
                    <input v-model="editor.code" class="adm-input mono" maxlength="60" placeholder="contoh: qris" :disabled="!!editor.id">
                    <span class="hint">Kode tidak dapat diubah setelah metode dibuat.</span>
                    <span v-if="errors.code" class="err">{{ errors.code }}</span>
                </label>
                <label class="adm-field">
                    <span>Kategori pembayaran</span>
                    <select v-model="editor.method" class="adm-select" @change="onMethodChange">
                        <option value="QRIS">QRIS</option><option value="VIRTUAL_ACCOUNT">Virtual Account</option>
                        <option value="EWALLET">E-Wallet</option><option value="RETAIL">Gerai Retail</option>
                        <option value="WALLET">Saldo</option><option value="OTHER">Lainnya</option>
                    </select>
                </label>
                <label class="adm-field">
                    <span>Kategori tampilan</span>
                    <select v-model="editor.category_code" class="adm-select" :disabled="editor.method === 'WALLET'">
                        <option v-for="category in categories.filter(item => item.code !== 'wallet' || editor.method === 'WALLET')" :key="category.code" :value="category.code">{{ category.name }}</option>
                    </select>
                    <span v-if="errors.category_code" class="err">{{ errors.category_code }}</span>
                </label>
                <label class="adm-field">
                    <span>Deskripsi pelanggan</span>
                    <input v-model="editor.description" class="adm-input" maxlength="160">
                </label>

                <div v-if="!editor.id || !routesForEditor.length" class="adm-block full">
                    <strong>Koneksi gateway</strong>
                    <p class="adm-note">Pilih penyedia dan kode metode API yang benar, bukan hanya nama tampilan. Pilihan yang disediakan mengikuti katalog metode terintegrasi. Dukungan aktual harus diaktifkan pada akun merchant.</p>
                    <div class="adm-grid" style="margin-top:8px">
                        <label class="adm-field">
                            <span>Gateway penyedia</span>
                            <select v-model="editor.gateway_code" class="adm-select" @change="editor.provider_method = ''">
                                <option value="MIDTRANS">Midtrans Snap</option>
                                <option v-if="editor.method === 'VIRTUAL_ACCOUNT'" value="DOKU">DOKU Checkout</option>
                            </select>
                        </label>
                        <label class="adm-field">
                            <span>Kode metode pada penyedia</span>
                            <input v-model="editor.provider_method" class="adm-input mono"
                                list="payment-provider-methods" maxlength="80"
                                :placeholder="providerMethodChoices[0]?.code || 'Kode API dari penyedia'">
                            <datalist id="payment-provider-methods">
                                <option v-for="item in providerMethodChoices" :key="item.gateway+'-'+item.code"
                                    :value="item.code">{{ item.label }}</option>
                            </datalist>
                            <span v-if="errors.provider_method" class="err">{{ errors.provider_method }}</span>
                            <span v-if="errors.gateway_code" class="err">{{ errors.gateway_code }}</span>
                            <span class="hint">Kode baru di luar daftar hanya bisa dipakai jika sudah benar-benar didukung gateway dan merchant.</span>
                        </label>
                    </div>
                </div>

                <div class="adm-block full">
                    <strong>Biaya untuk pilihan pembayaran ini</strong>
                    <div class="adm-grid" style="margin-top:8px">
                        <label class="adm-field">
                            <span>Biaya persen (%)</span>
                            <input v-model.number="editor.fee_percent" type="number" min="0" max="99.99" step="0.01" class="adm-input" :disabled="freeMethod(editor.method, editor.code)">
                        </label>
                        <label class="adm-field">
                            <span>Biaya tetap (Rp)</span>
                            <input v-model.number="editor.fee_flat_idr" type="number" min="0" max="100000000" class="adm-input" :disabled="freeMethod(editor.method, editor.code)">
                        </label>
                    </div>
                    <p class="adm-note" style="margin-top:6px">LFAMILIA Coin tidak dikenai fee. Persentase dan nominal bisa dipakai masing-masing atau bersamaan. Untuk kanal lain, atur biaya individual di sini atau tarif khusus provider.</p>
                </div>

                <div v-if="editor.id" class="adm-block full">
                    <strong>Provider untuk pilihan pembayaran ini</strong>
                    <p class="adm-note" style="margin:4px 0 0">Tarif kosong mengikuti biaya metode. Pilih gateway untuk pesanan dan top up lewat kolom Gateway terpilih. Jangan aktifkan dua route sekaligus.</p>
                    <div v-for="route in routesForEditor" :key="'r' + route.id" class="adm-route">
                        <div style="display:flex;justify-content:space-between;gap:6px;align-items:center">
                            <strong>{{ route.gateway_name }}</strong>
                            <span class="adm-tag" :class="route.is_active ? 'ok' : 'bad'">{{ route.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        </div>
                        <p class="adm-note">Ditagih sekarang: <strong>{{ routeFeeText(route) }}</strong></p>
                        <div class="adm-grid">
                            <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="route.custom_fee" :disabled="routeIsFree(route)" @click="route.custom_fee = !route.custom_fee" /> Tarif khusus</label>
                            <label class="adm-field"><span>Biaya tetap (Rp)</span><input v-model.number="route.fee_flat_idr" type="number" min="0" class="adm-input" :disabled="routeIsFree(route) || !route.custom_fee"></label>
                            <label class="adm-field"><span>Biaya persen (%)</span><input v-model.number="route.fee_percent" type="number" min="0" max="99.99" step="0.01" class="adm-input" :disabled="routeIsFree(route) || !route.custom_fee"></label>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:6px;align-items:center;margin-top:6px">
                            <span class="adm-note">{{ route.is_active ? 'Route aktif' : 'Route tidak aktif' }} · pilih gateway melalui kolom utama</span>
                            <button type="button" class="adm-btn sm" @click="saveRoute(route)">Simpan gateway</button>
                        </div>
                    </div>
                    <p v-if="!routesForEditor.length" class="adm-note" style="margin-top:8px">Hubungkan ke gateway dengan mengisi kode metode penyedia di bagian "Koneksi gateway" sebelum menyimpan.</p>
                </div>

                <div class="adm-block">
                    <strong>Digunakan untuk</strong>
                    <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="editor.supports_order" @click="editor.supports_order = !editor.supports_order" /> Pembayaran pesanan</label>
                    <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="editor.supports_wallet_topup" @click="editor.supports_wallet_topup = !editor.supports_wallet_topup" /> Top up saldo</label>
                </div>
                <div class="adm-block">
                    <strong>Status</strong>
                    <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="editor.is_active" @click="editor.is_active = !editor.is_active" /> Metode aktif</label>
                    <p class="adm-note">Aktif hanya berarti diizinkan oleh Admin. Agar muncul di checkout harus ada satu gateway dipilih, gateway aktif, dan kredensial tersedia.</p>
                </div>

                <div class="adm-field full">
                    <span>Logo metode</span>
                    <div style="display:flex;gap:8px;align-items:center">
                        <span class="adm-logo big">
                            <img v-if="logoPreview" :src="logoPreview" alt="">
                            <span v-else class="adm-note">—</span>
                        </span>
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="adm-input" style="padding:4px" @change="chooseLogo">
                    </div>
                    <span class="hint">Rekomendasi 256 × 256 px (1:1), PNG/WebP transparan. Maks 2 MB.</span>
                </div>

                <div class="full">
                    <button type="button" class="adm-btn link" @click="advanced = !advanced">Pengaturan lanjutan</button>
                    <div v-if="advanced" class="adm-grid" style="margin-top:8px">
                        <label class="adm-field">
                            <span>Skor urutan internal</span>
                            <input v-model.number="editor.sort_order" type="number" min="0" max="9999" class="adm-input">
                            <span class="hint">Ditampilkan sebagai Posisi 1, 2, 3. Skor 10, 20, 30 boleh dibiarkan.</span>
                        </label>
                    </div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="closeEditor">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="saving === 'channel'" @click="saveChannel">{{ saving === 'channel' ? 'Menyimpan…' : 'Simpan metode' }}</button>
            </template>
        </AdminModal>

        <!-- pop-up hapus metode -->
        <AdminModal :open="!!pendingChannel" title="Hapus metode pembayaran"
            :description="pendingChannel ? 'Metode ' + pendingChannel.name + ' akan dihapus. Pesanan lama tetap menyimpan riwayat pembayarannya.' : ''"
            @update:open="value => { if (!value) pendingChannel = null; }">
            <p class="adm-note">Tindakan ini tidak dapat dibatalkan.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="pendingChannel = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="removeChannel">Hapus metode</button>
            </template>
        </AdminModal>

        <!-- pop-up detail transaksi -->
        <AdminModal :open="!!selected" title="Detail pembayaran"
            :description="selected?.merchant_reference || selected?.order_number || ''"
            @update:open="value => { if (!value) selected = null; }">
            <div v-if="selected" class="adm-grid two">
                <div class="adm-block"><span class="adm-note">Pelanggan</span><strong>{{ selected.customer_name }}</strong></div>
                <div class="adm-block"><span class="adm-note">Total</span><strong>{{ rupiah(selected.amount_idr) }}</strong></div>
                <div class="adm-block"><span class="adm-note">Metode</span><strong>{{ selected.channel_name }}</strong></div>
                <div class="adm-block"><span class="adm-note">Gateway internal</span><strong>{{ selected.gateway_name }}</strong></div>
                <div class="adm-block"><span class="adm-note">Status</span><strong>{{ statusLabel(selected.status) }}</strong></div>
                <div class="adm-block"><span class="adm-note">Callback diterima</span><strong>{{ selected.callback_count }}</strong></div>
                <div class="adm-block full"><span class="adm-note">Referensi eksternal</span><strong class="mono" style="overflow-wrap:anywhere">{{ selected.external_reference || '—' }}</strong></div>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="selected = null">Tutup</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
