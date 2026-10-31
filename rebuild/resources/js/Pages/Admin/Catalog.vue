<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { rupiah } from '../../lib/adminFormat';
import { nominalMode, nominalPayload, nominalPriceValue } from '../../lib/catalogNominal';
import { orderedPackageGroups, movePackageGroup } from '../../lib/catalogGroups';
import { canSyncMapping, mappingPayload, mappingStatusLabel, sortMappings } from '../../lib/catalogSources';

const props = defineProps({
    products: { type: Array, default: () => [] },
    productPage: { type: Object, default: () => ({ total: 0, current_page: 1, last_page: 1, per_page: 25 }) },
    autoSync: { type: Boolean, default: true },
    syncIntervalMinutes: { type: Number, default: 15 },
    productFilters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    nicknameGameCodes: { type: Array, default: () => [] },
    checkoutPreviews: { type: Object, default: () => ({}) },
    digiflazzItems: { type: Array, default: () => [] },
    autoProductOptions: { type: Array, default: () => [] },
    pricingSettings: { type: Object, default: () => ({}) },
});

// Saringan dikerjakan server supaya hasil tidak bohong saat produk banyak.
const tab = ref(props.productFilters.tab || 'auto');
const q = ref(props.productFilters.q || '');
const categoryId = ref(props.productFilters.category || '');
const status = ref(props.productFilters.status || '');
const sort = ref(props.productFilters.sort || 'name');
const perPage = ref(props.productFilters.per_page || 25);
const page = ref(props.productPage.current_page || 1);
const busy = ref(false);

const mode = computed(() => (tab.value === 'auto' ? 'AUTO_PROVIDER' : 'MANUAL'));
const visible = computed(() => props.products);
const total = computed(() => Number(props.productPage.total || 0));
const pageCount = computed(() => Math.max(1, Number(props.productPage.last_page || 1)));
const categoryName = id => props.categories.find(row => Number(row.id) === Number(id))?.name || 'Tanpa kategori';
const activePackages = row => row.packages.filter(pkg => pkg.is_active).length;
// Prioritas beranda dipindah dari halaman Promo ke daftar Produk.
const togglePopular = row => router.put(`/admin/vouchers/popular/${row.id}`, { popular: !row.popular }, { preserveScroll: true });

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
const resetFilters = () => { q.value = ''; categoryId.value = ''; status.value = ''; sort.value = 'name'; perPage.value = 25; page.value = 1; load(); };
const goPage = value => { page.value = value; load(); };
const hasFilters = computed(() => Boolean(q.value || categoryId.value || status.value || sort.value !== 'name' || Number(perPage.value) !== 25));
const switchTab = value => { tab.value = value; page.value = 1; load(); };

/* ---------- tambah produk ---------- */
const addOpen = ref(false);
const addAdvanced = ref(false);
const addBusy = ref(false);
const addErrors = ref({});
const MANUAL_DEFAULTS = { manual_instructions: '', manual_open_time: '', manual_close_time: '', manual_timezone: 'Asia/Jakarta' };
const add = reactive({ category_id: '', name: '', slug: '', publisher: '', description: '', sort_order: 0, ...MANUAL_DEFAULTS });

const openAdd = () => {
    Object.assign(add, { category_id: '', name: '', slug: '', publisher: '', description: '', sort_order: 0, ...MANUAL_DEFAULTS });
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
        // Petunjuk & jam layanan dipakai pemberitahuan fulfillment dan toko.
        ...(mode.value === 'MANUAL' ? {
            manual_instructions: add.manual_instructions || null,
            manual_open_time: add.manual_open_time || null,
            manual_close_time: add.manual_close_time || null,
            manual_timezone: add.manual_timezone || null,
        } : {}),
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
    groupOrderError.value = '';
    editorOpen.value = true;
};

/* ---------- sumber nominal ---------- */
const sourceRows = computed(() => (product.value?.packages || []).map(row => ({
    ...row,
    mappings: sortMappings(row.mappings),
})));

const saveMapping = (mapping, changes) => {
    saveBusy.value = 'source-'+mapping.id;
    router.put(`/admin/catalog/mappings/${mapping.id}`, mappingPayload(mapping, changes), {
        preserveScroll: true,
        onError: bag => { editErrors.value = bag; },
        onFinish: () => { saveBusy.value = ''; },
    });
};
const toggleMapping = mapping => saveMapping(mapping, { is_active: !mapping.is_active });
// Seller Digiflazz is read-only; only manual sources have editable cost.
const canEditCost = mapping => mapping.provider_code === 'MANUAL';
const movePriority = (mapping, direction) => {
    saveBusy.value = 'source-'+mapping.id;
    router.put(`/admin/catalog/mappings/${mapping.id}`, { direction }, {
        preserveScroll: true,
        onError: bag => { editErrors.value = bag; },
        onFinish: () => { saveBusy.value = ''; },
    });
};
const syncMapping = mapping => {
    saveBusy.value = 'sync-'+mapping.id;
    router.post(`/admin/catalog/mappings/${mapping.id}/sync`, {}, {
        preserveScroll: true, onFinish: () => { saveBusy.value = ''; },
    });
};

const info = reactive({
    name: '', slug: '', publisher: '', category_id: '', fulfillment_mode: 'AUTO_PROVIDER',
    sort_order: 0, description: '', is_active: false, nickname_check_enabled: false,
    nickname_game_code: '', nickname_user_field_key: '', nickname_server_field_key: '',
    ...MANUAL_DEFAULTS,
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
        manual_instructions: product.value.manual_instructions || '',
        manual_open_time: product.value.manual_open_time || '',
        manual_close_time: product.value.manual_close_time || '',
        manual_timezone: product.value.manual_timezone || 'Asia/Jakarta',
    });
};

const saveInfo = () => {
    saveBusy.value = 'info';
    router.put(`/admin/catalog/products/${product.value.id}`, {
        ...info,
        category_id: Number(info.category_id),
        sort_order: Number(info.sort_order || 0),
        nickname_check_enabled: info.nickname_game_code ? info.nickname_check_enabled : false,
        manual_instructions: info.manual_instructions || null,
        manual_open_time: info.manual_open_time || null,
        manual_close_time: info.manual_close_time || null,
        manual_timezone: info.manual_timezone || null,
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
const isDigiflazzNominal = computed(() => Boolean(nominalEditing.value?.is_digiflazz_pricing));
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
        price_mode: isDigiflazzNominal.value
            ? ((row?.digiflazz_markup_mode ?? row?.pricing_mode) === 'FIXED' ? 'FIXED' : 'MARKUP')
            : (row?.legacy_voucher_sell_price_idr != null ? 'FIXED' : nominalMode(row)),
        price_value: isDigiflazzNominal.value
            ? ((row?.digiflazz_markup_mode ?? row?.pricing_mode) === 'FIXED'
                ? (row?.digiflazz_markup_mode != null ? row.digiflazz_markup_fixed_idr : row.margin_fixed_idr) ?? 0
                : (row?.digiflazz_markup_mode != null ? row.digiflazz_markup_percent : row?.margin_percent) ?? 12)
            : (row?.legacy_voucher_sell_price_idr ?? nominalPriceValue(row)),
        // Modal toko yang ditampilkan adalah store_cost_idr, bukan cost_idr
        // (harga akuntansi lama yang bisa berbeda).
        cost_idr: row?.mappings?.[0]?.store_cost_idr ?? row?.mappings?.[0]?.cost_idr ?? '',
    });
    nominalImage.value = null;
    nominalErrors.value = {};
    nominalOpen.value = true;
};

const slugCode = (text) => String(text || 'nominal').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'nominal';

const saveNominal = () => {
    // When the voucher is the effective source, display/edit its retained
    // fixed selling price rather than the same nominal's Digiflazz markup.
    const savedRow = !isDigiflazzNominal.value && nominalEditing.value?.legacy_voucher_sell_price_idr != null
        ? { ...nominalEditing.value, pricing_mode: 'SELL_PRICE',
            sell_price_idr: nominalEditing.value.legacy_voucher_sell_price_idr }
        : nominalEditing.value;
    const payload = nominalPayload(savedRow, nominal, nominalEditing.value?.code || `${slugCode(nominal.name)}-${Date.now().toString(36).slice(-4)}`);
    // Bind pricing to the source shown when the editor opened. If voucher
    // stock runs out before Save, the server rejects stale price edits
    // instead of silently changing Digiflazz's independent markup.
    const mappings = nominalEditing.value?.mappings || [];
    if (mappings.some(row => row.provider_code === 'DIGIFLAZZ')
        && mappings.some(row => row.provider_code === 'VOUCHER_STOCK')) {
        payload.price_source = isDigiflazzNominal.value ? 'DIGIFLAZZ' : 'VOUCHER_STOCK';
    }
    // Digiflazz has a markup per nominal: a percentage or added rupiah.
    // Do not turn the FIXED increment into a fixed selling price.
    // A name/status-only edit must leave this nominal's markup untouched.
    if (isDigiflazzNominal.value) {
        for (const key of ['pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr', 'sell_markup_percent']) delete payload[key];
        const row = nominalEditing.value;
        const oldMode = (row.digiflazz_markup_mode ?? row.pricing_mode) === 'FIXED' ? 'FIXED' : 'MARKUP';
        const oldValue = oldMode === 'FIXED'
            ? (row.digiflazz_markup_mode != null ? row.digiflazz_markup_fixed_idr : row.margin_fixed_idr)
            : (row.digiflazz_markup_mode != null ? row.digiflazz_markup_percent : row.margin_percent) ?? 12;
        if (nominal.price_mode !== oldMode || Number(nominal.price_value) !== Number(oldValue)) {
            payload.pricing_mode = nominal.price_mode === 'FIXED' ? 'FIXED' : 'PERCENT';
            payload.margin_percent = nominal.price_mode === 'FIXED' ? null : Number(nominal.price_value);
            payload.margin_fixed_idr = nominal.price_mode === 'FIXED' ? Number(nominal.price_value) : null;
            payload.sell_price_idr = null;
            payload.sell_markup_percent = null;
        }
    }
    saveBusy.value = 'nominal';
    const handleErrors = bag => { nominalErrors.value = bag; };
    const finish = () => { saveBusy.value = ''; };
    if (nominalEditing.value) {
        // Modal nominal manual tersimpan di pemetaan provider, bukan di paket.
        if (product.value.fulfillment_mode === 'MANUAL') payload.store_cost_idr = Number(nominal.cost_idr || 0);
        router.put(`/admin/catalog/packages/${nominalEditing.value.id}`, payload, {
            preserveScroll: true, onError: handleErrors, onFinish: finish,
            onSuccess: () => { nominalOpen.value = false; uploadNominalImage(); },
        });
    } else {
        router.post(`/admin/catalog/products/${product.value.id}/packages`, {
            ...payload,
            cost_idr: product.value.fulfillment_mode === 'MANUAL' ? Number(nominal.cost_idr || 0) : 0,
        }, {
            preserveScroll: true, onError: handleErrors, onFinish: finish,
            onSuccess: () => { nominalOpen.value = false; },
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
const removeBusy = ref(false);
const removeErrors = ref({});
const askRemove = (row, kind) => {
    if (removeBusy.value) return;
    removeErrors.value = {};
    removeTarget.value = row;
    removeKind.value = kind;
};
const confirmRemove = () => {
    const row = removeTarget.value;
    if (!row || removeBusy.value) return;
    removeBusy.value = true;
    const kind = removeKind.value;
    const url = kind === 'notice' ? `/admin/catalog/notices/${row.id}`
        : kind === 'product' ? `/admin/catalog/products/${row.id}`
        : `/admin/catalog/packages/${row.id}`;
    router.delete(url, {
        preserveScroll: true,
        onError: bag => { removeErrors.value = bag; },
        onSuccess: () => {
            removeTarget.value = null;
            removeErrors.value = {};
            if (kind === 'package') {
                // Keep the editor in sync even if the parent product list is paginated.
                if (nominalEditing.value?.id === row.id) nominalOpen.value = false;
                if (product.value?.packages) {
                    product.value.packages = product.value.packages.filter(item => item.id !== row.id);
                }
            } else if (kind === 'product' && product.value?.id === row.id) {
                editorOpen.value = false;
                product.value = null;
            }
        },
        onFinish: () => { removeBusy.value = false; },
    });
};

// The saved group priority is independent of nominal position and does not enable tabs.
const packageGroupNames = computed(() => orderedPackageGroups(
    product.value?.packages || [], product.value?.package_tabs || [],
).map(group => group.name));
const groupOrderError = ref('');
const moveGroup = (index, direction) => {
    if (!product.value || saveBusy.value) return;
    const before = packageGroupNames.value;
    const next = movePackageGroup(before, index, direction);
    if (JSON.stringify(next) === JSON.stringify(before)) return;
    const previous = [...(product.value.package_tabs || [])];
    const productId = product.value.id;
    product.value.package_tabs = next;
    groupOrderError.value = '';
    saveBusy.value = 'group-order';
    router.put('/admin/catalog/products/' + productId + '/package-groups/reorder', { groups: next }, {
        preserveScroll: true,
        onError: bag => {
            if (product.value?.id === productId) product.value.package_tabs = previous;
            groupOrderError.value = bag.groups || 'Gagal menyimpan urutan grup.';
        },
        onFinish: () => { saveBusy.value = ''; },
    });
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

const nominalNumber = row => {
    if (row.nominal_value !== null && row.nominal_value !== undefined && row.nominal_value !== '') return Number(row.nominal_value);
    const match = String(row.name || '').match(/\d+(?:[.,]\d{3})*/u);
    return match ? Number(match[0].replace(/[.,]/g, '')) : Number.MAX_SAFE_INTEGER;
};
const sortNominalsNaturally = () => {
    if (!product.value?.packages?.length) return;
    const collator = new Intl.Collator('id-ID', { numeric: true, sensitivity: 'base' });
    const list = [...product.value.packages].sort((a, b) => nominalNumber(a) - nominalNumber(b)
        || collator.compare(a.name || '', b.name || '')
        || collator.compare(a.group_name || '', b.group_name || '')
        || Number(a.id) - Number(b.id));
    product.value.packages = list;
    router.put('/admin/catalog/products/' + product.value.id + '/packages/reorder',
        { ids: list.map(row => row.id) }, { preserveScroll: true });
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
const destinationFormat = ref('');
const fieldBusy = ref(false);
const fieldErrors = ref({});

const loadFields = () => {
    fieldRows.value = (product.value?.fields || []).map(row => ({
        field_key: row.field_key, label: row.label, placeholder: row.placeholder || '',
        type: row.type || 'text', is_required: Boolean(row.is_required),
    }));
    destinationFormat.value = product.value?.customer_no_template || '';
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

const isMobileLegends = slug => ['mobile-legends', 'mobile-legends-bang-bang', 'mobile-legend']
    .includes(String(slug || '').toLowerCase());
const resolveDestination = (slug, fields, saved = '') => {
    const keys = fields.map(row => row.field_key);
    const stored = String(saved || '').trim();
    if (isMobileLegends(slug) && keys.includes('user_id') && keys.includes('zone_id')
        && (!stored || stored === '{{user_id}}({{zone_id}})')) {
        return '{{user_id}}{{zone_id}}';
    }
    if (keys.length === 1) {
        const referenced = [...stored.matchAll(/\{\{([^}]+)\}\}/g)].map(match => match[1]);
        if (!stored || referenced.some(key => !keys.includes(key))) return `{{${keys[0]}}}`;
    }
    return stored;
};
const templateNeedsCorrection = computed(() => [...String(destinationFormat.value || '')
    .matchAll(/\{\{([^}]+)\}\}/g)]
    .some(match => !fieldRows.value.some(row => row.field_key === match[1])));
const template = computed(() => resolveDestination(product.value?.slug, fieldRows.value, destinationFormat.value));
const requiredTemplateFieldsMissing = computed(() => fieldRows.value.some(row =>
    row.is_required && !template.value.includes(`{{${row.field_key}}}`)
));
const showTemplateEditor = computed(() => fieldRows.value.length > 1
    && (!isMobileLegends(product.value?.slug) || templateNeedsCorrection.value
        || requiredTemplateFieldsMissing.value
        || !fieldRows.value.some(row => row.field_key === 'user_id')
        || !fieldRows.value.some(row => row.field_key === 'zone_id')));

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
/* ---------- pengaturan markup produk non-Digiflazz ---------- */
const priceOpen = ref(false);
const priceBusy = ref('');
const priceErrors = ref({});
const manualVoucherMarkup = ref(Number(props.pricingSettings?.sell_markup_percent ?? 10));
const openPricing = () => {
    priceErrors.value = {};
    manualVoucherMarkup.value = Number(props.pricingSettings?.sell_markup_percent ?? 10);
    priceOpen.value = true;
};
const savePricing = () => {
    priceBusy.value = 'pricing';
    router.put('/admin/catalog/pricing', {
        // Retain the legacy field only because this endpoint still requires it.
        // It is NOT applied to Digiflazz selling prices or edited in this UI.
        cost_uplift_percent: Number(props.pricingSettings?.cost_uplift_percent ?? 2),
        sell_markup_percent: Number(manualVoucherMarkup.value),
    }, {
        preserveScroll: true,
        onError: bag => { priceErrors.value = bag; },
        onFinish: () => { priceBusy.value = ''; },
        onSuccess: () => { priceOpen.value = false; },
    });
};
/* ---------- salin nominal & stok kode ---------- */
// Produk stok kode = produk yang nominalnya mengambil kode dari stok sendiri.
const stockProduct = computed(() => (product.value?.packages || [])
    .some(row => (row.mappings || []).some(mapping => mapping.provider_code === 'VOUCHER_STOCK')));
const canStock = row => product.value?.fulfillment_mode === 'AUTO_PROVIDER'
    && (stockProduct.value
        ? (row.mappings || []).some(mapping => mapping.provider_code === 'VOUCHER_STOCK')
        : !(row.mappings || []).some(mapping => mapping.provider_code === 'DIGIFLAZZ'));
const duplicate = row => {
    saveBusy.value = 'duplicate';
    router.post(`/admin/catalog/packages/${row.id}/duplicate`, {}, {
        preserveScroll: true,
        onError: bag => { nominalErrors.value = bag; },
        onFinish: () => { saveBusy.value = ''; },
    });
};
const stockOpen = ref(false);
const stockBusy = ref(false);
const stockErrors = ref({});
const stockRow = ref(null);
const stock = reactive({ stock_key: '', cost_idr: 0, priority: 0, is_active: true, codes_text: '' });
const openStock = row => {
    stockRow.value = row;
    const mapping = (row.mappings || []).find(item => item.provider_code === 'VOUCHER_STOCK');
    stock.stock_key = mapping?.stock_key || '';
    stock.cost_idr = costOf(row) ?? 0;
    stock.priority = mapping?.priority ?? 0;
    stock.is_active = mapping ? Boolean(mapping.is_active) : true;
    stock.codes_text = '';
    stockErrors.value = {};
    stockOpen.value = true;
};
const saveStock = () => {
    if (!stockRow.value) return;
    stockBusy.value = true;
    stockErrors.value = {};
    router.post(`/admin/catalog/packages/${stockRow.value.id}/voucher-stock`, {
        stock_key: stock.stock_key,
        cost_idr: Number(stock.cost_idr || 0),
        priority: Number(stock.priority || 0),
        is_active: Boolean(stock.is_active),
        codes_text: stock.codes_text || null,
    }, {
        preserveScroll: true,
        onError: bag => { stockErrors.value = bag; },
        onSuccess: () => { stockOpen.value = false; },
        onFinish: () => { stockBusy.value = false; },
    });
};

/* ---------- impor nominal Digiflazz ---------- */
const importOpen = ref(false);
const importBusy = ref(false);
const importErrors = ref({});
const importInfo = ref('');
const importProductId = ref('');
const importCategory = ref('');
const importBrand = ref('');
const importType = ref('');
const importSearch = ref('');
const importPicked = ref([]);
const importPublish = ref(true);
const importDestination = ref('');
const importPage = ref(1);
const IMPORT_PER_PAGE = 50;
const importCollator = new Intl.Collator('id-ID', { numeric: true, sensitivity: 'base' });

const autoProducts = computed(() => props.autoProductOptions);
const importProduct = computed(() => props.autoProductOptions.find(row => Number(row.id) === Number(importProductId.value))
    || props.products.find(row => Number(row.id) === Number(importProductId.value)) || null);
const importFields = computed(() => importProduct.value?.fields?.length
    ? importProduct.value.fields
    : isMobileLegends(importProduct.value?.slug)
        ? [{ field_key: 'user_id', is_required: true }, { field_key: 'zone_id', is_required: true }]
        : [{ field_key: 'destination', is_required: true }]);
const importTemplate = computed(() => resolveDestination(
    importProduct.value?.slug, importFields.value, importDestination.value
));
const importTemplateReady = computed(() => {
    const source = importTemplate.value;
    const used = [...source.matchAll(/\{\{([a-z][a-z0-9_]*)\}\}/g)].map(m => m[1]);
    const remaining = source.replace(/\{\{([a-z][a-z0-9_]*)\}\}/g, '');
    return used.length > 0 && !/[{}]/.test(remaining)
        && used.every(key => importFields.value.some(field => field.field_key === key))
        && importFields.value.filter(field => field.is_required).every(field => used.includes(field.field_key));
});
watch(importProductId, () => {
    importDestination.value = importProduct.value?.customer_no_template || '';
    importPublish.value = importTemplateReady.value;
    importErrors.value = {};
});
const importAvailable = computed(() => props.digiflazzItems.filter(row => !row.mapped && row.available));
const importCategories = computed(() => [...new Set(importAvailable.value.map(row => row.category).filter(Boolean))].sort(importCollator.compare));
const importBrands = computed(() => [...new Set(importAvailable.value
    .filter(row => !importCategory.value || row.category === importCategory.value)
    .map(row => row.brand).filter(Boolean))].sort(importCollator.compare));
const importTypes = computed(() => [...new Set(importAvailable.value
    .filter(row => (!importCategory.value || row.category === importCategory.value)
        && (!importBrand.value || row.brand === importBrand.value))
    .map(row => row.type).filter(Boolean))].sort(importCollator.compare));
const importMatches = computed(() => {
    const term = importSearch.value.trim().toLocaleLowerCase('id-ID');
    return importAvailable.value.filter(row => {
        if (importCategory.value && row.category !== importCategory.value) return false;
        if (importBrand.value && row.brand !== importBrand.value) return false;
        if (importType.value && row.type !== importType.value) return false;
        return !term || [row.product_name, row.buyer_sku_code, row.brand, row.type]
            .some(value => String(value || '').toLocaleLowerCase('id-ID').includes(term));
    }).sort((a, b) => importCollator.compare(a.brand || '', b.brand || '')
        || importCollator.compare(a.type || '', b.type || '')
        || (a.nominal_value == null ? 1 : b.nominal_value == null ? -1 : Number(a.nominal_value) - Number(b.nominal_value))
        || importCollator.compare(a.product_name || '', b.product_name || '')
        || importCollator.compare(a.buyer_sku_code || '', b.buyer_sku_code || ''));
});
const importPageCount = computed(() => Math.max(1, Math.ceil(importMatches.value.length / IMPORT_PER_PAGE)));
const importRows = computed(() => importMatches.value.slice((importPage.value - 1) * IMPORT_PER_PAGE, importPage.value * IMPORT_PER_PAGE));
const importGroupKey = row => row.nominal_value == null
    ? 'sku:' + row.buyer_sku_code
    : 'value:' + String(row.type || '').toLocaleLowerCase('id-ID') + ':' + Number(row.nominal_value);
const importGroupCounts = computed(() => {
    const counts = new Map();
    for (const row of importMatches.value) {
        const key = importGroupKey(row);
        counts.set(key, (counts.get(key) || 0) + 1);
    }
    return counts;
});
const importAmbiguousCount = computed(() => importMatches.value.filter(row => importGroupCounts.value.get(importGroupKey(row)) > 1).length);
const canBulkImport = computed(() => Boolean(importBrand.value || importSearch.value.trim().length >= 2));
watch([importCategory, importBrand, importType, importSearch], () => { importPage.value = 1; importInfo.value = ''; });
const bulkSelectImport = () => {
    if (!canBulkImport.value) {
        importInfo.value = 'Pilih game/brand atau cari nama game dahulu agar SKU game lain tidak tercampur.';
        return;
    }
    // Jangan pernah memilih seller/SKU otomatis untuk nominal yang memiliki kandidat ganda.
    const existing = new Set(importPicked.value);
    const selectedKeys = new Set(importAvailable.value.filter(row => existing.has(row.id)).map(importGroupKey));
    let added = 0;
    for (const row of importMatches.value) {
        const key = importGroupKey(row);
        if (importGroupCounts.value.get(key) !== 1 || selectedKeys.has(key) || existing.size >= 2000) continue;
        existing.add(row.id);
        selectedKeys.add(key);
        added++;
    }
    importPicked.value = [...existing];
    importInfo.value = added + ' SKU ditambahkan. '
        + (importAmbiguousCount.value ? importAmbiguousCount.value + ' SKU dengan nominal ganda dilewati; pilih salah satu SKU sendiri.' : '');
};
const bulkClearImport = () => {
    const matched = new Set(importMatches.value.map(row => row.id));
    importPicked.value = importPicked.value.filter(id => !matched.has(id));
    importInfo.value = 'Pilihan pada hasil filter dibersihkan.';
};
const sellPriceOf = (seller, item) => {
    const price = Number(seller || 0);
    // The importer reuses an unmapped nominal when game/type and numeric
    // nominal match. Preview its saved markup; only newly imported nominals default to 10%.
    const existing = importProduct.value?.nominals?.find(nominal =>
        item?.nominal_value != null
        && Number(nominal.nominal_value) === Number(item.nominal_value)
        && String(nominal.group_name || '') === String(item.type || '')
    );
    if (existing?.pricing_mode === 'FIXED') return price + Number(existing.margin_fixed_idr || 0);
    const percentage = existing?.pricing_mode === 'PERCENT'
        ? Number(existing.margin_percent ?? 12)
        : 10;
    return price + Math.ceil(price * percentage / 100);
};

const openImport = () => {
    importErrors.value = {};
    importPicked.value = [];
    importProductId.value = '';
    importSearch.value = '';
    importBrand.value = '';
    importCategory.value = '';
    importType.value = '';
    importPage.value = 1;
    importInfo.value = '';
    importPublish.value = true;
    importDestination.value = '';
    importOpen.value = true;
};
const toggleImportRow = id => {
    if (importPicked.value.includes(id)) {
        importPicked.value = importPicked.value.filter(x => x !== id);
    } else {
        const chosen = importAvailable.value.find(row => row.id === id);
        if (!chosen) return;
        const key = importGroupKey(chosen);
        const conflicts = new Set(importAvailable.value.filter(row => importGroupKey(row) === key).map(row => row.id));
        importPicked.value = [...importPicked.value.filter(x => !conflicts.has(x)), id];
    }
    importInfo.value = '';
};
const submitImport = () => {
    if (!importProduct.value || !importPicked.value.length) return;
    importBusy.value = true;
    importErrors.value = {};
    router.post(`/admin/catalog/products/${importProduct.value.id}/import`, {
        item_ids: importPicked.value,
        publish: importPublish.value && importTemplateReady.value,
        ...(importTemplateReady.value ? { customer_no_template: importTemplate.value } : {}),
    }, {
        preserveScroll: true,
        onError: bag => { importErrors.value = bag; },
        onSuccess: () => { importOpen.value = false; importPicked.value = []; },
        onFinish: () => { importBusy.value = false; },
    });
};

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('import') === 'digiflazz') openImport();
});
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
                <button type="button" class="adm-btn" @click="openPricing">Markup Manual / Voucher</button>
                <button v-if="autoProducts.length" type="button" class="adm-btn" @click="openImport">Impor dari Digiflazz</button>
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
                        <option value="name">Nama A–Z / angka</option>
                        <option value="order">Urutan toko (manual)</option>
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
                                <span v-if="row.popular" class="adm-tag info" style="margin-left:6px">Unggulan</span>
                                <p class="dim">{{ row.publisher || 'Tanpa publisher' }} · {{ row.slug }}</p>
                            </td>
                            <td>{{ categoryName(row.category_id) }}</td>
                            <td>{{ row.fulfillment_mode === 'MANUAL' ? 'Manual' : 'Otomatis' }}</td>
                            <td>{{ activePackages(row) }} aktif dari {{ row.packages.length }}</td>
                            <td>{{ row.sort_order }}</td>
                            <td>
                                <span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                <p v-if="row.is_active" class="adm-note" :style="{ color: row.buyable_nominal_count > 0 ? 'var(--a-ok)' : 'var(--a-danger)' }">
                                    {{ row.storefront_status }} · {{ row.buyable_nominal_count }} nominal bisa dibeli
                                </p>
                            </td>
                            <td>
                                <button type="button" class="adm-btn sm" @click="openEditorFull(row)">Ubah</button>
                                <button type="button" class="adm-btn sm" @click="togglePopular(row)">{{ row.popular ? 'Lepas unggulan' : 'Jadikan unggulan' }}</button>
                                <button type="button" class="adm-btn sm danger" @click="askRemove(row, 'product')">Hapus</button>
                            </td>
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
                <template v-if="mode === 'MANUAL'">
                    <label class="adm-field full"><span>Petunjuk pengerjaan</span><textarea v-model="add.manual_instructions" class="adm-textarea" maxlength="5000" /><span class="hint">Dipakai operator dan pemberitahuan jam layanan.</span></label>
                    <label class="adm-field"><span>Jam buka layanan</span><input v-model="add.manual_open_time" type="time" class="adm-input"></label>
                    <label class="adm-field"><span>Jam tutup layanan</span><input v-model="add.manual_close_time" type="time" class="adm-input"></label>
                    <label class="adm-field"><span>Zona waktu</span>
                        <select v-model="add.manual_timezone" class="adm-select">
                            <option value="Asia/Jakarta">WIB (Asia/Jakarta)</option>
                            <option value="Asia/Makassar">WITA (Asia/Makassar)</option>
                            <option value="Asia/Jayapura">WIT (Asia/Jayapura)</option>
                        </select>
                    </label>
                </template>
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
                <button type="button" role="tab" :aria-selected="editTab === 'sources'" @click="editTab = 'sources'">Sumber</button>
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
                    <template v-if="info.fulfillment_mode === 'MANUAL'">
                        <label class="adm-field full"><span>Petunjuk pengerjaan</span><textarea v-model="info.manual_instructions" class="adm-textarea" maxlength="5000" /><span class="hint">Tampil ke operator dan pelanggan saat pesanan manual.</span></label>
                        <label class="adm-field"><span>Jam buka layanan</span><input v-model="info.manual_open_time" type="time" class="adm-input"></label>
                        <label class="adm-field"><span>Jam tutup layanan</span><input v-model="info.manual_close_time" type="time" class="adm-input"></label>
                        <label class="adm-field"><span>Zona waktu</span>
                            <select v-model="info.manual_timezone" class="adm-select">
                                <option value="Asia/Jakarta">WIB (Asia/Jakarta)</option>
                                <option value="Asia/Makassar">WITA (Asia/Makassar)</option>
                                <option value="Asia/Jayapura">WIT (Asia/Jayapura)</option>
                            </select>
                        </label>
                    </template>
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
                <div style="margin-bottom:16px">
                    <strong>Urutan grup / tabel di checkout</strong>
                    <p class="adm-note">Grup nomor 1 tampil paling atas. Atur urutan isi grup lewat tabel nominal di bawah.</p>
                    <div v-for="(group, index) in packageGroupNames" :key="group" class="adm-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                        <span><strong>{{ index + 1 }}.</strong> {{ group }}</span>
                        <div style="display:flex;gap:4px">
                            <button type="button" class="adm-btn sm" :disabled="index === 0 || saveBusy === 'group-order'" :aria-label="'Naikkan grup ' + group" @click="moveGroup(index, -1)">↑</button>
                            <button type="button" class="adm-btn sm" :disabled="index === packageGroupNames.length - 1 || saveBusy === 'group-order'" :aria-label="'Turunkan grup ' + group" @click="moveGroup(index, 1)">↓</button>
                        </div>
                    </div>
                    <p v-if="!packageGroupNames.length" class="adm-note">Tambahkan nominal untuk membuat grup.</p>
                    <p v-if="groupOrderError" class="err">{{ groupOrderError }}</p>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px">
                    <p class="adm-note">Urutan angka dari kecil ke besar. Bisa tetap diubah manual dengan geser atau panah.</p>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button type="button" class="adm-btn sm" :disabled="!product?.packages?.length" @click="sortNominalsNaturally">Urutkan nominal otomatis (angka / A–Z)</button>
                        <button type="button" class="adm-btn primary sm" @click="openNominal()">Tambah nominal</button>
                    </div>
                </div>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>Nominal</th><th class="num">Harga dasar seller</th><th class="num">Harga jual</th><th class="num">Margin</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead>
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
                                        <button type="button" class="adm-btn sm" :disabled="saveBusy === 'duplicate'" @click="duplicate(row)">Salin</button>
                                        <button v-if="canStock(row)" type="button" class="adm-btn sm" @click="openStock(row)">Stok kode</button>
                                        <button type="button" class="adm-btn sm danger" @click="askRemove(row, 'package')">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!product?.packages?.length"><td colspan="7" class="adm-empty">Belum ada nominal. Tambahkan nominal pertama.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="editTab === 'sources'" style="padding-top:12px">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px">
                    <div class="adm-note" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
                        <span>
                            Nama produk/nominal mengikuti pengaturan toko, bukan nama Digiflazz. Harga seller Digiflazz tidak bisa diedit; modal hanya untuk produk manual.
                        </span>
                        <span>
                            Sinkron Digiflazz: {{ autoSync ? 'aktif' : 'nonaktif' }}{{ autoSync ? ' · setiap ' + (syncIntervalMinutes || 15) + ' menit' : '' }}
                            · <Link href="/admin/digiflazz">Kelola di Digiflazz</Link>
                        </span>
                    </div>
                </div>
                <p v-for="(message, key) in editErrors" :key="'src-'+key" class="err">{{ message }}</p>
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>Nominal</th><th>Sumber</th><th class="num">Harga dasar</th><th class="num">Prioritas</th><th>Status</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <template v-for="row in sourceRows" :key="row.id">
                                <tr v-for="mapping in row.mappings" :key="mapping.id">
                                    <td><strong>{{ row.name }}</strong><p class="dim">{{ row.group_name || 'Umum' }}</p></td>
                                    <td>
                                        {{ mapping.provider_name || mapping.provider_code }}
                                        <p v-if="mapping.external_sku" class="dim">{{ mapping.external_sku }}</p>
                                    </td>
                                    <td class="num">
                                        <input v-if="canEditCost(mapping)" class="adm-input" style="max-width:130px;text-align:right" type="number" min="1" step="1"
                                            :value="mapping.store_cost_idr ?? mapping.cost_idr ?? 0"
                                            :disabled="saveBusy === 'source-'+mapping.id"
                                            @change="saveMapping(mapping, { store_cost_idr: Number($event.target.value || 0) })">
                                        <template v-else>{{ mapping.store_cost_idr ?? mapping.cost_idr ?? 0 }}</template>
                                    </td>
                                    <td class="num">{{ mapping.priority }}</td>
                                    <td>{{ mappingStatusLabel(mapping) }}</td>
                                    <td>
                                        <button type="button" class="adm-btn sm" :disabled="saveBusy === 'source-'+mapping.id" @click="movePriority(mapping, -1)">Naik</button>
                                        <button type="button" class="adm-btn sm" :disabled="saveBusy === 'source-'+mapping.id" @click="movePriority(mapping, 1)">Turun</button>
                                        <button type="button" class="adm-btn sm" :disabled="saveBusy === 'source-'+mapping.id" @click="toggleMapping(mapping)">
                                            {{ mapping.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                        <button v-if="canSyncMapping(mapping)" type="button" class="adm-btn sm" :disabled="saveBusy === 'sync-'+mapping.id" @click="syncMapping(mapping)">
                                            {{ saveBusy === 'sync-'+mapping.id ? 'Sinkron…' : 'Sinkron' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!row.mappings.length"><td><strong>{{ row.name }}</strong></td><td colspan="5" class="adm-empty">Belum ada sumber. Tambahkan lewat Impor nominal Digiflazz.</td></tr>
                            </template>
                            <tr v-if="!sourceRows.length"><td colspan="6" class="adm-empty">Belum ada nominal pada produk ini.</td></tr>
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
                <label v-if="showTemplateEditor" class="adm-field">
                    <span>Format ID pengiriman sesuai produk</span>
                    <input v-model="destinationFormat" class="adm-input" placeholder="Contoh: user_id + zone_id" maxlength="500">
                    <span class="hint">Diatur satu kali per game, bukan per nominal. Gunakan format yang disyaratkan Digiflazz.</span>
                </label>
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


        <AdminModal v-model:open="priceOpen" title="Markup global manual dan voucher" description="Hanya untuk nominal manual dan voucher lokal yang memakai markup global. Digiflazz memakai markup per nominal.">
            <div class="adm-grid">
                <label class="adm-field">
                    <span>Markup jual manual / voucher (%)</span>
                    <input v-model.number="manualVoucherMarkup" type="number" min="0" max="1000" step="0.01" class="adm-input">
                    <span class="hint">Tidak mengubah markup nominal Digiflazz, harga seller, atau SKU terhubung.</span>
                    <span v-if="priceErrors.sell_markup_percent" class="err">{{ priceErrors.sell_markup_percent }}</span>
                    <span v-if="priceErrors.cost_uplift_percent" class="err">{{ priceErrors.cost_uplift_percent }}</span>
                </label>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="priceOpen = false">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="!!priceBusy" @click="savePricing">{{ priceBusy ? 'Menyimpan…' : 'Simpan markup' }}</button>
            </template>
        </AdminModal>

        <AdminModal v-model:open="importOpen" title="Hubungkan SKU Digiflazz" description="SKU dan harga seller dihubungkan ke produk tujuan. Nama produk yang sudah ada tidak akan berubah." wide>
            <div class="adm-grid two">
                <label class="adm-field">
                    <span>Produk tujuan <b class="req">*</b></span>
                    <select v-model="importProductId" class="adm-select">
                        <option value="">Pilih produk tujuan</option>
                        <option v-for="row in autoProducts" :key="row.id" :value="String(row.id)">{{ row.name }}</option>
                    </select>
                </label>
                <div class="adm-field">
                    <span>Markup nominal baru</span>
                    <strong>10% dari harga seller</strong>
                    <span class="hint">Nominal baru memakai 10%. Nominal lama tanpa SKU memakai markup tersimpan. Setiap nominal bisa diubah sendiri setelah impor.</span>
                </div>
                <label class="adm-field">
                    <span>Kategori</span>
                    <select v-model="importCategory" class="adm-select" @change="importBrand = ''; importType = ''">
                        <option value="">Semua kategori</option>
                        <option v-for="category in importCategories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </label>
                <label class="adm-field">
                    <span>Game / brand</span>
                    <select v-model="importBrand" class="adm-select" @change="importType = ''">
                        <option value="">Semua game / brand</option>
                        <option v-for="brand in importBrands" :key="brand" :value="brand">{{ brand }}</option>
                    </select>
                </label>
                <label class="adm-field">
                    <span>Sub-brand / jenis</span>
                    <select v-model="importType" class="adm-select">
                        <option value="">Semua jenis</option>
                        <option v-for="type in importTypes" :key="type" :value="type">{{ type }}</option>
                    </select>
                </label>
                <label class="adm-field">
                    <span>Cari SKU</span>
                    <input v-model="importSearch" class="adm-input" placeholder="Nama nominal atau kode SKU">
                </label>
                <label v-if="importFields.length > 1 && !isMobileLegends(importProduct?.slug)" class="adm-field full">
                    <span>Format ID tujuan Digiflazz (satu kali per game)</span>
                    <input v-model="importDestination" class="adm-input" placeholder="Gunakan kode kolom sesuai spesifikasi game" maxlength="500">
                </label>
                <label class="adm-switch-row full">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="importPublish && importTemplateReady" :disabled="!importTemplateReady" @click="importPublish = !importPublish" />
                    Langsung aktif dijual
                </label>
                <p v-if="importProduct && !importTemplateReady" class="adm-note full">Format ID tujuan belum dapat dipastikan. Impor tetap dapat dilakukan sebagai draf; jangan aktifkan pengiriman dengan format tebakan.</p>
            </div>

            <p v-if="importErrors.item_ids" class="err">{{ importErrors.item_ids }}</p>
            <p v-if="importErrors.publish" class="err">{{ importErrors.publish }}</p>
            <p v-if="importErrors.customer_no_template" class="err">{{ importErrors.customer_no_template }}</p>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px">
                <strong>{{ importMatches.length }} SKU cocok</strong>
                <button type="button" class="adm-btn sm primary" :disabled="importBusy || !importMatches.length || !canBulkImport" @click="bulkSelectImport">Pilih semua hasil filter ({{ importMatches.length }})</button>
                <button type="button" class="adm-btn sm" :disabled="importBusy || !importMatches.length" @click="bulkClearImport">Hapus pilihan hasil filter</button>
                <span v-if="!canBulkImport" class="adm-note">Pilih game/brand atau cari nama (minimal 2 karakter) untuk memilih massal.</span>
            </div>
            <p v-if="importAmbiguousCount" class="adm-note">{{ importAmbiguousCount }} kandidat SKU berbagi nominal; pemilihan massal melewatinya agar seller tidak dipilih otomatis.</p>
            <p v-if="importInfo" role="status" class="adm-note">{{ importInfo }}</p>
            <div class="adm-scroll" style="margin-top:10px;max-height:46vh">
                <table class="adm-table">
                    <thead><tr><th></th><th>Nominal</th><th>SKU</th><th class="num">Harga seller</th><th class="num">Harga jual</th></tr></thead>
                    <tbody>
                        <tr v-for="row in importRows" :key="row.id">
                            <td><input type="checkbox" :checked="importPicked.includes(row.id)" @change="toggleImportRow(row.id)"></td>
                            <td><strong>{{ row.product_name }}</strong><p class="dim">{{ row.brand }}</p></td>
                            <td class="mono">{{ row.buyer_sku_code }}</td>
                            <td class="num">{{ rupiah(row.price_idr) }}</td>
                            <td class="num">{{ rupiah(sellPriceOf(row.price_idr, row)) }}</td>
                        </tr>
                        <tr v-if="!importRows.length"><td colspan="5" class="adm-empty">Tidak ada SKU tersedia untuk saringan ini. Sinkronkan daftar harga di menu Digiflazz lebih dulu.</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap;margin-top:8px">
                <p class="adm-note">{{ importPicked.length }} SKU dipilih dari seluruh halaman · nama produk dan nama nominal lama tetap milik toko.</p>
                <div style="display:flex;align-items:center;gap:8px">
                    <button type="button" class="adm-btn sm" :disabled="importPage <= 1" @click="importPage--">Sebelumnya</button>
                    <span class="adm-note">Halaman {{ importPage }} / {{ importPageCount }}</span>
                    <button type="button" class="adm-btn sm" :disabled="importPage >= importPageCount" @click="importPage++">Berikutnya</button>
                </div>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="importOpen = false">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="importBusy || !importPicked.length || !importProduct" @click="submitImport">
                    {{ importBusy ? 'Mengimpor…' : `Impor ${importPicked.length} nominal` }}
                </button>
            </template>
        </AdminModal>
        <AdminModal v-model:open="nominalOpen" :title="nominalEditing ? 'Ubah nominal' : 'Tambah nominal'" :description="product?.name || ''">
            <form id="nominal-form" class="adm-grid two" @submit.prevent="saveNominal">
                <label class="adm-field"><span>Nama nominal <b class="req">*</b></span><input v-model="nominal.name" class="adm-input" maxlength="255" required><span v-if="nominalErrors.name" class="err">{{ nominalErrors.name }}</span></label>
                <label class="adm-field"><span>Grup / tabel</span><input v-model="nominal.group_name" class="adm-input" placeholder="Umum" maxlength="120"><span class="hint">Kosong berarti Umum.</span></label>
                <label class="adm-field"><span>Posisi</span><input v-model="nominal.sort_order" type="number" min="0" class="adm-input"></label>
                <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="nominal.is_active" @click="nominal.is_active = !nominal.is_active" />Nominal aktif</label>
                <label class="adm-field">
                    <span>{{ isDigiflazzNominal ? 'Jenis tambahan harga nominal' : 'Harga' }}</span>
                    <select v-model="nominal.price_mode" class="adm-select">
                        <option value="FIXED">{{ isDigiflazzNominal ? 'Tambahan rupiah (Rp)' : 'Harga tetap' }}</option>
                        <option value="MARKUP">{{ isDigiflazzNominal ? 'Persentase (%)' : 'Markup dari modal' }}</option>
                    </select>
                </label>
                <label class="adm-field">
                    <span>{{ nominal.price_mode === 'FIXED' ? (isDigiflazzNominal ? 'Tambahan harga (Rp)' : 'Harga jual (Rp)') : 'Markup (%)' }}</span>
                    <input v-model="nominal.price_value" type="number" min="0" :max="isDigiflazzNominal && nominal.price_mode === 'FIXED' ? 1000000000 : undefined" :step="nominal.price_mode === 'FIXED' ? '1' : '0.0001'" class="adm-input" required>
                    <span v-if="nominalErrors.sell_price_idr || nominalErrors.margin_fixed_idr || nominalErrors.margin_percent || nominalErrors.pricing_mode" class="err">{{ nominalErrors.sell_price_idr || nominalErrors.margin_fixed_idr || nominalErrors.margin_percent || nominalErrors.pricing_mode }}</span>
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
        <AdminModal :open="!!removeTarget" :title="removeKind === 'notice' ? 'Hapus pemberitahuan' : removeKind === 'product' ? 'Hapus produk' : 'Hapus nominal'" :description="removeTarget?.name || removeTarget?.title || ''" @update:open="value => { if (!value) removeTarget = null; }">
            <p class="adm-note">{{ removeKind === 'notice' ? 'Pemberitahuan ini akan hilang dari halaman produk toko.' : removeKind === 'product' ? 'Produk beserta seluruh nominalnya dihapus permanen. Produk yang punya riwayat pesanan akan ditolak; nonaktifkan saja bila hanya ingin menghentikan penjualan.' : 'Nominal yang memiliki riwayat pesanan tidak dapat dihapus; nonaktifkan bila hanya ingin menghentikan penjualan.' }}</p>
            <p v-for="(message, key) in removeErrors" :key="key" role="alert" class="err">{{ message }}</p>
            <template #footer>
                <button type="button" class="adm-btn" :disabled="removeBusy" @click="removeTarget = null">Batal</button>
                <button type="button" class="adm-btn danger" :disabled="removeBusy" @click="confirmRemove">{{ removeBusy ? 'Menghapus…' : 'Hapus' }}</button>
            </template>
        </AdminModal>
        <AdminModal v-model:open="stockOpen" title="Stok kode" :description="stockRow?.name || ''">
            <div class="adm-grid two">
                <label class="adm-field"><span>Nama stok <b class="req">*</b></span><input v-model="stock.stock_key" class="adm-input" maxlength="100" placeholder="steam-wallet-50k"><span v-if="stockErrors.stock_key" class="err">{{ stockErrors.stock_key }}</span></label>
                <label class="adm-field"><span>Modal (Rp) <b class="req">*</b></span><input v-model="stock.cost_idr" type="number" min="1" class="adm-input"><span v-if="stockErrors.cost_idr" class="err">{{ stockErrors.cost_idr }}</span></label>
                <label class="adm-field"><span>Prioritas</span><input v-model="stock.priority" type="number" min="0" class="adm-input"><span v-if="stockErrors.priority" class="err">{{ stockErrors.priority }}</span></label>
                <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="stock.is_active" @click="stock.is_active = !stock.is_active" />Sumber aktif</label>
                <label class="adm-field full"><span>Kode baru (satu per baris)</span><textarea v-model="stock.codes_text" class="adm-textarea" rows="6" maxlength="500000" placeholder="AAAA-BBBB-CCCC" /><span class="hint">Kode disimpan terenkripsi dan hanya dikirim sekali. Kosongkan bila hanya mengubah modal atau status.</span><span v-if="stockErrors.codes_text" class="err">{{ stockErrors.codes_text }}</span></label>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="stockOpen = false">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="stockBusy" @click="saveStock">{{ stockBusy ? 'Menyimpan…' : 'Simpan stok' }}</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
