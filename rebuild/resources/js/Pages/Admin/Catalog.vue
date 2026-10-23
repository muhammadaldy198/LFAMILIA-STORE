<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { rupiah } from '../../lib/adminFormat';

const props = defineProps({
    products: { type: Array, default: () => [] },
    productPage: { type: Object, default: () => ({ data: [], total: 0, current_page: 1, last_page: 1 }) },
    productFilters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    nicknameGameCodes: { type: Array, default: () => [] },
    checkoutPreviews: { type: Object, default: () => ({}) },
});

// Saringan dikerjakan server supaya hasil tidak bohong saat produk banyak.
const tab = ref(props.productFilters.tab || 'auto');
const q = ref(props.productFilters.q || '');
const categoryId = ref(props.productFilters.category || '');
const status = ref(props.productFilters.status || '');
const sort = ref(props.productFilters.sort || 'order');
const perPage = ref(props.productFilters.per_page || 25);
const page = ref(props.productPage.current_page || 1);
const busy = ref(false);

const mode = computed(() => (tab.value === 'auto' ? 'AUTO_PROVIDER' : 'MANUAL'));
const visible = computed(() => props.products);
const total = computed(() => Number(props.productPage.total || 0));
const pageCount = computed(() => Math.max(1, Number(props.productPage.last_page || 1)));
const categoryName = id => props.categories.find(row => Number(row.id) === Number(id))?.name || 'Tanpa kategori';
const activePackages = row => row.packages.filter(pkg => pkg.is_active).length;

const load = (extra = {}) => {
    busy.value = true;
    const query = { tab: tab.value, q: q.value, category: categoryId.value, status: status.value, sort: sort.value, per_page: perPage.value, page: page.value, ...extra };
    Object.keys(query).forEach(key => { if (query[key] === '' || query[key] === null) delete query[key]; });
    router.get('/admin/catalog', query, {
        preserveState: true, preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
const applyFilters = () => { page.value = 1; load(); };
const resetFilters = () => { q.value = ''; categoryId.value = ''; status.value = ''; sort.value = 'order'; perPage.value = 25; page.value = 1; load(); };
const goPage = value => { page.value = value; load(); };
const hasFilters = computed(() => Boolean(q.value || categoryId.value || status.value || sort.value !== 'order' || Number(perPage.value) !== 25));
const switchTab = value => { tab.value = value; page.value = 1; load(); };

/* ---------- tambah produk ---------- */
const addOpen = ref(false);
const addAdvanced = ref(false);
const addBusy = ref(false);
const addErrors = ref({});
const add = reactive({ category_id: '', name: '', slug: '', publisher: '', description: '', sort_order: 0 });

const openAdd = () => {
    Object.assign(add, { category_id: '', name: '', slug: '', publisher: '', description: '', sort_order: 0 });
    addErrors.value = {};
    addAdvanced.value = false;
    addOpen.value = true;
};

const submitAdd = () => {
    addBusy.value = true;
    router.post('/admin/catalog/products', {
        category_id: add.category_id,
        name: add.name,
        slug: add.slug || null,
        publisher: add.publisher || null,
        description: add.description || null,
        fulfillment_mode: mode.value,
        sort_order: Number(add.sort_order || 0),
        margin_percent: 0,
    }, {
        preserveScroll: true,
        onError: bag => { addErrors.value = bag; },
        onFinish: () => { addBusy.value = false; },
        onSuccess: () => { addOpen.value = false; },
    });
};

/* ---------- editor produk ---------- */
const editorOpen = ref(false);
const product = ref(null);
const editTab = ref('info');
const saveBusy = ref('');
const editErrors = ref({});

const openEditor = (row) => {
    product.value = JSON.parse(JSON.stringify(row));
    editTab.value = 'info';
    editErrors.value = {};
    editorOpen.value = true;
};

const info = reactive({
    name: '', slug: '', publisher: '', category_id: '', fulfillment_mode: 'AUTO_PROVIDER',
    sort_order: 0, description: '', is_active: false, nickname_check_enabled: false,
    nickname_game_code: '', nickname_user_field_key: '', nickname_server_field_key: '',
});

const loadInfo = () => {
    if (!product.value) return;
    Object.assign(info, {
        name: product.value.name,
        slug: product.value.slug,
        publisher: product.value.publisher || '',
        category_id: product.value.category_id,
        fulfillment_mode: product.value.fulfillment_mode,
        sort_order: product.value.sort_order ?? 0,
        description: product.value.description || '',
        is_active: Boolean(product.value.is_active),
        nickname_check_enabled: Boolean(product.value.nickname_check_enabled),
        nickname_game_code: product.value.nickname_game_code || '',
        nickname_user_field_key: product.value.nickname_user_field_key || '',
        nickname_server_field_key: product.value.nickname_server_field_key || '',
    });
};

const saveInfo = () => {
    saveBusy.value = 'info';
    router.put(`/admin/catalog/products/${product.value.id}`, {
        ...info,
        category_id: Number(info.category_id),
        sort_order: Number(info.sort_order || 0),
        margin_percent: Number(product.value.margin_percent ?? 0),
        nickname_check_enabled: info.nickname_game_code ? info.nickname_check_enabled : false,
    }, {
        preserveScroll: true,
        onError: bag => { editErrors.value = bag; },
        onFinish: () => { saveBusy.value = ''; },
        onSuccess: () => { editorOpen.value = false; },
    });
};

const gameCodeOptions = computed(() => [{ value: '', label: 'Tidak ada' }, ...props.nicknameGameCodes.map(row => ({ value: row.code, label: row.name }))]);
const fieldKeyOptions = computed(() => (product.value?.fields || []).map(row => ({ value: row.field_key, label: `${row.label} (${row.field_key})` })));

/* ---------- nominal ---------- */
const nominalOpen = ref(false);
const nominalEditing = ref(null);
const nominalErrors = ref({});
const nominalImage = ref(null);
const nominal = reactive({
    name: '', group_name: '', sort_order: 0, is_active: true,
    price_mode: 'FIXED', price_value: '', cost_idr: '',
});

const openNominal = (row = null) => {
    nominalEditing.value = row;
    Object.assign(nominal, {
        name: row?.name || '',
        group_name: row?.group_name || '',
        sort_order: row?.sort_order ?? (product.value?.packages.length ?? 0),
        is_active: row ? Boolean(row.is_active) : true,
        price_mode: row?.pricing_mode === 'SELL_PRICE' ? 'FIXED' : row ? 'MARKUP' : 'FIXED',
        price_value: row?.pricing_mode === 'SELL_PRICE' ? row.sell_price_idr : (row?.sell_markup_percent ?? ''),
        cost_idr: row?.mappings?.[0]?.cost_idr ?? '',
    });
    nominalImage.value = null;
    nominalErrors.value = {};
    nominalOpen.value = true;
};

const slugCode = (text) => String(text || 'nominal').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'nominal';

const saveNominal = () => {
    const payload = {
        name: nominal.name,
        code: nominalEditing.value?.code || `${slugCode(nominal.name)}-${Date.now().toString(36).slice(-4)}`,
        group_name: nominal.group_name || null,
        sort_order: Number(nominal.sort_order || 0),
        is_active: Boolean(nominal.is_active),
        note: null,
        nominal_value: null,
        pricing_mode: nominal.price_mode === 'FIXED' ? 'SELL_PRICE' : 'PERCENT',
        sell_price_idr: nominal.price_mode === 'FIXED' ? Number(nominal.price_value || 0) : null,
        sell_markup_percent: nominal.price_mode === 'MARKUP' ? Number(nominal.price_value || 0) : null,
        margin_percent: 0,
    };
    saveBusy.value = 'nominal';
    const finish = (bag) => { nominalErrors.value = bag; saveBusy.value = ''; };
    if (nominalEditing.value) {
        router.put(`/admin/catalog/packages/${nominalEditing.value.id}`, payload, {
            preserveScroll: true, onError: finish, onSuccess: () => { nominalOpen.value = false; uploadNominalImage(); },
        });
    } else {
        router.post(`/admin/catalog/products/${product.value.id}/packages`, {
            ...payload,
            cost_idr: product.value.fulfillment_mode === 'MANUAL' ? Number(nominal.cost_idr || 0) : 0,
        }, {
            preserveScroll: true, onError: finish, onSuccess: () => { nominalOpen.value = false; },
        });
    }
};

const uploadNominalImage = () => {
    const file = nominalImage.value?.files?.[0];
    if (!file || !nominalEditing.value) return;
    router.post(`/admin/catalog/media/package/${nominalEditing.value.id}`, { image: file, collection: 'image' }, {
        preserveScroll: true, forceFormData: true,
    });
};

const removeTarget = ref(null);
const removeKind = ref('');
const askRemove = (row, kind) => { removeTarget.value = row; removeKind.value = kind; };
const confirmRemove = () => {
    const row = removeTarget.value;
    if (!row) return;
    const url = removeKind.value === 'notice' ? `/admin/catalog/notices/${row.id}` : `/admin/catalog/packages/${row.id}`;
    router.delete(url, { preserveScroll: true, onFinish: () => { removeTarget.value = null; } });
};

const dragIndex = ref(null);
const onDragStart = (index) => { dragIndex.value = index; };
const onDrop = (index) => {
    if (dragIndex.value === null || dragIndex.value === index) return;
    const list = [...product.value.packages];
    const [moved] = list.splice(dragIndex.value, 1);
    list.splice(index, 0, moved);
    product.value.packages = list;
    dragIndex.value = null;
    router.put(`/admin/catalog/products/${product.value.id}/packages/reorder`, { ids: list.map(row => row.id) }, { preserveScroll: true });
};

const move = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= product.value.packages.length) return;
    const list = [...product.value.packages];
    [list[index], list[target]] = [list[target], list[index]];
    product.value.packages = list;
    router.put(`/admin/catalog/products/${product.value.id}/packages/reorder`, { ids: list.map(row => row.id) }, { preserveScroll: true });
};

const costOf = (row) => {
    const mapping = (row.mappings || [])[0];
    return mapping ? Number(mapping.store_cost_idr ?? mapping.cost_idr ?? 0) : null;
};
const sellOf = (row) => props.checkoutPreviews?.[row.id] ?? null;
const marginOf = (row) => {
    const cost = costOf(row);
    const sell = sellOf(row);
    return cost && sell ? sell - cost : null;
};

/* ---------- tampilan produk ---------- */
const bannerInput = ref(null);
const imageInput = ref(null);
const uploadProduct = (collection, event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post(`/admin/catalog/media/product/${product.value.id}`, { image: file, collection }, {
        preserveScroll: true, forceFormData: true,
        onFinish: () => { event.target.value = ''; },
    });
};

const noticeOpen = ref(false);
const noticeEditing = ref(null);
const notice = reactive({ title: '', body: '', sort_order: 0, is_active: true });
const openNotice = (row = null) => {
    noticeEditing.value = row;
    Object.assign(notice, {
        title: row?.title || '', body: row?.body || '',
        sort_order: row?.sort_order ?? (product.value?.notices.length ?? 0),
        is_active: row ? Boolean(row.is_active) : true,
    });
    noticeOpen.value = true;
};
const saveNotice = () => {
    const payload = { ...notice, sort_order: Number(notice.sort_order || 0) };
    if (noticeEditing.value) router.put(`/admin/catalog/notices/${noticeEditing.value.id}`, payload, { preserveScroll: true, onSuccess: () => { noticeOpen.value = false; } });
    else router.post(`/admin/catalog/products/${product.value.id}/notices`, payload, { preserveScroll: true, onSuccess: () => { noticeOpen.value = false; } });
};


/* ---------- data pelanggan ---------- */
const fieldRows = ref([]);
const fieldBusy = ref(false);
const fieldErrors = ref({});

const loadFields = () => {
    fieldRows.value = (product.value?.fields || []).map(row => ({
        field_key: row.field_key, label: row.label, placeholder: row.placeholder || '',
        type: row.type || 'text', is_required: Boolean(row.is_required),
    }));
};

const FIELD_KEYS = [
    { value: 'user_id', label: 'user_id — ID pengguna' },
    { value: 'zone_id', label: 'zone_id — ID zona / server' },
    { value: 'destination', label: 'destination — tujuan umum' },
    { value: 'email', label: 'email — alamat email' },
    { value: 'phone', label: 'phone — nomor telepon' },
];

const addField = () => {
    const used = new Set(fieldRows.value.map(row => row.field_key));
    const next = FIELD_KEYS.find(option => !used.has(option.value));
    if (!next) return;
    fieldRows.value.push({ field_key: next.value, label: '', placeholder: '', type: 'text', is_required: true });
};

const moveField = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= fieldRows.value.length) return;
    const list = [...fieldRows.value];
    [list[index], list[target]] = [list[target], list[index]];
    fieldRows.value = list;
};

const template = computed(() => {
    const keys = fieldRows.value.map(row => `{{${row.field_key}}}`);
    if (!keys.length) return '';
    if (keys.length === 1) return keys[0];
    return keys[0] + keys.slice(1).map(key => `(${key})`).join('');
});

// Contoh nilai per kode kolom, dipakai bila admin belum mengisi contoh isian.
const SAMPLES = { user_id: '12345678', zone_id: '1234', destination: '12345678', email: 'nama@email.com', phone: '08123456789' };

// Pratinjau memakai contoh angka lebih dulu supaya bentuk hasilnya kelihatan,
// bukan kalimat perintah yang kebetulan diisi di kolom "Contoh isian".
const preview = computed(() => {
    let text = template.value;
    fieldRows.value.forEach(row => {
        const filled = String(row.placeholder || '').trim();
        const sample = SAMPLES[row.field_key];
        text = text.replaceAll(`{{${row.field_key}}}`, sample || filled || row.field_key);
    });
    return text;
});

const fieldSaved = ref(false);
const saveFields = () => {
    fieldBusy.value = true;
    fieldErrors.value = {};
    fieldSaved.value = false;
    router.put(`/admin/catalog/products/${product.value.id}/fields`, {
        fields: fieldRows.value.map((row, index) => ({ ...row, is_required: Boolean(row.is_required), sort_order: index })),
        customer_no_template: template.value || null,
    }, {
        preserveScroll: true,
        onError: bag => { fieldErrors.value = bag; },
        onSuccess: () => { fieldSaved.value = true; setTimeout(() => { fieldSaved.value = false; }, 2500); },
        onFinish: () => { fieldBusy.value = false; },
    });
};

// Setelah setiap perubahan, salinan produk di pop-up disegarkan dari data server.
watch(() => props.products, () => {
    if (!product.value) return;
    const fresh = props.products.find(row => row.id === product.value.id);
    if (fresh) product.value = JSON.parse(JSON.stringify(fresh));
});

const openEditorFull = (row) => {
    openEditor(row);
    loadInfo();
    loadFields();
};
</script>

<template>
    <Head title="Produk" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Produk</h1>
                <p>Kode SKU penyedia tidak pernah ditampilkan ke pelanggan.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/catalog/categories" class="adm-btn">Kategori</Link>
                <a href="/" target="_blank" rel="noopener" class="adm-btn">Lihat toko</a>
                <button type="button" class="adm-btn primary" @click="openAdd">Tambah produk</button>
            </div>
        </header>

        <div class="adm-tabs" role="tablist">
            <button type="button" role="tab" :aria-selected="tab === 'auto'" @click="switchTab('auto')">Produk Otomatis</button>
            <button type="button" role="tab" :aria-selected="tab === 'manual'" @click="switchTab('manual')">Produk Manual</button>
        </div>

        <section class="adm-card" style="margin-top:12px">
            <form class="adm-filters" @submit.prevent="applyFilters">
                <label class="adm-field search">
                    <span>Cari produk</span>
                    <input v-model="q" class="adm-input" placeholder="Nama produk atau publisher" maxlength="150">
                </label>
                <label class="adm-field narrow">
                    <span>Kategori</span>
                    <select v-model="categoryId" class="adm-select" @change="applyFilters">
                        <option value="">Semua kategori</option>
                        <option v-for="row in categories" :key="row.id" :value="row.id">{{ row.name }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="status" class="adm-select" @change="applyFilters">
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Urutkan</span>
                    <select v-model="sort" class="adm-select" @change="applyFilters">
                        <option value="order">Urutan toko</option>
                        <option value="name">Nama A–Z</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Per halaman</span>
                    <select v-model="perPage" class="adm-select" @change="applyFilters">
                        <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} produk</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary" :disabled="busy">Terapkan</button> <button type="button" class="adm-btn" :disabled="busy || !hasFilters" @click="resetFilters">Hapus filter</button>
                </div>

            </form>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr><th>Produk</th><th>Kategori</th><th>Jenis</th><th>Nominal aktif</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visible" :key="row.id">
                            <td>
                                <strong>{{ row.name }}</strong>
                                <p class="dim">{{ row.publisher || 'Tanpa publisher' }} · {{ row.slug }}</p>
                            </td>
                            <td>{{ categoryName(row.category_id) }}</td>
                            <td>{{ row.fulfillment_mode === 'MANUAL' ? 'Manual' : 'Otomatis' }}</td>
                            <td>{{ activePackages(row) }} aktif dari {{ row.packages.length }}</td>
                            <td>{{ row.sort_order }}</td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><button type="button" class="adm-btn sm" @click="openEditorFull(row)">Ubah</button></td>
                        </tr>
                        <tr v-if="!visible.length"><td colspan="7" class="adm-empty">Belum ada produk pada tab ini.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="adm-pager">
                <span>{{ total }} produk · Halaman {{ page }} dari {{ pageCount }}</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="busy || page <= 1" @click="goPage(page - 1)">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="busy || page >= pageCount" @click="goPage(page + 1)">Berikutnya</button>
            </div>
        </section>

        <AdminModal v-model:open="addOpen" title="Tambah produk" :description="tab === 'auto' ? 'Produk otomatis mengambil nominal dari impor Digiflazz.' : 'Produk manual diisi nominal sendiri.'">
            <form id="add-product" class="adm-grid two" @submit.prevent="submitAdd">
                <label class="adm-field">
                    <span>Kategori <b class="req">*</b></span>
                    <select v-model="add.category_id" class="adm-select" required>
                        <option value="">Pilih kategori</option>
                        <option v-for="row in categories" :key="row.id" :value="row.id">{{ row.name }}</option>
                    </select>
                    <span v-if="addErrors.category_id" class="err">{{ addErrors.category_id }}</span>
                </label>
                <label class="adm-field">
                    <span>Nama produk <b class="req">*</b></span>
                    <input v-model="add.name" class="adm-input" maxlength="255" required>
                    <span v-if="addErrors.name" class="err">{{ addErrors.name }}</span>
                </label>
                <label class="adm-field"><span>Merek game / publisher</span><input v-model="add.publisher" class="adm-input" maxlength="255"></label>
                <label class="adm-field"><span>Posisi di toko</span><input v-model="add.sort_order" type="number" min="0" class="adm-input"></label>
                <label class="adm-field full"><span>Deskripsi</span><textarea v-model="add.description" class="adm-textarea" maxlength="5000" /></label>
                <div class="full"><button type="button" class="adm-btn link" @click="addAdvanced = !addAdvanced">Ubah tautan halaman produk (lanjutan)</button></div>
                <label v-if="addAdvanced" class="adm-field full">
                    <span>Alamat halaman produk</span>
                    <input v-model="add.slug" class="adm-input" placeholder="contoh: mobile-legends" maxlength="255">
                    <span class="hint">Kosongkan untuk dibuat otomatis dari nama.</span>
                    <span v-if="addErrors.slug" class="err">{{ addErrors.slug }}</span>
                </label>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="addOpen = false">Batal</button>
                <button type="submit" form="add-product" class="adm-btn primary" :disabled="addBusy">{{ tab === 'auto' ? 'Tambah produk otomatis' : 'Tambah produk manual' }}</button>
            </template>
        </AdminModal>

        <AdminModal v-model:open="editorOpen" wide :title="product?.name || 'Produk'" :description="product ? `${product.slug} · ${product.fulfillment_mode === 'MANUAL' ? 'Manual' : 'Otomatis'}` : ''">
            <div class="adm-tabs" role="tablist">
                <button type="button" role="tab" :aria-selected="editTab === 'info'" @click="editTab = 'info'">Informasi</button>
                <button type="button" role="tab" :aria-selected="editTab === 'price'" @click="editTab = 'price'">Nominal &amp; Harga</button>
                <button type="button" role="tab" :aria-selected="editTab === 'look'" @click="editTab = 'look'">Tampilan Produk</button>
                <button type="button" role="tab" :aria-selected="editTab === 'fields'" @click="editTab = 'fields'">Data Pelanggan</button>
            </div>

            <div v-if="editTab === 'info'" style="padding-top:12px">
                <form id="product-info" class="adm-grid two" @submit.prevent="saveInfo">
                    <label class="adm-field"><span>Nama produk <b class="req">*</b></span><input v-model="info.name" class="adm-input" maxlength="255" required><span v-if="editErrors.name" class="err">{{ editErrors.name }}</span></label>
                    <label class="adm-field"><span>Publisher / merek</span><input v-model="info.publisher" class="adm-input" maxlength="255"></label>
                    <label class="adm-field">
                        <span>Kategori <b class="req">*</b></span>
                        <select v-model="info.category_id" class="adm-select">
                            <option v-for="row in categories" :key="row.id" :value="row.id">{{ row.name }}</option>
                        </select>
                    </label>
                    <label class="adm-field">
                        <span>Jenis penanganan</span>
                        <select v-model="info.fulfillment_mode" class="adm-select">
                            <option value="AUTO_PROVIDER">Otomatis</option>
                            <option value="MANUAL">Manual</option>
                        </select>
                        <span class="hint">Bisa diubah kapan saja tanpa mengosongkan nominal.</span>
                    </label>
                    <label class="adm-field"><span>Posisi di toko</span><input v-model="info.sort_order" type="number" min="0" class="adm-input"></label>
                    <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="info.is_active" @click="info.is_active = !info.is_active" />Aktif di toko</label>
                    <label class="adm-field full"><span>Deskripsi</span><textarea v-model="info.description" class="adm-textarea" maxlength="5000" /></label>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="info.nickname_check_enabled" :disabled="!info.nickname_game_code" @click="info.nickname_check_enabled = !info.nickname_check_enabled" />Aktifkan cek nickname</label>
                    <label class="adm-field">
                        <span>Kode game</span>
                        <select v-model="info.nickname_game_code" class="adm-select">
                            <option v-for="option in gameCodeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <span class="hint">Tanpa kode game, cek nickname otomatis mati.</span>
                    </label>
                    <label class="adm-field">
                        <span>Kolom ID pengguna</span>
                        <select v-model="info.nickname_user_field_key" class="adm-select">
                            <option value="">Tidak dipilih</option>
                            <option v-for="option in fieldKeyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <label class="adm-field">
                        <span>Kolom server / zona</span>
                        <select v-model="info.nickname_server_field_key" class="adm-select">
                            <option value="">Tidak dipakai</option>
                            <option v-for="option in fieldKeyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <label class="adm-field full">
                        <span>Alamat produk</span>
                        <input v-model="info.slug" class="adm-input" maxlength="255">
                        <span class="hint">Ubah hanya bila perlu; alamat lama akan berubah.</span>
                        <span v-if="editErrors.slug" class="err">{{ editErrors.slug }}</span>
                    </label>
                </form>
            </div>

            <div v-if="editTab === 'price'" style="padding-top:12px">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px">
                    <p class="adm-note">Geser baris untuk mengubah urutan tampil. Harga jual diambil dari aturan harga nominal.</p>
                    <button type="button" class="adm-btn primary sm" @click="openNominal()">Tambah nominal</button>
                </div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>Nominal</th><th class="num">Sumber / Modal</th><th class="num">Harga jual</th><th class="num">Margin</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, index) in product?.packages || []" :key="row.id" draggable="true" @dragstart="onDragStart(index)" @dragover.prevent @drop="onDrop(index)">
                                <td>
                                    <strong>{{ row.name }}</strong>
                                    <p class="dim">{{ row.group_name || 'Umum' }}</p>
                                </td>
                                <td class="num">{{ costOf(row) == null ? '—' : rupiah(costOf(row)) }}</td>
                                <td class="num">{{ sellOf(row) == null ? '—' : rupiah(sellOf(row)) }}</td>
                                <td class="num">{{ marginOf(row) == null ? '—' : rupiah(marginOf(row)) }}</td>
                                <td>
                                    <div style="display:flex;gap:3px">
                                        <button type="button" class="adm-btn sm" aria-label="Naikkan" @click="move(index, -1)">↑</button>
                                        <button type="button" class="adm-btn sm" aria-label="Turunkan" @click="move(index, 1)">↓</button>
                                    </div>
                                </td>
                                <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td>
                                    <div style="display:flex;gap:4px">
                                        <button type="button" class="adm-btn sm" @click="openNominal(row)">Ubah</button>
                                        <button type="button" class="adm-btn sm danger" @click="askRemove(row, 'package')">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!product?.packages?.length"><td colspan="7" class="adm-empty">Belum ada nominal. Tambahkan nominal pertama.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="editTab === 'look'" style="padding-top:12px">
                <div class="adm-grid two">
                    <div class="adm-field">
                        <span>Gambar produk</span>
                        <p class="hint">Rekomendasi 512×512 piksel, PNG atau WebP transparan.</p>
                        <img v-if="product?.image_url" :src="product.image_url" :alt="product.name" class="adm-thumb">
                        <p v-else class="adm-note">Belum ada gambar.</p>
                        <input ref="imageInput" type="file" accept="image/png,image/webp,image/jpeg" class="adm-input" style="padding:5px" @change="uploadProduct('image', $event)">
                    </div>
                    <div class="adm-field">
                        <span>Banner halaman produk</span>
                        <p class="hint">Rekomendasi 1440×360 piksel, JPG atau WebP.</p>
                        <img v-if="product?.banner_url" :src="product.banner_url" :alt="`Banner ${product.name}`" style="width:100%;max-height:120px;object-fit:cover;border:1px solid var(--a-line);border-radius:8px">
                        <p v-else class="adm-note">Belum ada banner.</p>
                        <input ref="bannerInput" type="file" accept="image/png,image/webp,image/jpeg" class="adm-input" style="padding:5px" @change="uploadProduct('banner', $event)">
                    </div>
                </div>

                <div class="adm-divider" />

                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px">
                    <div>
                        <strong style="font-size:12.5px">Pemberitahuan produk</strong>
                        <p class="adm-note">Muncul sebagai pop-up sekali di halaman produk toko.</p>
                    </div>
                    <button type="button" class="adm-btn sm" @click="openNotice()">Tambah pemberitahuan</button>
                </div>
                <div v-if="product?.notices?.length" class="adm-list">
                    <div v-for="row in product.notices" :key="row.id" class="adm-list-row">
                        <strong>{{ row.title }}</strong>
                        <span>{{ row.body }}</span>
                        <span>Urutan {{ row.sort_order }} · {{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        <div style="display:flex;gap:4px;margin-top:4px">
                            <button type="button" class="adm-btn sm" @click="openNotice(row)">Ubah</button>
                            <button type="button" class="adm-btn sm danger" @click="askRemove(row, 'notice')">Hapus</button>
                        </div>
                    </div>
                </div>
                <p v-else class="adm-empty">Belum ada pemberitahuan.</p>
            </div>

            <div v-if="editTab === 'fields'" style="padding-top:12px">
                <p class="adm-note">Urutan baris menentukan urutan data yang dikirim ke penyedia.</p>
                <div class="adm-scroll" style="margin-top:8px">
                    <table class="adm-table">
                        <thead><tr><th>Urutan</th><th>Nama kolom</th><th>Kode kolom</th><th>Contoh isian</th><th>Jenis isian</th><th>Wajib</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, index) in fieldRows" :key="index">
                                <td>
                                    <div style="display:flex;gap:3px">
                                        <button type="button" class="adm-btn sm" aria-label="Naikkan" @click="moveField(index, -1)">↑</button>
                                        <button type="button" class="adm-btn sm" aria-label="Turunkan" @click="moveField(index, 1)">↓</button>
                                    </div>
                                </td>
                                <td><input v-model="row.label" class="adm-input" placeholder="Contoh: ID pengguna" maxlength="255"></td>
                                <td style="min-width:190px">
                                    <select v-model="row.field_key" class="adm-select">
                                        <option v-for="option in FIELD_KEYS" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                </td>
                                <td><input v-model="row.placeholder" class="adm-input" placeholder="12345678" maxlength="255"></td>
                                <td>
                                    <select v-model="row.type" class="adm-select">
                                        <option value="text">Teks / ID</option>
                                        <option value="tel">Nomor telepon</option>
                                        <option value="email">Email</option>
                                    </select>
                                </td>
                                <td><input v-model="row.is_required" type="checkbox"></td>
                                <td><button type="button" class="adm-btn sm danger" @click="fieldRows.splice(index, 1)">Hapus</button></td>
                            </tr>
                            <tr v-if="!fieldRows.length"><td colspan="7" class="adm-empty">Belum ada kolom data pelanggan.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
                    <button type="button" class="adm-btn sm" @click="addField">Tambah kolom</button>
                </div>
                <div class="adm-note adm-block">
                    Hasil kirim ke penyedia:
                    <strong class="mono" style="font-size:12.5px;color:var(--a-ink)">{{ preview || '—' }}</strong>
                    <p style="margin:4px 0 0">Contoh memakai isian contoh; data asli diambil dari pelanggan saat checkout.</p>
                </div>
                <p v-if="fieldErrors.customer_no_template" class="err">{{ fieldErrors.customer_no_template }}</p>
                <p v-if="fieldErrors.fields" class="err">{{ fieldErrors.fields }}</p>
            </div>

            <template #footer>
                <button type="button" class="adm-btn" @click="editorOpen = false">Tutup</button>
                <button v-if="editTab === 'info'" type="submit" form="product-info" class="adm-btn primary" :disabled="saveBusy === 'info'">Simpan informasi</button>
                <span v-if="editTab === 'fields' && fieldSaved" class="adm-ok" style="margin-right:auto">Data pelanggan tersimpan</span>
                <button v-else-if="editTab === 'fields'" type="button" class="adm-btn primary" :disabled="fieldBusy" @click="saveFields">Simpan data pelanggan</button>
            </template>
        </AdminModal>

        <AdminModal v-model:open="nominalOpen" :title="nominalEditing ? 'Ubah nominal' : 'Tambah nominal'" :description="product?.name || ''">
            <form id="nominal-form" class="adm-grid two" @submit.prevent="saveNominal">
                <label class="adm-field"><span>Nama nominal <b class="req">*</b></span><input v-model="nominal.name" class="adm-input" maxlength="255" required><span v-if="nominalErrors.name" class="err">{{ nominalErrors.name }}</span></label>
                <label class="adm-field"><span>Grup / tabel</span><input v-model="nominal.group_name" class="adm-input" placeholder="Umum" maxlength="120"><span class="hint">Kosong berarti Umum.</span></label>
                <label class="adm-field"><span>Posisi</span><input v-model="nominal.sort_order" type="number" min="0" class="adm-input"></label>
                <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="nominal.is_active" @click="nominal.is_active = !nominal.is_active" />Nominal aktif</label>
                <label class="adm-field">
                    <span>Harga</span>
                    <select v-model="nominal.price_mode" class="adm-select">
                        <option value="FIXED">Harga tetap</option>
                        <option value="MARKUP">Markup dari modal</option>
                    </select>
                </label>
                <label class="adm-field">
                    <span>{{ nominal.price_mode === 'FIXED' ? 'Harga jual (Rp)' : 'Markup (%)' }}</span>
                    <input v-model="nominal.price_value" type="number" min="0" class="adm-input">
                    <span v-if="nominalErrors.sell_price_idr" class="err">{{ nominalErrors.sell_price_idr }}</span>
                </label>
                <label v-if="product?.fulfillment_mode === 'MANUAL'" class="adm-field">
                    <span>Modal (Rp)</span>
                    <input v-model="nominal.cost_idr" type="number" min="0" class="adm-input">
                    <span class="hint">Dipakai untuk menghitung margin nominal manual.</span>
                    <span v-if="nominalErrors.cost_idr" class="err">{{ nominalErrors.cost_idr }}</span>
                </label>
                <div v-if="nominalEditing" class="adm-field full">
                    <span>Gambar nominal</span>
                    <p class="hint">Opsional. Bila tidak ada gambar, kartu nominal tampil tanpa gambar.</p>
                    <img v-if="nominalEditing.image_url" :src="nominalEditing.image_url" alt="" class="adm-thumb sm">
                    <input type="file" accept="image/png,image/webp,image/jpeg" class="adm-input" style="padding:5px" @change="event => { nominalImage = event.target; }">
                </div>
                <p v-else class="adm-note full">Simpan nominal dulu untuk menambahkan gambar.</p>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="nominalOpen = false">Batal</button>
                <button type="submit" form="nominal-form" class="adm-btn primary" :disabled="saveBusy === 'nominal'">Simpan nominal</button>
            </template>
        </AdminModal>

        <AdminModal v-model:open="noticeOpen" :title="noticeEditing ? 'Ubah pemberitahuan' : 'Tambah pemberitahuan'">
            <form id="notice-form" class="adm-grid" @submit.prevent="saveNotice">
                <label class="adm-field full"><span>Judul <b class="req">*</b></span><input v-model="notice.title" class="adm-input" maxlength="180" required></label>
                <label class="adm-field full"><span>Isi <b class="req">*</b></span><textarea v-model="notice.body" class="adm-textarea" maxlength="5000" required /></label>
                <label class="adm-field"><span>Urutan</span><input v-model="notice.sort_order" type="number" min="0" class="adm-input"></label>
                <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="notice.is_active" @click="notice.is_active = !notice.is_active" />Aktif</label>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="noticeOpen = false">Batal</button>
                <button type="submit" form="notice-form" class="adm-btn primary">Simpan</button>
            </template>
        </AdminModal>
        <AdminModal :open="!!removeTarget" :title="removeKind === 'notice' ? 'Hapus pemberitahuan' : 'Hapus nominal'" :description="removeTarget?.name || removeTarget?.title || ''" @update:open="value => { if (!value) removeTarget = null; }">
            <p class="adm-note">{{ removeKind === 'notice' ? 'Pemberitahuan ini akan hilang dari halaman produk toko.' : 'Nominal yang memiliki riwayat pesanan tidak dapat dihapus; nonaktifkan bila hanya ingin menghentikan penjualan.' }}</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="removeTarget = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="confirmRemove">Hapus</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
