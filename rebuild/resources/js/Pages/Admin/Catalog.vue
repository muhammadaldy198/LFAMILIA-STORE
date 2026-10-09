<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Card } from '../../Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import AdminCustomerFields from '../../Components/AdminCustomerFields.vue';
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';
import { byOrder, bySourcePriority, displayPosition } from '../../lib/ordering.js';

const props = defineProps({
    categories: Array,
    products: Array,
    digiflazzItems: { type: Array, default: () => [] },
    nicknameGameCodes: { type: Array, default: () => [] },
    defaultMargin: Number,
});
const cloneProducts = (items) => items.map((item) => ({
    ...item,
    packages: item.packages.map((pack) => {
        const mappings = pack.mappings.map((mapping) => ({ ...mapping }));
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
const categoryPosition = (row) => displayPosition(categories.value, row, byOrder());
const products = ref(cloneProducts(props.products));
const productPosition = (row) => displayPosition(
    products.value.filter((item) => item.fulfillment_mode === row.fulfillment_mode), row,
    (a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0)
        || a.name.localeCompare(b.name, 'id-ID', { numeric: true })
        || Number(a.id) - Number(b.id),
);
const packagePosition = (product, pack) => displayPosition(product.packages, pack, byOrder());
const sourcePosition = (pack, mapping) => displayPosition(
    pack.mappings.filter((entry) => entry.is_active && entry.provider_code === mapping.provider_code),
    mapping, bySourcePriority,
);
watch(() => props.categories, (items) => { categories.value = items.map((item) => ({ ...item })); });
watch(() => props.products, (items) => { products.value = cloneProducts(items); });

const page = usePage();
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
const catalogTab = ref('products'), catalogSearch = ref(initialSearch), catalogPage = ref(1);
const catalogCategory = ref(''), catalogStatus = ref(''), catalogSort = ref('CUSTOM'), catalogPageSize = ref(25);
const catalogTabs = [['products','Produk'],['categories','Kategori'],['fields','Kolom Data Pelanggan']];
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
const categoryIconOptions=[['gamepad','Game'],['ticket','Voucher'],['play','Entertainment'],['smartphone','Pulsa / Data'],['zap','PLN / Listrik'],['grid','Umum']];
const categoryForm = useForm({ name: '', slug: '', icon: 'grid', sort_order: categories.value.length });
const productForm = useForm({ category_id: '', name: '', slug: '', publisher: '', description: '', fulfillment_mode: 'AUTO_PROVIDER', manual_instructions: '', manual_open_time: '', manual_close_time: '', manual_timezone: 'Asia/Jakarta', margin_percent: props.defaultMargin||0, sort_order: Math.max(-1, ...products.value.map((item) => Number(item.sort_order ?? 0))) + 1 });
const globalMarginForm = useForm({margin_percent:props.defaultMargin||0});
const applyGlobalMargin=()=>{if(confirm('Terapkan margin global ke semua produk otomatis? Produk manual dan margin khusus nominal tidak akan diubah.'))globalMarginForm.put('/admin/catalog/margin',{preserveScroll:true});};
const autoSourcesForm = useForm({});
const importForm = useForm({item_ids:[],margin_percent:props.defaultMargin||0,publish:true,auto_sources:true,customer_no_template:''});
const suggestedTemplate = item => {
    const fields = item.fields || [];
    const previous = item.packages.flatMap(pack => pack.mappings).find(mapping => mapping.provider_code === 'DIGIFLAZZ' && mapping.customer_no_template);
    if (previous) return previous.customer_no_template;
    if (fields.length === 1) return '{{' + fields[0].field_key + '}}';
    if (item.slug === 'mobile-legends') {
        const keys = fields.map(field => field.field_key);
        const user = ['user_id', 'destination'].find(key => keys.includes(key));
        const server = ['zone_id', 'server'].find(key => keys.includes(key));
        if (user && server) return '{{' + user + '}}{{' + server + '}}';
    }
    return '';
};
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
    importForm.auto_sources = true;
    importForm.customer_no_template = suggestedTemplate(item);
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
        importForm.auto_sources = true;
        importForm.customer_no_template = suggestedTemplate(item);
        importForm.margin_percent = Number(item.margin_percent || 0);
        importBrand.value = props.digiflazzItems.find(sku=>sku.brand.toLowerCase()===item.name.toLowerCase())?.brand || '';
    }
}

const importBrands=computed(()=>[...new Set(props.digiflazzItems.map(i=>i.brand))].sort());
const importMatches=computed(()=>{
    const matches=props.digiflazzItems.filter(i=>!i.mapped && i.available && (!importBrand.value||i.brand===importBrand.value) && (!importSearch.value||[i.product_name,i.buyer_sku_code].join(' ').toLowerCase().includes(importSearch.value.toLowerCase())));
    const groups=new Map();
    for(const item of matches){
        const key=importForm.auto_sources ? item.source_group : item.buyer_sku_code;
        const current=groups.get(key);
        if(!current || Number(item.price_idr)<Number(current.price_idr))groups.set(key,item);
    }
    return [...groups.values()].map(item=>({...item,source_count:props.digiflazzItems.filter(source=>source.source_group===item.source_group && source.available).length}))
        .sort((a,b)=>(a.nominal_value??Infinity)-(b.nominal_value??Infinity)||a.product_name.localeCompare(b.product_name,'id-ID',{numeric:true}));
});
watch(()=>importForm.auto_sources,()=>{importForm.item_ids=[];importPage.value=1;});
const importItems=computed(()=>importMatches.value.slice((importPage.value-1)*25,importPage.value*25));
watch([importSearch,importBrand],()=>{importPage.value=1;});
const sourceSearch=reactive({});
const sourceCandidates=(pack)=>{
    const term=String(sourceSearch[pack.id]||'').trim().toLowerCase();
    if(!term)return [];
    return props.digiflazzItems
        .filter(item=>!item.mapped&&item.available&&[item.product_name,item.buyer_sku_code,item.brand,item.seller_name].filter(Boolean).join(' ').toLowerCase().includes(term))
        .slice(0,12);
};
const attachSource=(pack,item)=>{
    router.post('/admin/catalog/packages/'+pack.id+'/sources/digiflazz',{item_id:item.id},{
        preserveScroll:true,
        onSuccess:()=>{sourceSearch[pack.id]='';},
    });
};
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
const previewPrice=(pack,item)=>{
 const mapping=[...pack.mappings].filter(m=>m.is_active).sort((a,b)=>a.priority-b.priority||a.cost_idr-b.cost_idr)[0]||pack.mappings[0];
 if(!mapping?.cost_idr)return 'Belum ada modal';
 const cost=Number(mapping.cost_idr);
 const price=pack.pricing_mode==='SELL_PRICE'?Number(pack.sell_price_idr):cost+(pack.pricing_mode==='FIXED'?Number(pack.margin_fixed_idr||0):Math.ceil(cost*Number(pack.pricing_mode==='PERCENT'?pack.margin_percent:item.margin_percent)/100));
 return 'Rp'+price.toLocaleString('id-ID')+(price<cost?' · di bawah modal':'');
};
watch(()=>props.defaultMargin,value=>{if(!productForm.isDirty)productForm.margin_percent=value;if(!globalMarginForm.isDirty)globalMarginForm.margin_percent=value;if(!importForm.isDirty)importForm.margin_percent=value;});
const packageForm = useForm({ product_id: '', code: '', name: '', note: '', group_name: '', nominal_value: '', sort_order: 0, cost_idr: '' });
const noticeDrafts = reactive({});
const fieldsProductId = ref('');
const fieldRows = ref([]);
const fieldsError = ref('');
const fieldsSaving = ref(false);
watch(fieldsProductId, (id) => {
    const product = products.value.find(item => String(item.id) === String(id));
    fieldRows.value = (product?.fields || []).map(field => ({
        field_key: field.field_key, label: field.label, placeholder: field.placeholder || '',
        type: field.type, is_required: Boolean(field.is_required),
    }));
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
    router.put('/admin/catalog/products/' + fieldsProductId.value + '/fields', {
        fields: fieldRows.value.map(field => ({ ...field, field_key: field.field_key.trim(), label: field.label.trim(), placeholder: field.placeholder.trim() || null })),
    }, {
        preserveScroll: true,
        onError: errors => { fieldsError.value = Object.values(errors).join(' · '); },
        onFinish: () => { fieldsSaving.value = false; },
    });
};
const saveCategory = (item) => router.put('/admin/catalog/categories/' + item.id, { name: item.name, slug: item.slug, icon: item.icon || 'grid', sort_order: item.sort_order, is_active: item.is_active });
const deleteCategory = (item) => {
    if (confirm('Hapus kategori "' + item.name + '"? Kategori yang masih memiliki produk tidak akan bisa dihapus.')) {
        router.delete('/admin/catalog/categories/' + item.id, { preserveScroll: true });
    }
};
const deleteProduct = (item) => {
    if (confirm('Hapus produk "' + item.name + '"? Produk yang memiliki riwayat pesanan akan ditolak oleh sistem.')) {
        router.delete('/admin/catalog/products/' + item.id, { preserveScroll: true, onSuccess: () => { selectedProductId.value = null; } });
    }
};
const deletePackage = (pack) => {
    if (confirm('Hapus nominal "' + pack.name + '"? Nominal yang pernah dipesan tidak dapat dihapus.')) {
        router.delete('/admin/catalog/packages/' + pack.id, { preserveScroll: true, onSuccess: () => { selectedPackageId.value = null; } });
    }
};
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
    sort_order: item.sort_order, is_active: item.is_active,
    nickname_check_enabled: item.nickname_check_enabled,
    nickname_game_code: item.nickname_game_code || null,
    nickname_user_field_key: item.nickname_user_field_key || null,
    nickname_server_field_key: item.nickname_server_field_key || null,
}, {preserveScroll:true,onStart:()=>{savingProduct.value=true;},onFinish:()=>{savingProduct.value=false;}});
const savePackage = (pack) => router.put('/admin/catalog/packages/' + pack.id, {
    code: pack.code, name: pack.name, note: pack.note || null, group_name: pack.group_name || null, nominal_value: pack.nominal_value,
    sort_order: pack.sort_order, is_active: pack.is_active,
    pricing_mode:pack.pricing_mode||'PRODUCT_MARGIN',margin_percent:pack.margin_percent??null,margin_fixed_idr:pack.margin_fixed_idr??null,sell_price_idr:pack.sell_price_idr??null,
});
const saveMapping = (mapping) => router.put('/admin/catalog/mappings/' + mapping.id, {
    priority: mapping.priority, is_active: mapping.is_active,
    ...(mapping.provider_code === 'MANUAL' ? { cost_idr: mapping.cost_idr } : {}),
    ...(mapping.provider_code === 'DIGIFLAZZ' ? { customer_no_template: mapping.customer_no_template || null } : {}),
});
const providerLabel = (mapping) => mapping.provider_name || mapping.provider_code || 'Provider';
const fulfillmentRole = (pack, mapping) => {
    if (!mapping.is_active) return 'Nonaktif';
    const active = [...pack.mappings]
        .filter((entry) => entry.provider_code === mapping.provider_code && entry.is_active)
        .sort((a, b) => Number(a.priority || 0) - Number(b.priority || 0)
            || Number(a.cost_idr || 0) - Number(b.cost_idr || 0)
            || Number(a.id) - Number(b.id));
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
const deleteNotice = (notice) => {
    if (confirm('Hapus pemberitahuan produk ini?')) router.delete('/admin/catalog/notices/' + notice.id, { preserveScroll: true });
};
</script>

<template>
    <Head title="Kelola katalog" />
    <AdminShell>
        <div class="mx-auto max-w-7xl space-y-8">
            <template v-if="selectedProductId === null">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><Link href="/admin/panel" class="text-sm text-cyan-300">← Panel Admin</Link><h1 class="mt-2 text-3xl font-bold">Produk</h1></div><Link href="/" class="text-sm text-cyan-300">Lihat katalog pelanggan</Link></div>
            

            <nav class="lf-admin-tabs"><Button v-for="[key,label] in catalogTabs" :key="key" type="button" :class="{active:catalogTab===key}" @click="catalogTab=key">{{label}}</Button></nav>
            <section v-show="catalogTab === 'categories'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Kategori</h2>
                <p class="text-xs text-muted-foreground">Posisi ditampilkan mulai 1. Angka urutan teknis lama tetap disimpan agar tampilan toko tidak berubah tiba-tiba.</p>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="categoryForm.post('/admin/catalog/categories', { onSuccess: () => { categoryForm.reset(); categoryForm.sort_order = categories.value.length; } })">
                    <label class="text-sm">Nama kategori<Input v-model="categoryForm.name" required class="mt-1 block rounded-md bg-slate-800 p-2" /><span v-if="categoryForm.errors.name" role="alert" class="text-sm font-normal text-destructive">{{ categoryForm.errors.name }}</span></label>
                    <label class="text-sm">Alamat kategori<Input v-model="categoryForm.slug" placeholder="Otomatis dari nama jika kosong" class="mt-1 block rounded-md bg-slate-800 p-2" /><span v-if="categoryForm.errors.slug" role="alert" class="text-sm font-normal text-destructive">{{ categoryForm.errors.slug }}</span></label>
                    <label class="text-sm">Ikon cadangan<select v-model="categoryForm.icon" class="mt-1 block rounded-md bg-slate-800 p-2"><option v-for="[value,label] in categoryIconOptions" :key="value" :value="value">{{label}}</option></select><span v-if="categoryForm.errors.icon" role="alert" class="text-sm font-normal text-destructive">{{ categoryForm.errors.icon }}</span></label>
                    <details class="text-sm"><summary class="cursor-pointer">Urutan lanjutan</summary><label class="block mt-2">Skor urutan teknis<Input v-model.number="categoryForm.sort_order" type="number" min="0" required class="mt-1 block w-24 rounded-md bg-slate-800 p-2" /><span v-if="categoryForm.errors.sort_order" role="alert" class="text-sm font-normal text-destructive">{{ categoryForm.errors.sort_order }}</span></label></details>
                    <Button :disabled="categoryForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah</Button>
                    <span v-if="categoryForm.errors.name" class="text-sm text-red-300">{{ categoryForm.errors.name }}</span>
                </form>
                <div v-for="item in categories" :key="item.id" class="space-y-3 border-t border-slate-800 pt-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <span class="text-xs font-semibold text-slate-300">Posisi {{ categoryPosition(item) }}</span>
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Alamat kategori<Input v-model="item.slug" class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Ikon cadangan<select v-model="item.icon" class="mt-1 block rounded-md bg-slate-800 p-2"><option v-for="[value,label] in categoryIconOptions" :key="value" :value="value">{{label}}</option></select></label>
                        <details class="text-sm"><summary class="cursor-pointer">Ubah skor urutan (lanjutan)</summary><label class="mt-2 block">Skor teknis<Input v-model.number="item.sort_order" type="number" min="0" class="mt-1 block w-24 rounded-md bg-slate-800 p-2" /></label></details>
                        <label class="flex gap-2 text-sm"><AdminSwitch v-model="item.is_active" />Aktif</label>
                        <Button type="button" class="rounded-md bg-slate-700 px-3 py-2 text-sm" @click="saveCategory(item)">Simpan</Button>
                        <Button type="button" class="rounded-md bg-red-50 px-3 py-2 text-sm font-semibold text-red-700" @click="deleteCategory(item)">Hapus</Button>
                        <span class="text-xs text-slate-500">Alamat publik: /{{ item.slug }} · ikon dipakai jika gambar kategori kosong.</span>
                    </div>
                    <div class="rounded-md border border-slate-200 p-3">
                        <strong class="text-xs">Gambar kategori</strong>
                        <p class="mt-1 text-[11px] text-slate-500">Rekomendasi 512×512 (1:1). Dipakai sebagai ikon/tab kategori.</p>
                        <div class="mt-2"><AdminMediaControl type="category" :id="item.id" :url="item.image_url" /></div>
                    </div>
                </div>
            </section>

            <Card class="p-4"><form class="flex flex-wrap items-end gap-3" @submit.prevent="applyGlobalMargin"><label class="text-sm">Margin global produk otomatis (%)<Input v-model.number="globalMarginForm.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/><span v-if="globalMarginForm.errors.margin_percent" role="alert" class="text-sm font-normal text-destructive">{{ globalMarginForm.errors.margin_percent }}</span></label><Button :disabled="globalMarginForm.processing">Terapkan margin otomatis</Button><p class="w-full text-xs text-muted-foreground">Produk manual dan nominal dengan pengaturan harga khusus tidak diubah.</p></form></Card>
            <section v-show="catalogTab === 'products'" class="lf-admin-catalog-section space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-5">
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
                    <p class="text-sm text-muted-foreground">Pilih produk tujuan, lalu pilih SKU yang ingin diimpor. Jika produknya belum ada, buat lewat Tambah produk terlebih dahulu.</p>
                    <label class="block text-sm">Produk tujuan
                        <select v-model="importProductId" class="mt-1 block w-full rounded-md border p-2">
                            <option value="">Pilih produk tujuan</option>
                            <option v-for="product in importableProducts" :key="product.id" :value="product.id">{{ product.name }}</option>
                        </select>
                    </label>
                    <Button type="button" :disabled="!importProductId" @click="openProductImport">Lanjut pilih SKU</Button>
                </Card>
                <form v-if="showCreateProduct" class="grid gap-3 md:grid-cols-3" @submit.prevent="productForm.fulfillment_mode = tab; productForm.post('/admin/catalog/products', { onSuccess: () => { productForm.reset(); productForm.sort_order = Math.max(-1, ...products.value.map((row) => Number(row.sort_order ?? 0))) + 1; } })">
                    <label class="text-sm">Kategori<select v-model="productForm.category_id" required class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="">Pilih kategori</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select><span v-if="productForm.errors.category_id" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.category_id }}</span></label>
                    <label class="text-sm">Nama produk<Input v-model="productForm.name" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.name" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.name }}</span></label>
                    <label class="text-sm">Alamat produk<Input v-model="productForm.slug" placeholder="Otomatis dari nama jika kosong" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.slug" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.slug }}</span></label>
                    <label class="text-sm">Publisher / merek<Input v-model="productForm.publisher" placeholder="Contoh: Moonton" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.publisher" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.publisher }}</span></label>
                    <label class="text-sm">Margin persen<Input v-model.number="productForm.margin_percent" type="number" step="0.0001" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.margin_percent" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.margin_percent }}</span></label>
                    <label class="text-sm md:col-span-2">Deskripsi<Textarea v-model="productForm.description" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.description" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.description }}</span></label>
                    <details class="text-sm"><summary class="cursor-pointer">Urutan produk (lanjutan)</summary><label class="mt-2 block">Skor teknis<Input v-model.number="productForm.sort_order" type="number" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.sort_order" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.sort_order }}</span></label></details>
                    <label v-if="tab === 'MANUAL'" class="text-sm md:col-span-3">Instruksi penanganan internal<Textarea v-model="productForm.manual_instructions" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.manual_instructions" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_instructions }}</span></label>
                    <template v-if="tab === 'MANUAL'">
                        <label class="text-sm">Jam buka<Input v-model="productForm.manual_open_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.manual_open_time" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_open_time }}</span></label>
                        <label class="text-sm">Jam tutup<Input v-model="productForm.manual_close_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /><span v-if="productForm.errors.manual_close_time" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_close_time }}</span></label>
                        <label class="text-sm">Zona waktu<select v-model="productForm.manual_timezone" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select><span v-if="productForm.errors.manual_timezone" role="alert" class="text-sm font-normal text-destructive">{{ productForm.errors.manual_timezone }}</span></label>
                    </template>
                    <div class="md:col-span-3"><Button :disabled="productForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah {{ tab === 'MANUAL' ? 'produk manual' : 'produk otomatis' }}</Button><p v-if="Object.keys(productForm.errors).length" class="mt-2 text-sm text-red-300">{{ Object.values(productForm.errors).join(' · ') }}</p></div>
                </form>
                <div class="divide-y border-y border-slate-200 md:hidden">
                    <button v-for="item in visibleProducts" :key="item.id" type="button" class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-1 py-3 text-left" @click="editProduct(item)">
                        <span class="min-w-0">
                            <strong class="block truncate text-sm">{{ item.name }}</strong>
                            <small class="mt-0.5 block truncate text-muted-foreground">{{ categories.find(c=>String(c.id)===String(item.category_id))?.name||'Tanpa kategori' }}<template v-if="item.publisher"> · {{ item.publisher }}</template></small>
                            <small class="mt-1 block text-muted-foreground">Posisi {{ productPosition(item) }} · {{ item.packages.length }} nominal · {{ item.fulfillment_mode==='MANUAL'?'Manual':'Otomatis' }}</small>
                        </span>
                        <span class="text-right">
                            <span class="block text-xs font-semibold" :class="item.is_active ? 'text-emerald-700' : 'text-slate-500'">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            <span class="mt-1 block text-xs font-medium text-primary">Edit →</span>
                        </span>
                    </button>
                </div>
                <div class="hidden overflow-x-auto md:block"><Table class="min-w-[880px]"><TableHeader><TableRow><TableHead>Produk</TableHead><TableHead>Kategori</TableHead><TableHead>Jenis</TableHead><TableHead>Nominal</TableHead><TableHead>Urutan</TableHead><TableHead>Status</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="item in visibleProducts" :key="item.id"><TableCell><strong>{{item.name}}</strong><small class="block text-muted-foreground">/{{item.slug}}<template v-if="item.publisher"> · {{item.publisher}}</template></small></TableCell><TableCell>{{categories.find(c=>String(c.id)===String(item.category_id))?.name||'Tanpa kategori'}}</TableCell><TableCell>{{item.fulfillment_mode==='MANUAL'?'Manual':'Otomatis'}}</TableCell><TableCell>{{item.packages.length}}</TableCell><TableCell>Posisi {{ productPosition(item) }}</TableCell><TableCell>{{item.is_active ? 'Aktif' : 'Nonaktif'}}</TableCell><TableCell><Button type="button" variant="outline" size="sm" @click="editProduct(item)">Edit</Button></TableCell></TableRow></TableBody></Table></div><p v-if="!visibleProducts.length" class="lf-admin-note">Tidak ada produk yang sesuai dengan filter.</p>
                <nav class="flex flex-wrap items-center justify-between gap-3"><span class="text-sm text-muted-foreground">{{matchingProducts.length}} produk · Halaman {{catalogPage}} dari {{totalCatalogPages}}</span><div class="flex gap-2"><Button type="button" variant="outline" :disabled="catalogPage <= 1" @click="catalogPage--">Sebelumnya</Button><Button type="button" variant="outline" :disabled="catalogPage >= totalCatalogPages" @click="catalogPage++">Berikutnya</Button></div></nav>

            </section>

            <section v-show="catalogTab === 'fields'" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Kolom data pelanggan</h2>
                <p class="text-sm text-slate-400">Atur data yang wajib atau opsional diisi pelanggan saat membeli produk. Urutan di halaman pelanggan mengikuti daftar di bawah.</p>
                <label class="block text-sm">Produk<select v-model="fieldsProductId" class="mt-1 w-full max-w-md rounded-md bg-slate-800 p-2"><option value="">Pilih produk</option><option v-for="item in products" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                <template v-if="fieldsProductId">
<AdminCustomerFields :rows="fieldRows" :error="fieldsError" :saving="fieldsSaving" @move="moveField" @remove="index=>fieldRows.splice(index,1)" @add="addField" @save="saveFields" />
                </template>
            </section>

            </template>
            <section v-else class="lf-admin-workspace" aria-label="Editor produk">
                        <div v-for="item in selectedProductItems" :key="item.id" class="space-y-4">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b pb-4 pr-8"><div><p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Editor Produk</p><h1 class="mt-1 text-xl font-semibold">{{item.name}}</h1></div><div class="flex flex-wrap gap-2"><Button type="button" variant="destructive" size="sm" @click="deleteProduct(item)">Hapus</Button><Button type="button" variant="outline" size="sm" @click="closeProduct">Kembali ke produk</Button></div></header>
                    <nav class="lf-admin-tabs"><Button type="button" variant="ghost" :class="{active:editorTab==='info'}" @click="editorTab='info'">Informasi</Button><Button type="button" variant="ghost" :class="{active:editorTab==='nominal'}" @click="editorTab='nominal'">Nominal & Harga</Button><Button type="button" variant="ghost" :class="{active:editorTab==='display'}" @click="editorTab='display'">Tampilan Produk</Button><Button type="button" variant="ghost" :class="{active:editorTab==='fields'}" @click="fieldsProductId=String(item.id);editorTab='fields'">Data Pelanggan</Button><Button type="button" variant="ghost" :class="{active:editorTab==='fulfillment'}" @click="editorTab='fulfillment'">Penanganan</Button></nav>
                    <div v-show="editorTab==='info'" class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-4">
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Alamat produk<Input v-model="item.slug" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Publisher / merek<Input v-model="item.publisher" placeholder="Publisher / merek game" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Kategori<select v-model="item.category_id" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                        <label class="text-sm">Jenis penanganan<select v-model="item.fulfillment_mode" :disabled="item.packages.length>0" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="AUTO_PROVIDER">Otomatis</option><option value="MANUAL">Manual</option></select><span v-if="item.packages.length>0" class="mt-1 block text-xs text-slate-500">Kosongkan nominal terlebih dahulu jika jenis penanganan memang perlu diubah.</span></label>
                        <label class="text-sm">Margin produk (%)<Input v-model.number="item.margin_percent" type="number" step="0.0001" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <details class="text-sm"><summary class="cursor-pointer">Posisi {{ productPosition(item) }} · ubah skor teknis</summary><label class="mt-2 block">Skor urutan internal<Input v-model.number="item.sort_order" type="number" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label></details>
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
                    <div v-if="editorTab==='fields'" class="space-y-3"><h3>Data Pelanggan</h3><p class="text-muted-foreground">Atur data yang diminta saat pelanggan membeli produk ini.</p><AdminCustomerFields :rows="fieldRows" :error="fieldsError" :saving="fieldsSaving" @move="moveField" @remove="index=>fieldRows.splice(index,1)" @add="addField" @save="saveFields" /></div>
                    <div v-show="editorTab==='fulfillment'" class="space-y-4"><h3 class="font-semibold">Penanganan {{item.fulfillment_mode==='MANUAL'?'manual':'otomatis'}}</h3><p class="text-sm text-slate-500">Instruksi dan jam layanan dikelola pada Informasi. Sumber pemenuhan, prioritas, modal, dan format tujuan dikelola per nominal.</p><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" @click="router.post('/admin/catalog/products/'+item.id+'/sync',{}, {preserveScroll:true})">Sinkron semua nominal produk</Button><AdminResponsiveTable :mobile-columns="[0,2,3]"><TableHeader><TableRow><TableHead>Nominal</TableHead><TableHead>Sumber / kode</TableHead><TableHead>Status koneksi</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="pack in visiblePackages(item)" :key="pack.id"><TableCell>{{pack.name}}</TableCell><TableCell><p v-for="mapping in pack.mappings" :key="mapping.id">{{providerLabel(mapping)}} · {{mapping.provider_code==='VOUCHER_STOCK' ? (mapping.stock_key||'-') : (mapping.external_sku||'Manual')}}</p></TableCell><TableCell><p v-for="mapping in pack.mappings" :key="mapping.id">{{ fulfillmentRole(pack,mapping) }}<span v-if="mapping.is_active"> · Posisi {{ sourcePosition(pack,mapping) }}</span></p></TableCell><TableCell><Button type="button" variant="outline" @click="selectedPackageId=pack.id;editorTab='nominal'">Kelola sumber</Button></TableCell></TableRow></TableBody></AdminResponsiveTable></div>
                    <div v-show="editorTab==='display'" class="space-y-4">
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
                    <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4">
                        <div><h3 class="font-semibold">Pemberitahuan produk</h3><p class="mt-1 text-xs text-slate-500">Informasi publik yang tampil saat pelanggan melakukan pembelian, terpisah dari instruksi penanganan internal.</p></div>
                        <article v-for="notice in item.notices" :key="notice.id" class="grid gap-2 rounded border border-slate-200 p-3 md:grid-cols-[1fr_110px_auto]">
                            <label class="lf-admin-field "><span>Judul</span><Input v-model="notice.title" class="rounded border border-slate-200 p-2 text-xs" placeholder="Judul" /></label>
                            <label class="lf-admin-field "><span>Urutan</span><Input v-model.number="notice.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-xs" placeholder="Urutan" /></label>
                            <label class="flex items-center gap-2 text-xs"><AdminSwitch v-model="notice.is_active" /> Aktif</label>
                            <label class="lf-admin-field md:col-span-3"><span>Isi</span><Textarea v-model="notice.body" rows="2" class="rounded border border-slate-200 p-2 text-xs" placeholder="Isi pemberitahuan"></Textarea></label>
                            <div class="flex gap-2 md:col-span-3"><Button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs text-white" @click="saveNotice(notice)">Simpan</Button><Button type="button" class="rounded bg-red-50 px-3 py-2 text-xs font-semibold text-red-700" @click="deleteNotice(notice)">Hapus</Button></div>
                        </article>
                        <div class="grid gap-2 rounded border border-dashed border-slate-300 p-3 md:grid-cols-[1fr_110px_auto]">
                            <label class="lf-admin-field "><span>Judul</span><Input v-model="noticeDraft(item.id).title" class="rounded border border-slate-200 p-2 text-xs" placeholder="Judul notice baru" /></label>
                            <label class="lf-admin-field "><span>Urutan</span><Input v-model.number="noticeDraft(item.id).sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-xs" placeholder="Urutan" /></label>
                            <label class="flex items-center gap-2 text-xs"><AdminSwitch v-model="noticeDraft(item.id).is_active" /> Aktif</label>
                            <label class="lf-admin-field md:col-span-3"><span>Isi</span><Textarea v-model="noticeDraft(item.id).body" rows="2" class="rounded border border-slate-200 p-2 text-xs" placeholder="Informasi yang dilihat pelanggan"></Textarea></label>
                            <Button type="button" class="w-fit rounded bg-[#1769e8] px-3 py-2 text-xs font-bold text-white md:col-span-3" @click="addNotice(item)">Tambah pemberitahuan</Button>
                        </div>
                    </div>

                    </div>
                    <div v-show="editorTab==='nominal'" class="lf-admin-nominals space-y-3 rounded-md bg-slate-950 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold">Nominal / paket</h3><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" :disabled="autoSourcesForm.processing" @click="autoSourcesForm.post('/admin/catalog/products/'+item.id+'/auto-sources',{preserveScroll:true})">Lengkapi cadangan otomatis</Button><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" @click="toggleImport(item)">Impor nominal Digiflazz</Button></div>
                        <p v-if="Object.keys(autoSourcesForm.errors).length" role="alert" class="text-sm text-destructive">{{ Object.values(autoSourcesForm.errors).join(' · ') }}</p>
                        <Card v-if="showImport && item.fulfillment_mode==='AUTO_PROVIDER'" class="space-y-3 p-4" data-testid="digiflazz-import">
                            <h4 class="font-semibold">Pilih SKU untuk {{item.name}}</h4>
                            <div class="grid gap-3 md:grid-cols-3"><label class="text-sm">Cari SKU<Input v-model="importSearch" class="mt-1"/></label><label class="text-sm">Merek<select v-model="importBrand" class="mt-1 block w-full rounded border p-2"><option value="">Semua brand</option><option v-for="brand in importBrands" :key="brand">{{brand}}</option></select></label><label class="text-sm">Margin nominal (%)<Input v-model.number="importForm.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/></label></div>
                            <p class="text-xs text-slate-500">Pilih SKU tersedia yang belum dipakai, tentukan margin, lalu impor untuk menjual.</p>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="importForm.auto_sources" /> Cadangan otomatis untuk nominal yang sama</label>
                            <p class="text-sm text-muted-foreground">SKU dengan nama, merek, jenis, dan kategori yang sama menjadi satu nominal. Sumber termurah yang tersedia dipilih lebih dahulu; cadangan baru ditambahkan saat sinkron.</p>
                            <div class="flex flex-wrap gap-2"><Button type="button" variant="outline" @click="importForm.item_ids=importMatches.map(sku=>sku.id)">Pilih semua hasil filter ({{ importMatches.length }})</Button><Button type="button" variant="outline" @click="importForm.item_ids=[]">Hapus pilihan</Button></div>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="importForm.publish" /> Langsung jual setelah impor</label>
                            <label class="block text-sm">Format ID tujuan<Input v-model="importForm.customer_no_template" data-testid="import-customer-template" class="mt-1" placeholder="Pilih kolom pelanggan di bawah" /></label>
                            <div class="flex flex-wrap gap-2"><Button v-for="field in item.fields" :key="field.field_key" type="button" size="sm" variant="outline" @click="importForm.customer_no_template += '{{' + field.field_key + '}}'">{{ field.label }}</Button></div>
                            <p class="text-sm text-muted-foreground">{{ importForm.publish ? 'Produk, nominal, dan sumber Digiflazz diaktifkan bersama. Harga mengikuti margin di atas.' : 'Nominal disimpan sebagai draf dan belum dijual.' }}</p>
                            <label v-for="sku in importItems" :key="sku.id" :data-sku="sku.buyer_sku_code" class="flex items-start gap-3 rounded border p-2 text-sm"><input v-model="importForm.item_ids" type="checkbox" :value="sku.id"><span>{{sku.product_name}}<small class="block">{{sku.buyer_sku_code}} · Rp{{Number(sku.price_idr).toLocaleString('id-ID')}}<span v-if="importForm.auto_sources"> · {{sku.source_count}} sumber otomatis</span></small></span></label>
                            <p v-if="!importMatches.length" class="text-sm">Tidak ada SKU tersedia yang sesuai dengan filter dan belum diimpor. Hapus filter atau sinkronkan daftar harga di menu Digiflazz.</p>
                            <div class="flex flex-wrap items-center gap-2"><Button type="button" variant="outline" :disabled="importPage===1" @click="importPage--">Sebelumnya</Button><span class="text-sm">Halaman {{importPage}} / {{Math.max(1,Math.ceil(importMatches.length/25))}}</span><Button type="button" variant="outline" :disabled="importPage*25>=importMatches.length" @click="importPage++">Berikutnya</Button></div>
                            <p v-if="Object.keys(importForm.errors).length" role="alert" class="text-sm text-destructive">{{ Object.values(importForm.errors).join(' · ') }}</p>
                            <p v-if="!importForm.item_ids.length" class="text-sm text-muted-foreground">Centang minimal satu SKU untuk mengaktifkan tombol impor.</p>
                            <Button type="button" :disabled="importForm.processing||!importForm.item_ids.length" @click="importForm.post('/admin/catalog/products/'+item.id+'/import',{preserveScroll:true,onSuccess:()=>{importForm.item_ids=[];showImport=false;}})">Impor {{importForm.item_ids.length}} nominal</Button>
                        </Card>
                        <AdminResponsiveTable :mobile-columns="[0,2,5,6]"><TableHeader><TableRow><TableHead>Nominal</TableHead><TableHead>Sumber / Modal</TableHead><TableHead class="text-right">Harga jual</TableHead><TableHead>Margin</TableHead><TableHead class="text-right">Urutan</TableHead><TableHead>Status</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody>
                            <TableRow v-for="(pack,index) in visiblePackages(item)" :key="pack.id" draggable="true" @dragstart="draggedPackageId=pack.id" @dragover.prevent @drop.prevent="dropPackage(item,(nominalPage-1)*25+index)">
                                <TableCell><div class="flex items-center gap-2"><img v-if="pack.image_url" :src="pack.image_url" alt="" class="size-8 shrink-0 object-contain"><strong>{{pack.name}}</strong></div><p class="text-xs text-slate-500">{{pack.group_name||'-'}}</p></TableCell>
                                <TableCell><p v-for="mapping in pack.mappings" :key="mapping.id" class="text-xs">{{ providerLabel(mapping) }}<span v-if="mapping.provider_code==='VOUCHER_STOCK'"> · {{ mapping.stock_key || '-' }}</span><span v-else-if="mapping.external_sku"> · {{ mapping.external_sku }}</span> · Rp{{Number(mapping.cost_idr||0).toLocaleString('id-ID')}}</p></TableCell>
                                <TableCell class="text-right">{{previewPrice(pack,item)}}</TableCell><TableCell>{{pack.pricing_mode==='SELL_PRICE'?'Harga tetap':pack.pricing_mode==='FIXED'?'Rp'+Number(pack.margin_fixed_idr||0).toLocaleString('id-ID'):(pack.pricing_mode==='PERCENT'?pack.margin_percent:item.margin_percent)+'%'}}</TableCell><TableCell class="text-right">Posisi {{ packagePosition(item, pack) }}</TableCell><TableCell>{{pack.is_active?'Aktif':'Nonaktif'}}</TableCell>
                                <TableCell><div class="flex flex-wrap gap-2"><Button type="button" variant="outline" @click="editPackage(pack.id)">Edit nominal</Button></div></TableCell>
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
                                <details class="text-xs"><summary class="cursor-pointer">Posisi {{ packagePosition(item,pack) }} · skor lanjutan</summary><label class="block mt-2">Skor teknis<Input v-model.number="pack.sort_order" type="number" min="0" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label></details>
                                <label class="flex gap-2 text-xs"><AdminSwitch v-model="pack.is_active" />Aktif</label>
                                <Button type="button" class="rounded bg-slate-700 px-3 py-2 text-xs" @click="savePackage(pack)">Simpan</Button>
                            </div>
                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="text-xs">Mode harga<select v-model="pack.pricing_mode" class="mt-1 block w-full rounded border p-2"><option value="PRODUCT_MARGIN">Ikuti margin produk</option><option value="PERCENT">Margin persen nominal</option><option value="FIXED">Margin rupiah nominal</option><option value="SELL_PRICE">Harga jual tetap</option></select></label>
                                <label v-if="pack.pricing_mode==='PERCENT'" class="text-xs">Margin %<Input v-model.number="pack.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/></label>
                                <label v-if="pack.pricing_mode==='FIXED'" class="text-xs">Margin Rp<Input v-model.number="pack.margin_fixed_idr" type="number" min="0" class="mt-1"/></label>
                                <label v-if="pack.pricing_mode==='SELL_PRICE'" class="text-xs">Harga jual Rp<Input v-model.number="pack.sell_price_idr" type="number" min="1" class="mt-1"/></label>
                                <p class="self-center text-sm">Pratinjau harga: <strong>{{previewPrice(pack,item)}}</strong></p>
                            </div>
                            <div class="rounded border border-slate-700 p-2">
                                <p class="mb-2 text-[11px] text-slate-400">Gambar nominal: rekomendasi 512×512 (1:1), PNG/WebP transparan bila memungkinkan.</p>
                                <AdminMediaControl type="package" :id="pack.id" :url="pack.image_url" />
                            </div>
                            <div class="space-y-2 rounded-md border border-slate-800 p-3">
                                <div><h4 class="text-sm font-semibold">Sumber Top Up: Utama dan Cadangan</h4><p class="mt-1 text-[11px] text-slate-500">Posisi dimulai dari 1. Sumber utama digunakan pertama, cadangan hanya untuk kegagalan definitif. Pending, proses, timeout, atau status tidak pasti wajib direkonsiliasi, bukan langsung dikirim ulang.</p></div>
                                <div v-for="mapping in [...pack.mappings.filter((entry) => entry.provider_code !== 'VOUCHER_STOCK')].sort(bySourcePriority)" :key="mapping.id" class="flex flex-wrap items-end gap-2 rounded border border-slate-800 p-2 text-xs text-slate-300">
                                    <span class="min-w-40"><strong>{{ fulfillmentRole(pack, mapping) }}<template v-if="mapping.is_active"> · Posisi {{ sourcePosition(pack,mapping) }}</template></strong><br>{{ providerLabel(mapping) }}<span v-if="mapping.external_sku"> · {{ mapping.external_sku }}</span><br><span class="text-slate-500">Modal Rp{{Number(mapping.cost_idr||0).toLocaleString('id-ID')}}</span></span>
                                    <Button v-if="mapping.provider_code==='DIGIFLAZZ'" type="button" variant="outline" @click="router.post('/admin/catalog/mappings/'+mapping.id+'/sync',{}, {preserveScroll:true})">Sinkron harga</Button>
                                    <label v-if="mapping.provider_code === 'MANUAL'">Modal Rp<Input v-model.number="mapping.cost_idr" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                    <label v-if="mapping.provider_code === 'DIGIFLAZZ'" class="min-w-72">Format ID tujuan<Input v-model="mapping.customer_no_template" placeholder="{{user_id}}{{zone_id}}" class="mt-1 block w-full rounded bg-slate-800 p-2" /><span class="mt-1 block text-[11px] text-slate-500">Gunakan kode kolom di dalam {{ }}.</span></label>
                                    <details class="text-xs"><summary class="cursor-pointer">Prioritas teknis (lanjutan)</summary><label class="mt-2 block">Skor internal<Input v-model.number="mapping.priority" type="number" min="0" max="1000" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label><p>Angka kecil dipilih lebih dulu. Pada sumber otomatis, skor dapat disusun ulang saat sinkronisasi.</p></details>
                                    <label class="flex gap-2"><AdminSwitch v-model="mapping.is_active" />Aktif</label>
                                    <Button type="button" class="rounded bg-slate-700 px-3 py-2" @click="saveMapping(mapping)">Simpan sumber</Button>
                                </div>
                                <div v-if="item.fulfillment_mode==='AUTO_PROVIDER'" class="space-y-2 border-t border-slate-800 pt-3">
                                    <label class="text-xs">Tambah sumber Digiflazz alternatif<Input v-model="sourceSearch[pack.id]" placeholder="Cari nama, SKU, merek, atau seller" class="mt-1" /></label>
                                    <div v-if="sourceCandidates(pack).length" class="max-h-56 overflow-y-auto rounded border border-slate-800">
                                        <button v-for="candidate in sourceCandidates(pack)" :key="candidate.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-left text-xs last:border-b-0 hover:bg-slate-900" @click="attachSource(pack,candidate)">
                                            <span><strong>{{candidate.product_name}}</strong><small class="block text-slate-500">{{candidate.buyer_sku_code}} · {{candidate.brand||'-'}} · {{candidate.seller_name||'Seller Digiflazz'}}</small></span>
                                            <span class="whitespace-nowrap">Rp{{Number(candidate.price_idr||0).toLocaleString('id-ID')}}</span>
                                        </button>
                                    </div>
                                    <p class="text-[11px] text-slate-500">Sumber baru selalu ditambahkan nonaktif dan di prioritas paling belakang. Periksa kesetaraan nominal, format tujuan, harga, lalu aktifkan secara manual.</p>
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

        </div>
    </AdminShell>
</template>
