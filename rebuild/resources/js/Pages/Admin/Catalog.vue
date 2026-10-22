<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import { Card } from '../../Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import AdminCustomerFields from '../../Components/AdminCustomerFields.vue';
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';
import { byOrder, bySourcePriority, displayPosition } from '../../lib/ordering.js';

const props = defineProps({
    categories: Array,
    products: Array,
    checkoutPreviews: { type: Object, default: () => ({}) },
    digiflazzItems: { type: Array, default: () => [] },
    nicknameGameCodes: { type: Array, default: () => [] },
    defaultMargin: Number,
    pricingSettings: { type: Object, default: () => ({ cost_uplift_percent: '2', sell_markup_percent: '10' }) },
    fixedPriceProposals: { type: Array, default: () => [] },
    fixedPriceAlerts: { type: Array, default: () => [] },
});
const cloneProducts = (items) => items.map((item) => ({
    ...item,
    packages: item.packages.map((pack) => {
        const mappings = pack.mappings.map((mapping) => ({
            ...mapping,
            store_cost_idr: mapping.store_cost_idr ?? mapping.cost_idr,
            original_store_cost_idr: mapping.store_cost_idr ?? mapping.cost_idr,
            manual_price_idr: mapping.is_manual_price ? mapping.max_price_idr : mapping.cost_idr,
        }));
        const stock = mappings.find((mapping) => mapping.provider_code === 'VOUCHER_STOCK');
        return {
            ...pack,
            mappings,
            stock_form: {
                stock_key: stock?.stock_key || '',
                cost_idr: stock?.cost_idr || '',
                priority: stock?.priority || 0,
                is_active: Boolean(stock?.is_active),
                codes_text: '',
            },
        };
    }),
    notices: (item.notices || []).map((notice) => ({ ...notice })),
}));
const categories = ref(props.categories.map((item) => ({ ...item })));
const products = ref(cloneProducts(props.products));
const productPosition = (row) => displayPosition(
    products.value.filter((item) => item.fulfillment_mode === row.fulfillment_mode), row,
    (a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0)
        || a.name.localeCompare(b.name, 'id-ID', { numeric: true })
        || Number(a.id) - Number(b.id),
);
const packagePosition = (product, pack) => displayPosition(product.packages, pack, byOrder());
const sourcePosition = (pack, mapping) => displayPosition(
    pack.mappings.filter((entry) => entry.is_active),
    mapping, bySourcePriority,
);
watch(() => props.categories, (items) => { categories.value = items.map((item) => ({ ...item })); });
watch(() => props.products, (items) => { products.value = cloneProducts(items); });

const page = usePage();

// Satu dialog konfirmasi bertema untuk semua tindakan berisiko di halaman ini.
const pendingAction = ref(null);
const ask = (title, message, confirmLabel, run, destructive = false) => {
    pendingAction.value = { title, message, confirmLabel, run, destructive };
};
const runPending = () => {
    const action = pendingAction.value;
    pendingAction.value = null;
    action?.run();
};
const initialSearch = new URLSearchParams(page.url.split('?')[1] || '').get('q') || '';
const searchedProduct = products.value.find(item => item.name.toLowerCase().includes(initialSearch.toLowerCase()));
const tab = ref(initialSearch && searchedProduct ? searchedProduct.fulfillment_mode : 'AUTO_PROVIDER');
const editorIdFromUrl = () => { const id=Number(new URLSearchParams(page.url.split('?')[1] || '').get('edit')); return products.value.some(item=>item.id===id) ? id : null; };
const selectedProductId = ref(editorIdFromUrl()), editorTab = ref('info'), showCreateProduct = ref(false);
const selectedPackageId=ref(null), savingProduct=ref(false), showCreatePackage=ref(false), nominalPage=ref(1);
const visiblePackages = item => item.packages.slice((nominalPage.value-1)*25,nominalPage.value*25);
watch(selectedProductId,()=>{nominalPage.value=1;});
const editPackage = id => {selectedPackageId.value=id;nextTick(()=>document.getElementById('nominal-'+id)?.scrollIntoView({block:'start'}));};
const closeProduct = () => { const params=new URLSearchParams(page.url.split('?')[1] || '');params.delete('edit');router.push({url:'/admin/catalog'+(params.size?'?'+params.toString():''),preserveState:true,preserveScroll:false}); };
watch(()=>page.url,()=>{selectedProductId.value=editorIdFromUrl();});
const selectedProductItems = computed(() => products.value.filter(item => item.id === selectedProductId.value));
const editProduct = item => { selectedProductId.value = item.id; editorTab.value = 'info'; showImport.value=false; importForm.item_ids=[]; importForm.margin_percent=Number(item.margin_percent); selectedPackageId.value=null; const params=new URLSearchParams(page.url.split('?')[1] || '');params.set('edit',String(item.id));router.push({url:'/admin/catalog?'+params.toString(),preserveState:true,preserveScroll:false}); };
const catalogSearch = ref(initialSearch), catalogPage = ref(1);
const catalogCategory = ref(''), catalogStatus = ref(''), catalogSort = ref('CUSTOM'), catalogPageSize = ref(25);
const matchingProducts = computed(() => {
    const term = catalogSearch.value.trim().toLowerCase();
    const rows = products.value.filter(item =>
        item.fulfillment_mode === tab.value
        && (!catalogCategory.value || String(item.category_id) === String(catalogCategory.value))
        && (!catalogStatus.value || (catalogStatus.value === 'ACTIVE' ? item.is_active : !item.is_active))
        && (!term || [item.name,item.slug,item.publisher,...item.packages.map(p => p.name)].join(' ').toLowerCase().includes(term))
    );
    return [...rows].sort((a,b) => catalogSort.value === 'NAME'
        ? a.name.localeCompare(b.name,'id-ID',{numeric:true,sensitivity:'base'})
        : Number(a.sort_order||0)-Number(b.sort_order||0) || a.name.localeCompare(b.name,'id-ID',{numeric:true,sensitivity:'base'}));
});
const totalCatalogPages = computed(() => Math.max(1, Math.ceil(matchingProducts.value.length / Number(catalogPageSize.value))));
const visibleProducts = computed(() => {
    const size = Number(catalogPageSize.value);
    return matchingProducts.value.slice((catalogPage.value - 1) * size, catalogPage.value * size);
});
watch([tab,catalogSearch,catalogCategory,catalogStatus,catalogSort,catalogPageSize], () => {catalogPage.value = 1;});
const resetCatalogFilters=()=>{catalogSearch.value='';catalogCategory.value='';catalogStatus.value='';catalogSort.value='CUSTOM';catalogPageSize.value=25;catalogPage.value=1;};
const productForm = useForm({ category_id: '', name: '', slug: '', publisher: '', description: '', fulfillment_mode: 'AUTO_PROVIDER', manual_instructions: '', manual_open_time: '', manual_close_time: '', manual_timezone: 'Asia/Jakarta', margin_percent: props.defaultMargin||0, sell_markup_percent: null, sort_order: Math.max(-1, ...products.value.map((item) => Number(item.sort_order ?? 0))) + 1 });
// Produk manual dibuat satu formulir untuk banyak nominal sekaligus: tiap baris
// punya modal tokonya sendiri dan memakai markup baris → produk → global.
const emptyManualRow = () => ({ name: '', nominal_value: '', cost_idr: '', sell_markup_percent: '' });
const manualRows = ref([emptyManualRow()]);
const addManualRow = () => manualRows.value.push(emptyManualRow());
const removeManualRow = index => {
    manualRows.value.splice(index, 1);
    if (!manualRows.value.length) manualRows.value.push(emptyManualRow());
};
const createRowMarkup = row => {
    for (const candidate of [row.sell_markup_percent, productForm.sell_markup_percent, props.pricingSettings?.sell_markup_percent]) {
        if (candidate !== '' && candidate !== null && candidate !== undefined) return Number(candidate);
    }
    return 10;
};
const createRowPreview = row => markupExample(row.cost_idr || 0, 0, createRowMarkup(row));
const filledManualRows = () => manualRows.value
    .filter(row => String(row.name || '').trim() !== '')
    .map((row, index) => ({
        name: String(row.name).trim(),
        nominal_value: row.nominal_value === '' || row.nominal_value === null || row.nominal_value === undefined ? null : Number(row.nominal_value),
        sort_order: index,
        cost_idr: Number(row.cost_idr || 0),
        ...(row.sell_markup_percent === '' || row.sell_markup_percent === null || row.sell_markup_percent === undefined
            ? {}
            : { sell_markup_percent: Number(row.sell_markup_percent) }),
    }));
const submitProduct = () => {
    productForm.fulfillment_mode = tab.value;
    const rows = tab.value === 'MANUAL' ? filledManualRows() : [];
    productForm
        .transform(data => (rows.length ? { ...data, packages: rows } : data))
        .post('/admin/catalog/products', {
            onSuccess: () => {
                productForm.reset();
                manualRows.value = [emptyManualRow()];
                productForm.sort_order = Math.max(-1, ...products.value.map((row) => Number(row.sort_order ?? 0))) + 1;
                showCreateProduct.value = false;
            },
        });
};
const globalMarginForm = useForm({margin_percent:props.defaultMargin||0,include_imported_percent:false});
const pricingForm = useForm({
    cost_uplift_percent: Number(props.pricingSettings?.cost_uplift_percent ?? 2),
    sell_markup_percent: Number(props.pricingSettings?.sell_markup_percent ?? 10),
});
const togglePopular = item => router.put('/admin/vouchers/popular/' + item.id, { popular: !item.popular }, { preserveScroll: true });
const savePricingSettings = () => pricingForm
    .transform(data => ({ cost_uplift_percent: Number(data.cost_uplift_percent), sell_markup_percent: Number(data.sell_markup_percent) }))
    .put('/admin/catalog/pricing', { preserveScroll: true });
// Persentase disimpan sebagai teks desimal; null berarti "ikut tingkat di atasnya".
const overrideValue = value => (value === null || value === undefined || value === '') ? null : Number(value);
const effectiveMarkupPercent = (pack, item) => {
    const nominal = overrideValue(pack?.sell_markup_percent);
    const game = overrideValue(item?.sell_markup_percent);
    return nominal ?? game ?? Number(props.pricingSettings?.sell_markup_percent ?? 10);
};
const effectiveUpliftPercent = (pack, item) => {
    const nominal = overrideValue(pack?.cost_uplift_percent);
    const game = overrideValue(item?.cost_uplift_percent);
    return nominal ?? game ?? Number(props.pricingSettings?.cost_uplift_percent ?? 2);
};
const markupExample = (amount, uplift, markup) => {
    const stored = Math.ceil((Number(amount) * (1000000 + Math.round(Number(uplift) * 10000))) / 1000000);
    const sell = Math.ceil((stored * (1000000 + Math.round(Number(markup) * 10000))) / 1000000);
    return { stored, sell };
};
const legacyDigiflazzPercent = computed(() => products.value
    .filter(item=>item.fulfillment_mode==='AUTO_PROVIDER')
    .flatMap(item=>item.packages)
    .filter(pack=>pack.pricing_mode==='PERCENT' && String(pack.code||'').startsWith('DF_')
        && pack.mappings.some(mapping=>mapping.provider_code==='DIGIFLAZZ')).length);
const globalMarginPreview = ref(null);
const globalMarginError = ref('');
const globalMarginBusy = ref(false);
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const formatAdminMoney = value => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const hasNumericValue = value => value !== null && value !== undefined
    && !(typeof value === 'string' && value.trim() === '') && Number.isFinite(Number(value));
const requestAdminPreview = async (url, payload) => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(result.errors || {})?.[0]?.[0] || result.message || 'Preview tidak dapat dibuat.');
    }
    return result;
};
const previewGlobalMargin = async () => {
    globalMarginPreview.value = null;
    globalMarginError.value = '';
    globalMarginBusy.value = true;
    try {
        globalMarginPreview.value = await requestAdminPreview('/admin/catalog/margin/preview', {
            margin_percent: globalMarginForm.margin_percent,
            include_imported_percent: globalMarginForm.include_imported_percent,
        });
    } catch (error) {
        globalMarginError.value = error.message;
    } finally {
        globalMarginBusy.value = false;
    }
};
watch(() => [globalMarginForm.margin_percent, globalMarginForm.include_imported_percent], () => {
    globalMarginPreview.value = null;
});
const applyGlobalMargin = () => {
    if (!globalMarginPreview.value) return;
    ask('Terapkan perubahan harga', 'Harga jual pada pratinjau akan disimpan untuk semua sumber harga yang tercantum. Pratinjau perlu dibuat ulang bila data berubah.', 'Terapkan harga', () => {
        router.put('/admin/catalog/margin', { preview_token: globalMarginPreview.value.token }, {
            preserveScroll: true,
            onSuccess: () => { globalMarginPreview.value = null; },
            onError: errors => { globalMarginError.value = errors.preview_token || 'Perubahan tidak dapat diterapkan. Ulangi preview.'; },
        });
    });
};
const applyFixedDigiflazzPrices = () => {
    const rows = props.fixedPriceProposals;
    if (!rows.length) return;
    ask('Kunci harga jual Digiflazz', 'Harga jual ' + rows.length + ' nominal akan dikunci sesuai margin yang sudah diatur admin. Harga tetap manual tidak ditimpa.', 'Kunci ' + rows.length + ' harga', () => {
        router.post('/admin/catalog/digiflazz/lock-prices', { package_ids: rows.map(row => row.package_id) }, { preserveScroll: true });
    });
};
const importForm = useForm({item_ids:[],margin_percent:props.defaultMargin||0,publish:true});
const importSearch=ref(''),importBrand=ref(''),showImport=ref(false);
const importPage=ref(1);
const importUrl = new URLSearchParams(page.url.split('?')[1] || '');
const showCatalogImport = ref(importUrl.get('import') === 'digiflazz' || importUrl.has('import_sku'));
const importProductId = ref('');
const importableProducts = computed(() => products.value.filter(item => item.fulfillment_mode === 'AUTO_PROVIDER'));
function openProductImport() {
    const item = importableProducts.value.find(product => String(product.id) === String(importProductId.value));
    if (!item) return;
    editProduct(item);
    editorTab.value = 'nominal';
    showImport.value = true;
    importForm.clearErrors();
    importForm.publish = true;
    importSearch.value = '';
    importBrand.value = props.digiflazzItems.find(sku=>sku.brand.toLowerCase()===item.name.toLowerCase())?.brand || '';
    importPage.value = 1;
    const requestedSku = Number(new URLSearchParams(page.url.split('?')[1] || '').get('import_sku'));
    const candidate = props.digiflazzItems.find(sku => sku.id === requestedSku && sku.available && !sku.mapped);
    importForm.item_ids = candidate ? [importMatches.value.find(sku=>sku.source_group===candidate.source_group)?.id || candidate.id] : [];
    showCatalogImport.value = false;
}
function toggleImport(item) {
    showImport.value = !showImport.value;
    if (showImport.value) {
        importForm.clearErrors();
        importForm.publish = true;
        importForm.margin_percent = Number(effectiveMarkupPercent(null, item));
        importBrand.value = props.digiflazzItems.find(sku=>sku.brand.toLowerCase()===item.name.toLowerCase())?.brand || '';
    }
}

const importBrands=computed(()=>[...new Set(props.digiflazzItems.map(i=>i.brand))].sort());
const importMatches=computed(()=>props.digiflazzItems
    .filter(item=>!item.mapped && item.available && (!importBrand.value||item.brand===importBrand.value) && (!importSearch.value||[item.product_name,item.buyer_sku_code].join(' ').toLowerCase().includes(importSearch.value.toLowerCase())))
    .sort((a,b)=>(a.nominal_value??Infinity)-(b.nominal_value??Infinity)||a.product_name.localeCompare(b.product_name,'id-ID',{numeric:true})));
const importItems=computed(()=>importMatches.value.slice((importPage.value-1)*25,importPage.value*25));
// Review helpers: the importer turns the reviewed margin into the game markup,
// so the same two-stage maths used by the pricing card can preview every row.
const importRowPreview = (sku, item) => markupExample(
    sku.price_idr,
    effectiveUpliftPercent(null, item),
    item.sell_markup_percent !== null && item.sell_markup_percent !== undefined
        ? effectiveMarkupPercent(null, item)
        : Number(importForm.margin_percent),
);
const selectedImportItems=()=>importMatches.value.filter(sku=>importForm.item_ids.includes(sku.id));
const importTotals=()=>selectedImportItems().reduce((acc,sku)=>{
    const row=importRowPreview(sku,selectedProductItems.value[0]);acc.count+=1;acc.seller+=Number(sku.price_idr||0);acc.stored+=row.stored;acc.sell+=row.sell;return acc;
},{count:0,seller:0,stored:0,sell:0});
const nominalAlreadyExists=(sku,item)=>sku.nominal_value!==null&&sku.nominal_value!==undefined
    &&(item.packages||[]).some(pack=>pack.nominal_value!==null&&pack.nominal_value!==undefined&&Number(pack.nominal_value)===Number(sku.nominal_value));
const duplicateImportRows=(item)=>selectedImportItems().filter(sku=>nominalAlreadyExists(sku,item));
const importUnavailableCount=computed(()=>props.digiflazzItems.filter(sku=>!sku.mapped&&!sku.available&&(!importBrand.value||sku.brand===importBrand.value)).length);
const submitImport=(item)=>{
    const duplicates=duplicateImportRows(item);
    const submit=()=>importForm.post('/admin/catalog/products/'+item.id+'/import',{preserveScroll:true,onSuccess:()=>{importForm.item_ids=[];showImport.value=false;}});
    if(!duplicates.length){submit();return;}
    ask('Nominal serupa sudah ada', duplicates.length+' nominal yang dipilih sudah punya nominal serupa di produk ini ('+duplicates.slice(0,5).map(sku=>sku.product_name).join(', ')+'). Tetap impor sebagai nominal baru?', 'Tetap impor', submit);
};
watch([importSearch,importBrand],()=>{importPage.value=1;});
const draggedPackageId=ref(null);
const reorderPackage=(item,index,direction)=>{
 const target=index+direction;if(target<0||target>=item.packages.length)return;
 const packageItem=item.packages.splice(index,1)[0];item.packages.splice(target,0,packageItem);
 router.put('/admin/catalog/products/'+item.id+'/packages/reorder',{ids:item.packages.map(p=>p.id)},{preserveScroll:true});
};
const dropPackage=(item,index)=>{
 const from=item.packages.findIndex(p=>p.id===draggedPackageId.value);
 draggedPackageId.value=null;if(from<0||from===index)return;
 const moved=item.packages.splice(from,1)[0];item.packages.splice(index,0,moved);
 router.put('/admin/catalog/products/'+item.id+'/packages/reorder',{ids:item.packages.map(p=>p.id)},{preserveScroll:true});
};
const mappingCost = (mapping) => Number(mapping?.store_cost_idr ?? mapping?.cost_idr ?? 0);
const previewPrice=(pack)=>{
 const price=props.checkoutPreviews[pack.id];
 return price===null||price===undefined?'Belum tersedia':`Rp${Number(price).toLocaleString('id-ID')}`;
};
watch(()=>props.defaultMargin,value=>{if(!productForm.isDirty)productForm.margin_percent=value;if(!globalMarginForm.isDirty)globalMarginForm.margin_percent=value;if(!importForm.isDirty)importForm.margin_percent=value;});
const packageForm = useForm({ product_id: '', code: '', name: '', note: '', group_name: '', nominal_value: '', sort_order: 0, cost_idr: '' });
const noticeDrafts = reactive({});
const fieldsProductId = ref('');
const fieldRows = ref([]);
const fieldsError = ref('');
const fieldsSaving = ref(false);
const customerTemplateOriginal = ref('');
watch(fieldsProductId, (id) => {
    const product = products.value.find(item => String(item.id) === String(id));
    fieldRows.value = (product?.fields || []).map(field => ({
        field_key: field.field_key, label: field.label, placeholder: field.placeholder || '',
        type: field.type, is_required: Boolean(field.is_required),
    }));
    customerTemplateOriginal.value = product?.customer_no_template || '';
    fieldsError.value = '';
});
const addField = () => fieldRows.value.push({ field_key: '', label: '', placeholder: '', type: 'text', is_required: true });
const moveField = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= fieldRows.value.length) return;
    const row = fieldRows.value.splice(index, 1)[0];
    fieldRows.value.splice(target, 0, row);
};
const saveFields = () => {
    fieldsError.value = '';
    fieldsSaving.value = true;
    const product = products.value.find(item => String(item.id) === String(fieldsProductId.value));
    const customerTemplate = product?.customer_no_template || '';
    const customerTemplateChanged = customerTemplate !== customerTemplateOriginal.value;
    router.put('/admin/catalog/products/' + fieldsProductId.value + '/fields', {
        ...(customerTemplateChanged ? { customer_no_template: customerTemplate || null } : {}),
        fields: fieldRows.value.map(field => ({ ...field, field_key: field.field_key.trim(), label: field.label.trim(), placeholder: field.placeholder.trim() || null })),
    }, {
        preserveScroll: true,
        onError: errors => { fieldsError.value = Object.values(errors).join(' · '); },
        onSuccess: () => { customerTemplateOriginal.value = customerTemplate; },
        onFinish: () => { fieldsSaving.value = false; },
    });
};
const deleteProduct = (item) => ask('Hapus produk', 'Produk "' + item.name + '" akan dihapus. Produk yang memiliki riwayat pesanan akan ditolak oleh sistem.', 'Hapus produk', () => {
    router.delete('/admin/catalog/products/' + item.id, { preserveScroll: true, onSuccess: () => { selectedProductId.value = null; } });
}, true);
const deletePackage = (pack) => ask('Hapus nominal', 'Nominal "' + pack.name + '" akan dihapus. Nominal yang pernah dipesan tidak dapat dihapus.', 'Hapus nominal', () => {
    router.delete('/admin/catalog/packages/' + pack.id, { preserveScroll: true, onSuccess: () => { selectedPackageId.value = null; } });
}, true);
const duplicatePackage = (item, pack) => {
    if (item.fulfillment_mode !== 'MANUAL') return;
    router.post('/admin/catalog/packages/' + pack.id + '/duplicate', {}, { preserveScroll: true });
};
const saveProduct = (item) => router.put('/admin/catalog/products/' + item.id, {
    category_id: item.category_id, name: item.name, slug: item.slug, publisher: item.publisher || '', description: item.description,
    fulfillment_mode: item.fulfillment_mode, initials: item.initials || null, accent_color: item.accent_color || null,
    instant: Boolean(item.instant), package_tabs_enabled: Boolean(item.package_tabs_enabled), package_tabs: item.package_tabs || [],
    manual_instructions: item.manual_instructions, manual_open_time: item.manual_open_time || null,
    manual_close_time: item.manual_close_time || null, manual_timezone: item.manual_timezone || 'Asia/Jakarta',
    margin_percent: item.margin_percent,
    sell_markup_percent: overrideValue(item.sell_markup_percent),
    cost_uplift_percent: overrideValue(item.cost_uplift_percent),
    sort_order: item.sort_order, is_active: item.is_active,
    nickname_check_enabled: item.nickname_check_enabled,
    nickname_game_code: item.nickname_game_code || null,
    nickname_user_field_key: item.nickname_user_field_key || null,
    nickname_server_field_key: item.nickname_server_field_key || null,
}, {preserveScroll:true,onStart:()=>{savingProduct.value=true;},onSuccess:()=>{if(String(fieldsProductId.value)===String(item.id))customerTemplateOriginal.value=item.customer_no_template||'';},onFinish:()=>{savingProduct.value=false;}});
const savePackage = (pack) => router.put('/admin/catalog/packages/' + pack.id, {
    code: pack.code, name: pack.name, note: pack.note || null, group_name: pack.group_name || null, nominal_value: pack.nominal_value,
    sort_order: pack.sort_order, is_active: pack.is_active,
    pricing_mode:pack.pricing_mode||'PRODUCT_MARGIN',margin_percent:pack.margin_percent??null,margin_fixed_idr:pack.margin_fixed_idr??null,sell_price_idr:pack.sell_price_idr??null,
    sell_markup_percent:overrideValue(pack.sell_markup_percent),cost_uplift_percent:overrideValue(pack.cost_uplift_percent),
});
const saveMapping = (mapping) => router.put('/admin/catalog/mappings/' + mapping.id, {
    priority: mapping.priority, is_active: mapping.is_active,
    ...(mapping.provider_code === 'MANUAL' ? {
        cost_idr: mapping.cost_idr,
        ...(mapping.store_cost_idr !== mapping.original_store_cost_idr && hasNumericValue(mapping.store_cost_idr)
            ? { store_cost_idr: Number(mapping.store_cost_idr) } : {}),
    } : {}),
    ...(mapping.provider_code === 'DIGIFLAZZ' ? {
        ...(hasNumericValue(mapping.store_cost_idr)
            ? { store_cost_idr: Number(mapping.store_cost_idr) } : {}),
        is_manual_price: Boolean(mapping.is_manual_price),
        ...(mapping.is_manual_price ? { manual_price_idr: Number(mapping.manual_price_idr) } : {}),
    } : {}),
});
const providerLabel = (mapping) => mapping.provider_name || mapping.provider_code || 'Provider';
const fulfillmentRole = (pack, mapping) => {
    if (mapping.disabled_by_sync) return 'SKU hilang · dinonaktifkan sync';
    if (!mapping.is_active) return 'Nonaktif';
    const active = pack.mappings.filter((entry) => entry.is_active).sort(bySourcePriority);
    const index = active.findIndex((entry) => Number(entry.id) === Number(mapping.id));
    return index <= 0 ? 'Utama' : 'Cadangan ' + index;
};
const stockMapping = (pack) => pack.mappings.find((mapping) => mapping.provider_code === 'VOUCHER_STOCK');
const saveVoucherStock = (pack) => router.post('/admin/catalog/packages/' + pack.id + '/voucher-stock', {
    stock_key: pack.stock_form.stock_key,
    cost_idr: Number(pack.stock_form.cost_idr || 0),
    priority: Number(pack.stock_form.priority || 0),
    is_active: Boolean(pack.stock_form.is_active),
    codes_text: pack.stock_form.codes_text || null,
}, {
    preserveScroll: true,
    onSuccess: () => { pack.stock_form.codes_text = ''; },
});

const noticeDraft = (productId) => noticeDrafts[productId] || (noticeDrafts[productId] = { title: '', body: '', sort_order: 0, is_active: true });
const addNotice = (product) => {
    const draft = noticeDraft(product.id);
    if (!draft.title.trim() || !draft.body.trim()) return;
    router.post('/admin/catalog/products/' + product.id + '/notices', draft, {
        preserveScroll: true,
        onSuccess: () => { noticeDrafts[product.id] = { title: '', body: '', sort_order: 0, is_active: true }; },
    });
};
const saveNotice = (notice) => router.put('/admin/catalog/notices/' + notice.id, {
    title: notice.title, body: notice.body, sort_order: Number(notice.sort_order || 0), is_active: Boolean(notice.is_active),
}, { preserveScroll: true });
const deleteNotice = (notice) => ask('Hapus pemberitahuan', 'Pemberitahuan "' + (notice.title || 'tanpa judul') + '" akan dihapus dari halaman produk.', 'Hapus pemberitahuan', () => {
    router.delete('/admin/catalog/notices/' + notice.id, { preserveScroll: true });
}, true);
</script>

<template>
    <Head title="Kelola katalog" />
    <AdminShell>
        <div class="mx-auto max-w-7xl space-y-8">
            <header class="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight">Produk</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Daftar produk yang dijual di toko. Pilih satu produk untuk mengatur harga, nominal, dan tampilannya. Kode SKU penyedia tidak pernah ditampilkan ke pelanggan.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child><Link href="/admin/catalog/categories">Kategori</Link></Button>
                    <Button variant="outline" as-child><Link href="/">Lihat toko</Link></Button>
                </div>
            </header>

            <details class="lf-admin-advanced rounded-md border border-border">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Pengaturan harga lanjutan</summary>
                <div class="space-y-4 border-t border-border p-4">
                    <p class="text-xs text-muted-foreground">Dipakai hanya bila kamu perlu mengubah harga banyak produk sekaligus. Harga per produk dan per nominal tetap diatur di ruang kerja masing-masing.</p>
                    <Card class="space-y-3 p-4" data-testid="digiflazz-fixed-price-policy">
                        <h2 class="font-semibold">Harga tetap Digiflazz · mengikuti pengaturan admin</h2>
                        <p class="text-sm text-muted-foreground">Perbarui harga dari Digiflazz tanpa mengubah harga jual tetap atau modal yang kamu atur sendiri. Nominal yang melewati batas harga atau sedang tidak tersedia akan ditandai untuk diperiksa.</p>
                        <p class="text-sm">Harga dinamis siap dikunci: <b>{{fixedPriceProposals.length}}</b> · Nominal tanpa sumber yang memenuhi batas admin/tersedia: <b>{{fixedPriceAlerts.length}}</b></p>
                        <div v-if="fixedPriceProposals.length" class="max-h-56 overflow-auto rounded border p-2 text-xs">
                            <div v-for="row in fixedPriceProposals" :key="row.package_id" class="flex flex-wrap justify-between gap-2 border-b py-1">
                                <span>{{row.product_name}} / {{row.package_name}} · modal Rp{{Number(row.cost_idr).toLocaleString('id-ID')}} · SKU {{row.seller_sku}}</span>
                                <b>Jual Rp{{Number(row.sell_price_idr).toLocaleString('id-ID')}}</b>
                            </div>
                        </div>
                        <Button type="button" :disabled="!fixedPriceProposals.length||fixedPriceProposals.length>2000" @click="applyFixedDigiflazzPrices">Setujui &amp; kunci {{fixedPriceProposals.length}} harga jual</Button>
                        <div v-if="fixedPriceAlerts.length" class="max-h-48 overflow-auto rounded border border-amber-600 p-2 text-xs">
                            <strong>Peringatan: produk terkunci sementara karena harga supplier / stok / cutoff jam</strong>
                            <div v-for="row in fixedPriceAlerts" :key="row.package_id" class="border-b py-1">{{row.product_name}} / {{row.package_name}} · jual Rp{{Number(row.sell_price_idr).toLocaleString('id-ID')}} · batas modal manual {{row.maximum_cost_idr===null?'tidak ditetapkan':'Rp'+Number(row.maximum_cost_idr).toLocaleString('id-ID')}} · modal tersedia {{row.lowest_cost_idr===null?'tidak ada':'Rp'+Number(row.lowest_cost_idr).toLocaleString('id-ID')}}</div>
                        </div>
                    </Card>
                    <Card class="p-4">
                        <form class="flex flex-wrap items-end gap-3" @submit.prevent="previewGlobalMargin">
                            <label class="text-sm">Markup harga jual otomatis (%)
                                <Input v-model.number="globalMarginForm.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/>
                            </label>
                            <Button :disabled="globalMarginBusy">{{globalMarginBusy ? 'Menghitung…' : 'Pratinjau perubahan harga'}}</Button>
                            <label class="flex w-full items-start gap-2 text-xs"><input v-model="globalMarginForm.include_imported_percent" type="checkbox" class="mt-0.5"/><span>Jadikan {{legacyDigiflazzPercent}} nominal Digiflazz lama yang memiliki markup sendiri mengikuti pengaturan otomatis ini. Termasuk markup khusus yang pernah diedit; centang hanya jika kamu memang ingin menggantinya sekaligus.</span></label>
                            <p class="w-full text-xs text-muted-foreground">Pratinjau belum menyimpan perubahan. Produk manual, harga jual tetap, dan tambahan harga dalam rupiah tidak diubah.</p>
                            <p v-if="globalMarginError" class="w-full text-sm text-destructive" role="alert">{{globalMarginError}}</p>
                        </form>
                        <div v-if="globalMarginPreview" class="mt-4 space-y-3 rounded border p-3">
                            <strong>Pratinjau perubahan — {{globalMarginPreview.summary.affected_mappings}} sumber harga akan berubah dari {{globalMarginPreview.summary.previewed_mappings}} yang diperiksa</strong>
                            <p class="text-sm">Produk terdampak: {{globalMarginPreview.summary.products}} · Nominal yang ikut pengaturan baru: {{globalMarginPreview.summary.converted_packages}} · Total selisih harga jual: {{formatAdminMoney(globalMarginPreview.summary.total_sell_difference_idr)}}</p>
                            <div class="max-h-72 space-y-1 overflow-auto text-xs">
                                <div v-for="row in globalMarginPreview.mappings" :key="row.mapping_id" class="border-b py-2">
                                    <b>{{row.product}} / {{row.package}} · {{row.sku}}</b>
                                    <p>Modal {{formatAdminMoney(row.cost_idr)}} · Sebelum {{formatAdminMoney(row.before_sell_idr)}} → Sesudah {{formatAdminMoney(row.after_sell_idr)}} · Selisih {{formatAdminMoney(row.difference_idr)}}</p>
                                </div>
                            </div>
                            <Button type="button" variant="outline" @click="applyGlobalMargin">Konfirmasi &amp; terapkan sesuai preview</Button>
                        </div>
                    </Card>
                    <Card class="space-y-3 p-4" data-testid="catalog-pricing-settings">
                        <div>
                            <h3 class="font-semibold">Harga katalog otomatis: tambahan modal &amp; markup jual</h3>
                            <p class="mt-1 text-xs text-muted-foreground">Dua tahap terpisah. Tahap 1 menambah sekian persen dari harga seller Digiflazz menjadi <strong>modal toko</strong> dan hanya dipakai sekali saat nominal dibuat. Tahap 2 menambah sekian persen dari modal toko menjadi <strong>harga jual</strong> dan langsung dipakai saat pelanggan membeli. Angka ini dipakai bila game atau nominal belum punya nilainya sendiri.</p>
                        </div>
                        <form class="grid gap-3 md:grid-cols-3" @submit.prevent="savePricingSettings">
                            <label class="text-sm">Tahap 1 — tambahan modal dari harga seller (%)
                                <Input v-model.number="pricingForm.cost_uplift_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1" />
                                <span v-if="pricingForm.errors.cost_uplift_percent" role="alert" class="mt-1 block text-xs text-destructive">{{ pricingForm.errors.cost_uplift_percent }}</span>
                            </label>
                            <label class="text-sm">Tahap 2 — markup harga jual dari modal toko (%)
                                <Input v-model.number="pricingForm.sell_markup_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1" />
                                <span v-if="pricingForm.errors.sell_markup_percent" role="alert" class="mt-1 block text-xs text-destructive">{{ pricingForm.errors.sell_markup_percent }}</span>
                            </label>
                            <div class="flex items-end">
                                <Button :disabled="pricingForm.processing">{{ pricingForm.processing ? 'Menyimpan…' : 'Simpan pengaturan harga' }}</Button>
                            </div>
                        </form>
                        <p class="text-xs text-muted-foreground">Contoh harga seller Rp10.000 → modal toko {{ formatAdminMoney(markupExample(10000, pricingForm.cost_uplift_percent, 0).stored) }} → harga jual {{ formatAdminMoney(markupExample(10000, pricingForm.cost_uplift_percent, pricingForm.sell_markup_percent).sell) }}. Modal toko yang sudah ada tidak diubah oleh perubahan tahap 1.</p>
                        <p v-if="pricingForm.recentlySuccessful" class="text-xs text-emerald-400">Pengaturan harga katalog tersimpan.</p>
                        <p v-if="Object.keys(pricingForm.errors).length" role="alert" class="text-xs text-destructive">{{ Object.values(pricingForm.errors).join(' · ') }}</p>
                    </Card>
                </div>
            </details>
            <section class="lf-admin-catalog-section space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-xl font-semibold">Produk</h2><p class="mt-1 text-sm text-muted-foreground">Kelola produk otomatis dan manual. Kode SKU penyedia hanya dikelola dari panel admin dan tidak ditampilkan kepada pelanggan.</p></div><div class="flex gap-2"><Button v-for="mode in ['AUTO_PROVIDER', 'MANUAL']" :key="mode" type="button" :variant="tab===mode?'default':'outline'" @click="tab = mode">{{ mode === 'MANUAL' ? 'Produk Manual' : 'Produk Otomatis' }}</Button></div></div>
                <Card class="p-4">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <label class="text-sm xl:col-span-2">Cari produk atau nominal<Input v-model="catalogSearch" placeholder="Nama, alamat produk, merek, atau nominal" class="mt-1" /></label>
                        <label class="text-sm">Kategori<select v-model="catalogCategory" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="">Semua kategori</option><option v-for="category in categories" :key="category.id" :value="category.id">{{category.name}}</option></select></label>
                        <label class="text-sm">Status<select v-model="catalogStatus" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="">Semua status</option><option value="ACTIVE">Aktif</option><option value="INACTIVE">Nonaktif</option></select></label>
                        <label class="text-sm">Urutkan<select v-model="catalogSort" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="CUSTOM">Urutan toko</option><option value="NAME">Nama A–Z</option></select></label>
                        <label class="text-sm">Produk per halaman<select v-model.number="catalogPageSize" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option v-for="size in [10,25,50,100]" :key="size" :value="size">{{size}} produk</option></select></label>
                    </div>
                    <div class="mt-3"><Button type="button" variant="outline" size="sm" @click="resetCatalogFilters">Hapus filter</Button></div>
                </Card>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" @click="showCreateProduct = !showCreateProduct">{{ showCreateProduct ? 'Tutup formulir' : 'Tambah produk' }}</Button>
                    <Button v-if="tab === 'AUTO_PROVIDER'" type="button" variant="outline" @click="showCatalogImport = !showCatalogImport">Impor Digiflazz</Button>
                </div>
                <Card v-if="showCatalogImport && tab === 'AUTO_PROVIDER'" class="space-y-3 p-4" data-testid="catalog-import-target">
                    <h3 class="font-semibold">Impor nominal Digiflazz</h3>
                    <p class="text-sm text-muted-foreground">Pilih produk tujuan, lalu pilih kode produk dari Digiflazz yang ingin ditambahkan. Jika produknya belum ada, buat lewat Tambah produk terlebih dahulu.</p>
                    <label class="block text-sm">Produk tujuan
                        <select v-model="importProductId" class="mt-1 block w-full rounded-md border p-2">
                            <option value="">Pilih produk tujuan</option>
                            <option v-for="product in importableProducts" :key="product.id" :value="product.id">{{ product.name }}</option>
                        </select>
                    </label>
                    <Button type="button" :disabled="!importProductId" @click="openProductImport">Lanjut pilih SKU</Button>
                </Card>
                <Sheet :open="showCreateProduct" @update:open="(value) => { showCreateProduct = value; }">
                <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-3xl">
                <SheetHeader><SheetTitle>Tambah produk</SheetTitle><SheetDescription>Isi data produk baru. Nominal bisa ditambahkan setelah produk dibuat.</SheetDescription></SheetHeader>
                <form class="grid gap-3 md:grid-cols-3" @submit.prevent="submitProduct">
                    <label class="text-sm">Kategori<select v-model="productForm.category_id" required class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="">Pilih kategori</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select><span v-if="productForm.errors.category_id" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.category_id }}</span></label>
                    <label class="text-sm">Nama produk<Input v-model="productForm.name" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.name" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.name }}</span></label>
                    <details class="text-sm"><summary class="cursor-pointer">Ubah tautan halaman produk (lanjutan)</summary><label class="mt-2 block">Bagian alamat halaman<Input v-model="productForm.slug" placeholder="Dibuat otomatis dari nama" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.slug" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.slug }}</span></label></details>
                    <label class="text-sm">Merek game (opsional)<Input v-model="productForm.publisher" placeholder="Contoh: Moonton" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.publisher" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.publisher }}</span></label>
                    <label v-if="tab !== 'MANUAL'" class="text-sm">Margin persen<Input v-model.number="productForm.margin_percent" type="number" step="0.0001" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.margin_percent" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.margin_percent }}</span></label>
                    <label v-else class="text-sm">Markup harga jual (%)<Input v-model.number="productForm.sell_markup_percent" type="number" step="0.0001" min="0" max="1000" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span class="mt-1 block text-xs text-slate-500">Harga jual nominal = modal toko × markup. Markup global sekarang {{ pricingSettings?.sell_markup_percent ?? 10 }}%; tiap baris di bawah boleh punya markup sendiri.</span><span v-if="productForm.errors.sell_markup_percent" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.sell_markup_percent }}</span></label>
                    <label class="text-sm md:col-span-2">Deskripsi<Textarea v-model="productForm.description" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.description" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.description }}</span></label>
                    <details class="text-sm"><summary class="cursor-pointer">Atur posisi produk (lanjutan)</summary><label class="mt-2 block">Posisi di toko<Input v-model.number="productForm.sort_order" type="number" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span class="mt-1 block text-xs text-muted-foreground">Angka lebih kecil tampil lebih dulu.</span><span v-if="productForm.errors.sort_order" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.sort_order }}</span></label></details>
                    <label v-if="tab === 'MANUAL'" class="text-sm md:col-span-3">Instruksi penanganan internal<Textarea v-model="productForm.manual_instructions" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.manual_instructions" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_instructions }}</span></label>
                    <template v-if="tab === 'MANUAL'">
                        <label class="text-sm">Jam buka<Input v-model="productForm.manual_open_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.manual_open_time" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_open_time }}</span></label>
                        <label class="text-sm">Jam tutup<Input v-model="productForm.manual_close_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.manual_close_time" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_close_time }}</span></label>
                        <label class="text-sm">Zona waktu<select v-model="productForm.manual_timezone" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select><span v-if="productForm.errors.manual_timezone" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_timezone }}</span></label>
                        <div class="space-y-2 rounded-md border border-slate-700 p-3 md:col-span-3" data-testid="manual-nominal-rows">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold">Nominal manual — bisa banyak baris sekaligus</p>
                                    <p class="text-xs text-slate-400">Modal toko yang Anda ketik dipakai apa adanya (tanpa tambahan modal impor). Harga jual = modal toko × markup yang berlaku. Nominal dibuat nonaktif dulu sampai Anda mengaktifkannya.</p>
                                </div>
                                <Button type="button" variant="outline" size="sm" @click="addManualRow">Tambah baris</Button>
                            </div>
                            <div v-for="(row, index) in manualRows" :key="index" data-testid="manual-nominal-row" class="grid items-end gap-2 border-t border-slate-800 pt-2 md:grid-cols-12">
                                <label class="text-xs md:col-span-3">Nama nominal<Input v-model="row.name" data-field="name" placeholder="Contoh: 100 Diamonds" class="mt-1" /><span v-if="productForm.errors['packages.'+index+'.name']" role="alert" class="mt-1 block text-[11px] text-red-300">{{ productForm.errors['packages.'+index+'.name'] }}</span></label>
                                <label class="text-xs md:col-span-2">Nilai nominal<Input v-model="row.nominal_value" data-field="nominal_value" type="number" min="0" placeholder="Opsional" class="mt-1" /></label>
                                <label class="text-xs md:col-span-2">Modal toko (Rp)<Input v-model="row.cost_idr" data-field="cost_idr" type="number" min="0" required class="mt-1" /><span v-if="productForm.errors['packages.'+index+'.cost_idr']" role="alert" class="mt-1 block text-[11px] text-red-300">{{ productForm.errors['packages.'+index+'.cost_idr'] }}</span></label>
                                <label class="text-xs md:col-span-2">Markup baris (%)<Input v-model="row.sell_markup_percent" data-field="sell_markup_percent" type="number" min="0" max="1000" step="0.0001" placeholder="Ikut produk" class="mt-1" /><span class="mt-1 block text-[11px] text-slate-500">Kosongkan untuk mengikuti markup produk.</span></label>
                                <p class="text-xs md:col-span-2" data-testid="manual-row-price">Harga jual <strong>{{ formatAdminMoney(createRowPreview(row).sell) }}</strong><span class="block text-slate-500">modal toko × {{ createRowMarkup(row) }}%</span></p>
                                <div class="md:col-span-1"><Button type="button" variant="outline" size="sm" @click="removeManualRow(index)">Hapus</Button></div>
                            </div>
                            <p v-if="productForm.errors.packages" role="alert" class="text-sm text-red-300">{{ productForm.errors.packages }}</p>
                        </div>
                    </template>
                    <div class="md:col-span-3"><Button :disabled="productForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah {{ tab === 'MANUAL' ? 'produk manual' : 'produk otomatis' }}</Button><p v-if="Object.keys(productForm.errors).length" class="mt-2 text-sm text-red-300">{{ Object.values(productForm.errors).join(' · ') }}</p></div>
                </form>
                </SheetContent>
                </Sheet>
                <div class="max-h-[68vh] overflow-auto rounded-md border max-md:max-h-none" role="region" aria-label="Daftar produk dalam katalog" tabindex="0"><Table class="min-w-[880px]"><TableHeader><TableRow><TableHead>Produk</TableHead><TableHead>Kategori</TableHead><TableHead>Jenis</TableHead><TableHead>Nominal</TableHead><TableHead>Urutan</TableHead><TableHead>Status</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="item in visibleProducts" :key="item.id" class="cursor-pointer" @click="editProduct(item)" @keydown.enter="editProduct(item)"><TableCell><strong>{{item.name}}</strong><small v-if="item.publisher" class="block text-muted-foreground">{{item.publisher}}</small></TableCell><TableCell>{{categories.find(c=>String(c.id)===String(item.category_id))?.name||'Tanpa kategori'}}</TableCell><TableCell>{{item.fulfillment_mode==='MANUAL'?'Manual':'Otomatis'}}</TableCell><TableCell>{{item.packages.length}}</TableCell><TableCell>Posisi {{ productPosition(item) }}</TableCell><TableCell>{{item.is_active ? 'Aktif' : 'Nonaktif'}}</TableCell><TableCell><Button type="button" variant="outline" size="sm" @click.stop="editProduct(item)">Edit</Button></TableCell></TableRow></TableBody></Table></div><p v-if="!visibleProducts.length" class="lf-admin-note">Tidak ada produk yang sesuai dengan filter.</p>
                <nav class="flex flex-wrap items-center justify-between gap-3"><span class="text-sm text-muted-foreground">{{matchingProducts.length}} produk · Halaman {{catalogPage}} dari {{totalCatalogPages}}</span><div class="flex gap-2"><Button type="button" variant="outline" :disabled="catalogPage <= 1" @click="catalogPage--">Sebelumnya</Button><Button type="button" variant="outline" :disabled="catalogPage >= totalCatalogPages" @click="catalogPage++">Berikutnya</Button></div></nav>

            </section>


            <Sheet :open="selectedProductId !== null" @update:open="(value) => { if (!value) closeProduct(); }">
            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-5xl">
            <SheetHeader><SheetTitle>Ubah produk</SheetTitle><SheetDescription>Ubah informasi, nominal, tampilan, dan data pelanggan tanpa meninggalkan daftar produk.</SheetDescription></SheetHeader>
            <section class="lf-admin-workspace" aria-label="Ubah produk">
                        <div v-for="item in selectedProductItems" :key="item.id" class="space-y-4">
                    <header class="flex flex-col gap-3 border-b pb-4 pr-8 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Editor Produk</p><h1 class="mt-1 break-words text-xl font-semibold">{{item.name}}</h1></div><div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"><Button class="w-full sm:w-auto" type="button" variant="destructive" size="sm" @click="deleteProduct(item)">Hapus</Button><Button class="w-full sm:w-auto" type="button" variant="outline" size="sm" @click="closeProduct">Kembali ke produk</Button></div></header>
                    <nav class="lf-admin-tabs" aria-label="Bagian editor produk"><Button type="button" variant="ghost" :aria-current="editorTab==='info' ? 'page' : undefined" :class="{active:editorTab==='info'}" @click="editorTab='info'">Informasi</Button><Button type="button" variant="ghost" :aria-current="editorTab==='nominal' ? 'page' : undefined" :class="{active:editorTab==='nominal'}" @click="editorTab='nominal'">Nominal & Harga</Button><Button type="button" variant="ghost" :aria-current="editorTab==='display' ? 'page' : undefined" :class="{active:editorTab==='display'}" @click="editorTab='display'">Tampilan Produk</Button><Button type="button" variant="ghost" :aria-current="editorTab==='fields' ? 'page' : undefined" :class="{active:editorTab==='fields'}" @click="fieldsProductId=String(item.id);editorTab='fields'">Data Pelanggan</Button><Button type="button" variant="ghost" :aria-current="editorTab==='fulfillment' ? 'page' : undefined" :class="{active:editorTab==='fulfillment'}" @click="editorTab='fulfillment'">Penanganan</Button></nav>
                    <div v-show="editorTab==='info'" class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-4">
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Alamat produk<Input v-model="item.slug" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Publisher / merek<Input v-model="item.publisher" placeholder="Publisher / merek game" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Kategori<select v-model="item.category_id" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                        <label class="text-sm">Jenis penanganan<select v-model="item.fulfillment_mode" :disabled="item.packages.length>0" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="AUTO_PROVIDER">Otomatis</option><option value="MANUAL">Manual</option></select><span v-if="item.packages.length>0" class="mt-1 block text-xs text-slate-500">Kosongkan nominal terlebih dahulu jika jenis penanganan memang perlu diubah.</span></label>
                        <label class="text-sm">Margin produk (%)<Input v-model.number="item.margin_percent" type="number" step="0.0001" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Markup jual game (%)<Input v-model.number="item.sell_markup_percent" type="number" step="0.0001" min="0" max="1000" placeholder="Ikut pengaturan global" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span class="mt-1 block text-xs text-slate-500">Dihitung dari modal toko. Kosongkan untuk mengikuti pengaturan global.</span></label>
                        <label class="text-sm">Tambahan modal game (%)<Input v-model.number="item.cost_uplift_percent" type="number" step="0.0001" min="0" max="1000" placeholder="Ikut pengaturan global" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span class="mt-1 block text-xs text-slate-500">Dipakai hanya saat modal nominal pertama kali dibuat; modal yang sudah ada tidak diubah. Kosongkan untuk ikut global.</span></label>
                        <details class="text-sm"><summary class="cursor-pointer">Atur posisi produk (lanjutan)</summary><label class="mt-2 block">Posisi di toko<Input v-model.number="item.sort_order" type="number" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span class="mt-1 block text-xs text-muted-foreground">Angka lebih kecil tampil lebih dulu.</span></label></details>
                        <label class="text-sm md:col-span-3">Deskripsi<Textarea v-model="item.description" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.is_active" /> Produk aktif</label>
                        <label v-if="item.fulfillment_mode === 'MANUAL'" class="text-sm md:col-span-4">Instruksi internal<Textarea v-model="item.manual_instructions" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <template v-if="item.fulfillment_mode === 'MANUAL'">
                            <label class="text-sm">Jam buka<Input v-model="item.manual_open_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                            <label class="text-sm">Jam tutup<Input v-model="item.manual_close_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                            <label class="text-sm">Zona waktu<select v-model="item.manual_timezone" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select></label>
                        </template>
                        <div class="space-y-3 rounded-md border border-slate-700 p-3 md:col-span-4">
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.nickname_check_enabled" /> Aktifkan cek nickname</label>
                            <div v-if="item.nickname_check_enabled" class="grid gap-3 md:grid-cols-3">
                                <label class="text-sm">
                                    Kode game
                                    <select v-model="item.nickname_game_code" class="mt-1 block h-10 w-full rounded-md border border-slate-700 bg-slate-800 px-3 text-sm">
                                        <option :value="null">Pilih kode game</option>
                                        <option v-for="code in nicknameGameCodes" :key="code.id" :value="code.code">
                                            {{ code.name }} · {{ code.code }}{{ code.requires_server ? ' · perlu Server / Zone' : '' }}
                                        </option>
                                    </select>
                                </label>
                                <label class="text-sm">Kolom User ID<select v-model="item.nickname_user_field_key" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Pilih field</option><option v-for="field in item.fields" :key="field.field_key" :value="field.field_key">{{ field.label }} ({{ field.field_key }})</option></select></label>
                                <label class="text-sm">Kolom Server / Zone<select v-model="item.nickname_server_field_key" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Tidak dipakai</option><option v-for="field in item.fields" :key="field.field_key" :value="field.field_key">{{ field.label }} ({{ field.field_key }})</option></select></label>
                            </div>
                            <p class="text-xs text-slate-500">Kredensial layanan cek nickname tetap dikelola di Integrasi. Pelanggan tidak melihat nama penyedia.</p>
                        </div>
                    </div>

                    </div>
                    <div v-if="editorTab==='fields'" class="space-y-3"><h3>Data Pelanggan</h3><p class="text-muted-foreground">Atur data akun yang diminta saat pelanggan membeli produk ini. Urutan data yang kamu tambahkan berlaku untuk semua nominal produk ini.</p><div v-if="item.fulfillment_mode==='AUTO_PROVIDER'" class="space-y-2"><p class="text-sm">Pilih data top up sesuai urutan yang diminta penyedia. Bila perlu dua data, tambahkan keduanya berurutan:</p><div class="flex flex-wrap gap-2"><Button v-for="field in item.fields" :key="field.field_key" type="button" variant="outline" size="sm" @click="item.customer_no_template=(item.customer_no_template||'')+'{{'+field.field_key+'}}'">Tambah {{field.label}}</Button></div><details class="text-sm"><summary class="cursor-pointer">Lihat format teknis penyedia (lanjutan)</summary><label class="mt-2 block max-w-xl">Urutan data yang dikirim ke penyedia<Input v-model="item.customer_no_template" data-testid="product-customer-template" class="mt-1" placeholder="Pilih data di atas sesuai urutan"/><span class="mt-1 block text-xs text-muted-foreground">Format ini menggabungkan data yang dipilih tanpa pemisah. Ubah hanya bila diminta oleh penyedia.</span></label></details><Button type="button" :disabled="fieldsSaving" @click="saveFields">Simpan urutan data</Button></div><AdminCustomerFields :rows="fieldRows" :error="fieldsError" :saving="fieldsSaving" @move="moveField" @remove="index=>fieldRows.splice(index,1)" @add="addField" @save="saveFields" /></div>
                    <div v-show="editorTab==='fulfillment'" class="space-y-4"><h3 class="font-semibold">Penanganan {{item.fulfillment_mode==='MANUAL'?'manual':'otomatis'}}</h3><p class="text-sm text-slate-500">Instruksi dan jam layanan dikelola pada Informasi. Format tujuan bersama diatur pada Data Pelanggan; sumber, prioritas, dan modal tetap dikelola per nominal.</p><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" title="Menyinkronkan seluruh katalog Digiflazz, bukan hanya produk ini." @click="router.post('/admin/catalog/products/'+item.id+'/sync',{}, {preserveScroll:true})">Sinkron seluruh katalog</Button><AdminResponsiveTable scroll class="min-w-[900px]" :mobile-columns="[0,2,3]"><TableHeader><TableRow><TableHead>Nominal</TableHead><TableHead>Sumber / kode</TableHead><TableHead>Status koneksi</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="pack in visiblePackages(item)" :key="pack.id"><TableCell>{{pack.name}}</TableCell><TableCell><p v-for="mapping in pack.mappings" :key="mapping.id">{{providerLabel(mapping)}} · {{mapping.provider_code==='VOUCHER_STOCK' ? (mapping.stock_key||'-') : (mapping.external_sku||'Manual')}}</p></TableCell><TableCell><p v-for="mapping in pack.mappings" :key="mapping.id">{{ fulfillmentRole(pack,mapping) }}<span v-if="mapping.is_active"> · Posisi {{ sourcePosition(pack,mapping) }}</span></p></TableCell><TableCell><Button type="button" variant="outline" @click="selectedPackageId=pack.id;editorTab='nominal'">Kelola sumber</Button></TableCell></TableRow></TableBody></AdminResponsiveTable></div>
                    <div v-show="editorTab==='display'" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                        <div><h3 class="font-semibold">Produk populer</h3><p class="text-sm text-muted-foreground">Produk bertanda populer didahulukan di homepage; pengaturan status produk tetap di Informasi.</p></div>
                        <Button type="button" :variant="item.popular ? 'outline' : 'default'" @click="togglePopular(item)">{{ item.popular ? 'Lepas prioritas' : 'Jadikan populer' }}</Button>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-md border border-slate-200 p-3"><strong class="text-xs">Gambar produk / card</strong><div class="mt-2"><AdminMediaControl type="product" :id="item.id" :url="item.image_url" /></div></div>
                        <div class="rounded-md border border-slate-200 p-3"><strong class="text-xs">Banner halaman produk</strong><div class="mt-2"><AdminMediaControl type="product" :id="item.id" collection="banner" :url="item.banner_url" /></div></div>
                    </div>
                    <Card class="space-y-3 p-4">
                        <div><h3 class="font-semibold">Tampilan tanpa gambar & tab nominal</h3><p class="mt-1 text-xs text-muted-foreground">Pengaturan ini langsung dipakai pada halaman pelanggan, bukan sekadar catatan admin.</p></div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="text-sm">Inisial cadangan<Input v-model="item.initials" maxlength="4" placeholder="Contoh: ML" class="mt-1" /></label>
                            <label class="text-sm">Warna aksen cadangan<Input v-model="item.accent_color" maxlength="7" placeholder="#1769e8" class="mt-1" /></label>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.instant" /> Tampilkan label Instan</label>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.package_tabs_enabled" /> Gunakan tab untuk grup nominal</label>
                            <label v-if="item.package_tabs_enabled" class="text-sm md:col-span-2">Urutan tab nominal<Input :model-value="(item.package_tabs||[]).join(', ')" placeholder="Contoh: Diamonds, Weekly Pass" class="mt-1" @change="item.package_tabs=String($event.target.value).split(',').map(v=>v.trim()).filter(Boolean)" /><span class="mt-1 block text-xs text-muted-foreground">Pisahkan dengan koma. Nama harus sama dengan Grup / Tabel pada nominal.</span></label>
                        </div>

                    </Card>
                    <div class="space-y-3 rounded-md border border-border bg-card p-4">
                        <div><h3 class="font-semibold">Pemberitahuan produk</h3><p class="mt-1 text-xs text-muted-foreground">Informasi publik yang tampil saat pelanggan melakukan pembelian, terpisah dari instruksi penanganan internal.</p></div>
                        <article v-for="notice in item.notices" :key="notice.id" class="grid gap-2 rounded border border-border p-3 md:grid-cols-[1fr_110px_auto]">
                            <label class="lf-admin-field "><span>Judul</span><Input v-model="notice.title" class="rounded border border-border bg-background p-2 text-xs" placeholder="Judul" /></label>
                            <label class="lf-admin-field "><span>Urutan</span><Input v-model.number="notice.sort_order" type="number" min="0" class="rounded border border-border bg-background p-2 text-xs" placeholder="Urutan" /></label>
                            <label class="flex items-center gap-2 text-xs"><AdminSwitch v-model="notice.is_active" /> Aktif</label>
                            <label class="lf-admin-field md:col-span-3"><span>Isi</span><Textarea v-model="notice.body" rows="2" class="rounded border border-border bg-background p-2 text-xs" placeholder="Isi pemberitahuan"></Textarea></label>
                            <div class="md:col-span-3 rounded-md border border-border bg-muted/40 p-3" aria-label="Pratinjau pemberitahuan">
                                <p class="text-xs font-semibold">{{ notice.title || 'Judul pemberitahuan' }}</p>
                                <p class="mt-1 whitespace-pre-wrap text-xs text-muted-foreground">{{ notice.body || 'Isi pemberitahuan akan tampil di sini.' }}</p>
                            </div>
                            <div class="flex gap-2 md:col-span-3"><Button type="button" size="sm" @click="saveNotice(notice)">Simpan</Button><Button type="button" size="sm" variant="destructive" @click="deleteNotice(notice)">Hapus</Button></div>
                        </article>
                        <div class="grid gap-2 rounded border border-dashed border-border p-3 md:grid-cols-[1fr_110px_auto]">
                            <label class="lf-admin-field "><span>Judul</span><Input v-model="noticeDraft(item.id).title" class="rounded border border-border bg-background p-2 text-xs" placeholder="Judul notice baru" /></label>
                            <label class="lf-admin-field "><span>Urutan</span><Input v-model.number="noticeDraft(item.id).sort_order" type="number" min="0" class="rounded border border-border bg-background p-2 text-xs" placeholder="Urutan" /></label>
                            <label class="flex items-center gap-2 text-xs"><AdminSwitch v-model="noticeDraft(item.id).is_active" /> Aktif</label>
                            <label class="lf-admin-field md:col-span-3"><span>Isi</span><Textarea v-model="noticeDraft(item.id).body" rows="2" class="rounded border border-border bg-background p-2 text-xs" placeholder="Informasi yang dilihat pelanggan"></Textarea></label>
                            <Button type="button" size="sm" class="w-fit md:col-span-3" @click="addNotice(item)">Tambah pemberitahuan</Button>
                        </div>
                    </div>

                    </div>
                    <div v-show="editorTab==='nominal'" class="lf-admin-nominals space-y-3 rounded-md bg-slate-950 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold">Nominal / paket</h3><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" @click="toggleImport(item)">Impor nominal Digiflazz</Button></div>
                        <Card v-if="showImport && item.fulfillment_mode==='AUTO_PROVIDER'" class="space-y-3 p-4" data-testid="digiflazz-import">
                            <h4 class="font-semibold">Pilih kode produk Digiflazz untuk {{item.name}}</h4>
                            <div class="grid gap-3 md:grid-cols-3"><label class="text-sm">Cari kode produk<Input v-model="importSearch" class="mt-1"/></label><label class="text-sm">Merek<select v-model="importBrand" class="mt-1 block w-full rounded border p-2"><option value="">Semua brand</option><option v-for="brand in importBrands" :key="brand">{{brand}}</option></select></label><p class="self-end text-sm font-semibold text-emerald-700">Modal toko × markup</p></div>
                            <p class="text-xs text-slate-500">Pilih SKU yang tersedia, lalu impor. Harga jual dihitung dari modal toko × markup; keduanya bisa diubah setelah impor.</p>
                            <label class="block text-sm">Markup harga jual game (%)<Input v-model.number="importForm.margin_percent" type="number" min="0" max="1000" step="0.0001" :disabled="item.sell_markup_percent !== null && item.sell_markup_percent !== undefined" class="mt-1" /><span class="mt-1 block text-xs text-slate-500">{{ item.sell_markup_percent !== null && item.sell_markup_percent !== undefined ? 'Memakai markup game yang sudah tersimpan. Ubah di Informasi jika ingin mengganti harga jual seluruh nominal.' : 'Menjadi markup jual game ini, dihitung dari modal toko. Tahap tambahan modal tetap memakai pengaturan game/global.' }}</span></label>
                            <p class="text-sm text-muted-foreground">Pilih satu SKU Digiflazz untuk setiap nominal. Katalog tidak akan menambahkan SKU alternatif otomatis.</p>
                            <div class="flex flex-wrap gap-2"><Button type="button" variant="outline" @click="importForm.item_ids=importMatches.map(sku=>sku.id)">Pilih semua hasil filter ({{ importMatches.length }})</Button><Button type="button" variant="outline" @click="importForm.item_ids=[]">Hapus pilihan</Button></div>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="importForm.publish" /> Langsung jual setelah impor</label>
                            <p class="text-sm text-muted-foreground">{{ importForm.publish ? 'Produk dan SKU nominal yang dipilih diaktifkan. Harga jual = modal toko × markup; batas harga supplier mengikuti modal toko saat impor.' : 'Nominal disimpan sebagai draf dan belum dijual.' }}</p>
                            <label v-for="sku in importItems" :key="sku.id" :data-sku="sku.buyer_sku_code" class="flex items-start gap-3 rounded border p-2 text-sm"><input v-model="importForm.item_ids" type="checkbox" :value="sku.id" class="mt-1"><span class="min-w-0">{{sku.product_name}}<small class="block">{{sku.buyer_sku_code}} · seller Rp{{Number(sku.price_idr).toLocaleString('id-ID')}}</small><small class="block text-slate-400">Modal toko {{formatAdminMoney(importRowPreview(sku,item).stored)}} → harga jual {{formatAdminMoney(importRowPreview(sku,item).sell)}}</small><small v-if="nominalAlreadyExists(sku,item)" class="mt-1 block font-semibold text-amber-400">Nominal serupa sudah ada di produk ini</small></span></label>
                            <p v-if="importForm.item_ids.length" class="text-sm"><strong>{{importForm.item_ids.length}} dipilih</strong> dari {{importMatches.length}} hasil filter · Total seller {{formatAdminMoney(importTotals().seller)}} · Modal toko {{formatAdminMoney(importTotals().stored)}} · Harga jual {{formatAdminMoney(importTotals().sell)}}</p>
                            <p v-if="duplicateImportRows(item).length" role="status" class="text-sm text-amber-400">{{duplicateImportRows(item).length}} pilihan bertabrakan dengan nominal yang sudah ada. Kamu akan diminta konfirmasi sebelum menyimpan.</p>
                            <p v-if="importUnavailableCount" class="text-xs text-slate-500">{{importUnavailableCount}} SKU pada merek ini sedang tidak tersedia (stok habis, cutoff, atau harga di atas batas) sehingga tidak bisa dipilih.</p>
                            <p v-if="!importMatches.length" class="text-sm">Tidak ada SKU tersedia yang sesuai dengan filter dan belum diimpor. Hapus filter atau sinkronkan daftar harga di menu Digiflazz.</p>
                            <div class="flex flex-wrap items-center gap-2"><Button type="button" variant="outline" :disabled="importPage===1" @click="importPage--">Sebelumnya</Button><span class="text-sm">Halaman {{importPage}} / {{Math.max(1,Math.ceil(importMatches.length/25))}}</span><Button type="button" variant="outline" :disabled="importPage*25>=importMatches.length" @click="importPage++">Berikutnya</Button></div>
                            <p v-if="Object.keys(importForm.errors).length" role="alert" class="text-sm text-destructive">{{ Object.values(importForm.errors).join(' · ') }}</p>
                            <p v-if="!importForm.item_ids.length" class="text-sm text-muted-foreground">Centang minimal satu SKU untuk mengaktifkan tombol impor.</p>
                            <Button type="button" :disabled="importForm.processing||!importForm.item_ids.length" @click="submitImport(item)">Impor {{importForm.item_ids.length}} nominal</Button>
                        </Card>
                <AdminResponsiveTable scroll class="min-w-[1100px]" :mobile-columns="[0,2,5,6]"><TableHeader><TableRow><TableHead>Nominal</TableHead><TableHead>Sumber / Modal</TableHead><TableHead class="text-right">Harga jual</TableHead><TableHead>Margin</TableHead><TableHead class="text-right">Urutan</TableHead><TableHead>Status</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody>
                            <TableRow v-for="(pack,index) in visiblePackages(item)" :key="pack.id" draggable="true" @dragstart="draggedPackageId=pack.id" @dragover.prevent @drop.prevent="dropPackage(item,(nominalPage-1)*25+index)">
                                <TableCell><div class="flex items-center gap-2"><img v-if="pack.image_url" :src="pack.image_url" alt="" class="size-8 shrink-0 object-contain"><strong>{{pack.name}}</strong></div><p class="text-xs text-slate-500">{{pack.group_name||'-'}}</p></TableCell>
                                <TableCell><p v-for="mapping in pack.mappings" :key="mapping.id" class="text-xs">{{ providerLabel(mapping) }}<span v-if="mapping.provider_code==='VOUCHER_STOCK'"> · {{ mapping.stock_key || '-' }}</span><span v-else-if="mapping.external_sku"> · {{ mapping.external_sku }}</span> · Rp{{mappingCost(mapping).toLocaleString('id-ID')}}</p></TableCell>
                                <TableCell class="text-right">{{previewPrice(pack)}}</TableCell><TableCell><template v-if="pack.mappings.some(mapping=>mapping.provider_code==='DIGIFLAZZ'&&mapping.store_cost_idr!==null&&mapping.store_cost_idr!==undefined)">Markup {{effectiveMarkupPercent(pack,item)}}%</template><template v-else>{{pack.pricing_mode==='SELL_PRICE'?'Harga tetap':pack.pricing_mode==='FIXED'?'Rp'+Number(pack.margin_fixed_idr||0).toLocaleString('id-ID'):(pack.pricing_mode==='PERCENT'?pack.margin_percent:item.margin_percent)+'%'}}</template></TableCell><TableCell class="text-right">Posisi {{ packagePosition(item, pack) }}</TableCell><TableCell>{{pack.is_active?'Aktif':'Nonaktif'}}</TableCell>
                                <TableCell><div class="flex flex-wrap gap-2"><Button type="button" variant="outline" @click="editPackage(pack.id)">Ubah nominal</Button></div></TableCell>
                            </TableRow>
                        </TableBody></AdminResponsiveTable>
                        <nav v-if="item.packages.length>25" class="flex flex-wrap items-center justify-between gap-2" aria-label="Halaman nominal"><span>{{item.packages.length}} nominal · Halaman {{nominalPage}} / {{Math.ceil(item.packages.length/25)}}</span><div class="flex gap-2"><Button type="button" variant="outline" :disabled="nominalPage<=1" @click="nominalPage--">Sebelumnya</Button><Button type="button" variant="outline" :disabled="nominalPage*25>=item.packages.length" @click="nominalPage++">Berikutnya</Button></div></nav>
                        <p v-if="!item.packages.length" class="text-sm text-slate-500">Belum ada nominal. Tambahkan manual atau impor dari Digiflazz.</p>
                        <div v-for="pack in item.packages.filter(pack=>pack.id===selectedPackageId)" :key="pack.id" :id="'nominal-'+pack.id" class="space-y-2 border-t border-slate-800 pt-3" draggable="true" @dragstart="draggedPackageId=pack.id" @dragover.prevent @drop.prevent="dropPackage(item,item.packages.findIndex(p=>p.id===pack.id))">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="flex gap-2"><Button type="button" variant="outline" :disabled="item.packages.findIndex(p=>p.id===pack.id)===0" @click="reorderPackage(item,item.packages.findIndex(p=>p.id===pack.id),-1)">Naik</Button><Button type="button" variant="outline" :disabled="item.packages.findIndex(p=>p.id===pack.id)===item.packages.length-1" @click="reorderPackage(item,item.packages.findIndex(p=>p.id===pack.id),1)">Turun</Button></div>
                                <Button type="button" variant="outline" @click="selectedPackageId=null">Tutup editor nominal</Button><Button v-if="item.fulfillment_mode==='MANUAL'" type="button" variant="outline" @click="duplicatePackage(item,pack)">Salin nominal</Button><Button type="button" variant="destructive" @click="deletePackage(pack)">Hapus nominal</Button>
                                <label class="text-xs">Kode internal<Input v-model="pack.code" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Nama nominal<Input v-model="pack.name" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Badge<Input v-model="pack.note" maxlength="80" placeholder="Contoh: Populer" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Grup / Tabel<select v-if="item.package_tabs_enabled" v-model="pack.group_name" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Pilih tab</option><option v-for="tabName in item.package_tabs||[]" :key="tabName" :value="tabName">{{tabName}}</option></select><Input v-else v-model="pack.group_name" placeholder="Contoh: Diamonds" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Nilai nominal<Input v-model.number="pack.nominal_value" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <details class="text-xs"><summary class="cursor-pointer">Atur posisi nominal (lanjutan)</summary><label class="block mt-2">Posisi di toko<Input v-model.number="pack.sort_order" type="number" min="0" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label><p class="mt-1 text-slate-500">Angka lebih kecil tampil lebih dulu.</p></details>
                                <label class="flex gap-2 text-xs"><AdminSwitch v-model="pack.is_active" />Aktif</label>
                                <Button type="button" class="rounded bg-slate-700 px-3 py-2 text-xs" @click="savePackage(pack)">Simpan</Button>
                            </div>
                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="text-xs">Mode harga<select v-model="pack.pricing_mode" class="mt-1 block w-full rounded border p-2"><option value="PRODUCT_MARGIN">Ikuti margin produk</option><option value="PERCENT">Margin persen nominal</option><option value="FIXED">Margin rupiah nominal</option><option value="SELL_PRICE">Harga jual tetap</option></select></label>
                                <label v-if="pack.pricing_mode==='PERCENT'" class="text-xs">Margin %<Input v-model.number="pack.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/></label>
                                <label v-if="pack.pricing_mode==='FIXED'" class="text-xs">Margin Rp<Input v-model.number="pack.margin_fixed_idr" type="number" min="0" class="mt-1"/></label>
                                <label v-if="pack.pricing_mode==='SELL_PRICE'" class="text-xs">Harga jual Rp<Input v-model.number="pack.sell_price_idr" type="number" min="1" class="mt-1"/></label>
                                <p class="self-center text-sm">Harga checkout tersimpan: <strong>{{previewPrice(pack)}}</strong></p>
                            </div>
                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="text-xs">Markup jual nominal (%)<Input v-model.number="pack.sell_markup_percent" type="number" min="0" max="1000" step="0.0001" placeholder="Ikut game" class="mt-1" /><span class="mt-1 block text-[11px] text-slate-500">Yang berlaku sekarang: {{ effectiveMarkupPercent(pack,item) }}% dari modal toko.</span></label>
                                <label class="text-xs">Tambahan modal nominal (%)<Input v-model.number="pack.cost_uplift_percent" type="number" min="0" max="1000" step="0.0001" placeholder="Ikut game" class="mt-1" /><span class="mt-1 block text-[11px] text-slate-500">Yang berlaku sekarang: {{ effectiveUpliftPercent(pack,item) }}% · hanya saat modal nominal dibuat.</span></label>
                            </div>
                            <div class="rounded border border-slate-700 p-2">
                                <p class="mb-2 text-[11px] text-slate-400">Gambar nominal: rekomendasi 512×512 (1:1), PNG/WebP transparan bila memungkinkan.</p>
                                <AdminMediaControl type="package" :id="pack.id" :url="pack.image_url" />
                            </div>
                            <div class="space-y-2 rounded-md border border-slate-800 p-3">
                                <div><h4 class="text-sm font-semibold">Mapping fulfillment</h4><p class="mt-1 text-[11px] text-slate-500">Setiap nominal Digiflazz memakai satu SKU. Jika transaksi gagal, sistem tidak berpindah otomatis ke SKU lain.</p></div>
                                <div v-for="mapping in [...pack.mappings.filter((entry) => entry.provider_code !== 'VOUCHER_STOCK')].sort(bySourcePriority)" :key="mapping.id" class="flex flex-wrap items-end gap-2 rounded border border-slate-800 p-2 text-xs text-slate-300">
                                    <span class="min-w-40"><strong>{{ fulfillmentRole(pack, mapping) }}<template v-if="mapping.is_active"> · Posisi {{ sourcePosition(pack,mapping) }}</template></strong><br>{{ providerLabel(mapping) }}<span v-if="mapping.external_sku"> · {{ mapping.external_sku }}</span><br><span class="text-slate-500"><template v-if="mapping.store_cost_idr!==null&&mapping.store_cost_idr!==undefined">Modal toko Rp{{Number(mapping.store_cost_idr||0).toLocaleString('id-ID')}} · Harga seller Rp{{Number(mapping.seller_reference_price_idr??mapping.cost_idr??0).toLocaleString('id-ID')}}</template><template v-else>Modal Rp{{Number(mapping.cost_idr||0).toLocaleString('id-ID')}}</template><template v-if="mapping.is_manual_price"> · Terkunci</template></span></span>
                                    <Button v-if="mapping.provider_code==='DIGIFLAZZ'" type="button" variant="outline" @click="router.post('/admin/catalog/mappings/'+mapping.id+'/sync',{}, {preserveScroll:true})">Sinkron harga</Button>
                                    <template v-if="mapping.provider_code === 'MANUAL'"><label>Harga manual Rp<Input v-model.number="mapping.cost_idr" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label><label>Modal toko Rp<Input v-model.number="mapping.store_cost_idr" type="number" min="0" max="1000000000" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label></template>
                                    <label v-if="mapping.provider_code === 'DIGIFLAZZ' && !mapping.is_manual_price && mapping.store_cost_idr!==null && mapping.store_cost_idr!==undefined">Modal toko / batas harga (Rp)<Input v-model.number="mapping.store_cost_idr" type="number" min="1" max="1000000000" class="mt-1 block w-36 rounded bg-slate-800 p-2" /></label>
                                    <div v-if="mapping.provider_code === 'DIGIFLAZZ'" class="space-y-1">
                                        <label class="flex items-center gap-2"><AdminSwitch v-model="mapping.is_manual_price" /> Kunci modal & batas harga manual</label>
                                        <label v-if="mapping.is_manual_price" class="block">Harga Modal = Max Price Digiflazz (Rp)<Input v-model.number="mapping.manual_price_idr" type="number" min="1" max="1000000000" required class="mt-1 block w-36 rounded bg-slate-800 p-2" /></label>
                                        <p class="max-w-64 text-[11px] text-slate-500">Modal yang lu isi akan sama persis dengan max_price Digiflazz dan tidak ditimpa sinkronisasi. Matikan kunci jika mau mengikuti harga supplier.</p>
                                    </div>
                                    <label class="flex gap-2"><AdminSwitch v-model="mapping.is_active" />Aktif</label>
                                    <Button type="button" class="rounded bg-slate-700 px-3 py-2" @click="saveMapping(mapping)">Simpan sumber</Button>
                                </div>
                            </div>
                            <div v-if="item.fulfillment_mode==='AUTO_PROVIDER'" class="space-y-3 rounded-md border border-slate-700 p-3">
                                <div>
                                    <h4 class="font-semibold">Stok Kode Digital</h4>
                                    <p class="mt-1 text-xs text-slate-400">Untuk voucher, lisensi, atau serial yang dikirim otomatis dari stok internal. Kode disimpan terenkripsi dan baru ditampilkan setelah pesanan sukses.</p>
                                </div>
                                <div class="grid gap-3 md:grid-cols-4">
                                    <label class="text-xs">Kunci stok<Input v-model="pack.stock_form.stock_key" maxlength="100" placeholder="contoh: netflix-1bulan" class="mt-1" /></label>
                                    <label class="text-xs">Modal Rp<Input v-model.number="pack.stock_form.cost_idr" type="number" min="1" class="mt-1" /></label>
                                    <details class="text-xs"><summary class="cursor-pointer">Prioritas kode digital (lanjutan)</summary><label class="block mt-2">Skor internal<Input v-model.number="pack.stock_form.priority" type="number" min="0" max="1000" class="mt-1" /></label></details>
                                    <label class="flex items-center gap-2 self-end pb-2 text-xs"><AdminSwitch v-model="pack.stock_form.is_active" /> Aktif untuk checkout</label>
                                </div>
                                <div v-if="stockMapping(pack)?.stock_counts" class="flex flex-wrap gap-4 text-xs text-slate-300">
                                    <span>Tersedia <strong>{{ stockMapping(pack).stock_counts.available }}</strong></span>
                                    <span>Dipesan <strong>{{ stockMapping(pack).stock_counts.reserved }}</strong></span>
                                    <span>Terkirim <strong>{{ stockMapping(pack).stock_counts.delivered }}</strong></span>
                                    <span>Total <strong>{{ stockMapping(pack).stock_counts.total }}</strong></span>
                                </div>
                                <label class="block text-xs">Impor kode baru (satu kode per baris)<Textarea v-model="pack.stock_form.codes_text" rows="5" class="mt-1" placeholder="KODE-001&#10;KODE-002&#10;KODE-003" /></label>
                                <Button type="button" variant="outline" @click="saveVoucherStock(pack)">Simpan pengaturan & impor kode</Button>
                            </div>
                        </div>
                        <Button type="button" variant="outline" @click="showCreatePackage=!showCreatePackage">{{showCreatePackage?'Tutup formulir':'Tambah nominal'}}</Button>
                        <form v-if="showCreatePackage" class="flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3" @submit.prevent="packageForm.post('/admin/catalog/products/' + item.id + '/packages', { onSuccess: () => packageForm.reset() })">
                            <label class="text-xs">Kode internal<Input v-model="packageForm.code" required class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Nama nominal<Input v-model="packageForm.name" required class="mt-1 block rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Badge<Input v-model="packageForm.note" maxlength="80" placeholder="Contoh: Populer" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Grup / Tabel<select v-if="item.package_tabs_enabled" v-model="packageForm.group_name" required class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Pilih tab</option><option v-for="tabName in item.package_tabs||[]" :key="tabName" :value="tabName">{{tabName}}</option></select><Input v-else v-model="packageForm.group_name" placeholder="Contoh: Weekly / Diamonds" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Nilai nominal<Input v-model.number="packageForm.nominal_value" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <details class="text-xs"><summary class="cursor-pointer">Urutan nominal (lanjutan)</summary><label class="mt-2 block">Skor teknis<Input v-model.number="packageForm.sort_order" type="number" min="0" required class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label></details>
                            <label v-if="item.fulfillment_mode === 'MANUAL'" class="text-xs">Modal Rp<Input v-model.number="packageForm.cost_idr" type="number" min="0" required class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <Button :disabled="packageForm.processing" class="rounded bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950">Tambah nominal</Button>
                            <span v-if="Object.keys(packageForm.errors).length" class="text-xs text-red-300">{{ Object.values(packageForm.errors).join(' · ') }}</span>
                        </form>
                    </div>
                    <div class="lf-admin-workspace-actions"><Button type="button" variant="outline" @click="closeProduct">Kembali</Button><Button v-if="editorTab==='info'||editorTab==='display'" type="button" :disabled="savingProduct" @click="saveProduct(item)">{{savingProduct?'Menyimpan…':'Simpan produk'}}</Button><Button v-if="editorTab==='fields'" type="button" :disabled="fieldsSaving" @click="saveFields">{{fieldsSaving?'Menyimpan…':'Simpan data pelanggan'}}</Button></div>
                        </div>
            </section>
            </SheetContent>
            </Sheet>

        </div>
        <AdminConfirmDialog
            :open="Boolean(pendingAction)"
            :title="pendingAction?.title || ''"
            :message="pendingAction?.message || ''"
            :confirm-label="pendingAction?.confirmLabel || 'Lanjutkan'"
            :destructive="Boolean(pendingAction?.destructive)"
            @update:open="value => { if (!value) pendingAction = null; }"
            @confirm="runPending"
        />
    </AdminShell>
</template>
