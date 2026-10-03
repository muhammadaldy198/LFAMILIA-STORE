<script setup>
import { Card } from '../../Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { computed, reactive, ref, watch } from 'vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';

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
const products = ref(cloneProducts(props.products));
watch(() => props.categories, (items) => { categories.value = items.map((item) => ({ ...item })); });
watch(() => props.products, (items) => { products.value = cloneProducts(items); });

const page = usePage();
const initialSearch = new URLSearchParams(page.url.split('?')[1] || '').get('q') || '';
const searchedProduct = products.value.find(item => item.name.toLowerCase().includes(initialSearch.toLowerCase()));
const tab = ref(initialSearch && searchedProduct ? searchedProduct.fulfillment_mode : 'AUTO_PROVIDER');
const selectedProductId = ref(null), editorTab = ref('info'), showCreateProduct = ref(false);
const selectedPackageId=ref(null);
const selectedProductItems = computed(() => products.value.filter(item => item.id === selectedProductId.value));
const editProduct = item => { selectedProductId.value = item.id; editorTab.value = 'info'; showImport.value=false; importForm.item_ids=[]; importForm.margin_percent=Number(item.margin_percent); selectedPackageId.value=null; };
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
const categoryForm = useForm({ name: '', slug: '', icon: 'grid', sort_order: 0 });
const productForm = useForm({ category_id: '', name: '', slug: '', publisher: '', description: '', fulfillment_mode: 'AUTO_PROVIDER', manual_instructions: '', manual_open_time: '', manual_close_time: '', manual_timezone: 'Asia/Jakarta', margin_percent: props.defaultMargin||0, sort_order: 0 });
const globalMarginForm = useForm({margin_percent:props.defaultMargin||0});
const applyGlobalMargin=()=>{if(confirm('Terapkan margin global ke semua produk otomatis? Produk manual dan margin khusus nominal tidak akan diubah.'))globalMarginForm.put('/admin/catalog/margin',{preserveScroll:true});};
const importForm = useForm({item_ids:[],margin_percent:props.defaultMargin||0});
const importSearch=ref(''),importBrand=ref(''),showImport=ref(false);
const importPage=ref(1);
const importBrands=computed(()=>[...new Set(props.digiflazzItems.map(i=>i.brand))].sort());
const importMatches=computed(()=>props.digiflazzItems.filter(i=>!i.mapped && i.available && (!importBrand.value||i.brand===importBrand.value) && (!importSearch.value||[i.product_name,i.buyer_sku_code].join(' ').toLowerCase().includes(importSearch.value.toLowerCase()))));
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
});
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
            <div class="flex flex-wrap items-center justify-between gap-3"><div><Link href="/admin/panel" class="text-sm text-cyan-300">← Panel Admin</Link><h1 class="mt-2 text-3xl font-bold">Produk</h1></div><Link href="/" class="text-sm text-cyan-300">Lihat katalog pelanggan</Link></div>
            

            <nav class="lf-admin-tabs"><Button v-for="[key,label] in catalogTabs" :key="key" type="button" :class="{active:catalogTab===key}" @click="catalogTab=key">{{label}}</Button></nav>
            <section v-show="catalogTab === 'categories'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Kategori</h2>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="categoryForm.post('/admin/catalog/categories', { onSuccess: () => categoryForm.reset() })">
                    <label class="text-sm">Nama kategori<Input v-model="categoryForm.name" required class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Alamat kategori<Input v-model="categoryForm.slug" placeholder="Otomatis dari nama jika kosong" class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Ikon cadangan<select v-model="categoryForm.icon" class="mt-1 block rounded-md bg-slate-800 p-2"><option v-for="[value,label] in categoryIconOptions" :key="value" :value="value">{{label}}</option></select></label>
                    <label class="text-sm">Urutan<Input v-model.number="categoryForm.sort_order" type="number" min="0" required class="mt-1 block w-24 rounded-md bg-slate-800 p-2" /></label>
                    <Button :disabled="categoryForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah</Button>
                    <span v-if="categoryForm.errors.name" class="text-sm text-red-300">{{ categoryForm.errors.name }}</span>
                </form>
                <div v-for="item in categories" :key="item.id" class="space-y-3 border-t border-slate-800 pt-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Alamat kategori<Input v-model="item.slug" class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Ikon cadangan<select v-model="item.icon" class="mt-1 block rounded-md bg-slate-800 p-2"><option v-for="[value,label] in categoryIconOptions" :key="value" :value="value">{{label}}</option></select></label>
                        <label class="text-sm">Urutan<Input v-model.number="item.sort_order" type="number" min="0" class="mt-1 block w-24 rounded-md bg-slate-800 p-2" /></label>
                        <label class="flex gap-2 text-sm"><input v-model="item.is_active" type="checkbox">Aktif</label>
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

            <Card class="p-4"><form class="flex flex-wrap items-end gap-3" @submit.prevent="applyGlobalMargin"><label class="text-sm">Margin global produk otomatis (%)<Input v-model.number="globalMarginForm.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/></label><Button :disabled="globalMarginForm.processing">Terapkan margin otomatis</Button><p class="w-full text-xs text-muted-foreground">Produk manual dan nominal dengan pengaturan harga khusus tidak diubah.</p></form></Card>
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
                <Button type="button" variant="outline" @click="showCreateProduct = !showCreateProduct">{{ showCreateProduct ? 'Tutup formulir' : 'Tambah produk' }}</Button>
                <form v-if="showCreateProduct" class="grid gap-3 md:grid-cols-3" @submit.prevent="productForm.fulfillment_mode = tab; productForm.post('/admin/catalog/products', { onSuccess: () => productForm.reset() })">
                    <label class="text-sm">Kategori<select v-model="productForm.category_id" required class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="">Pilih kategori</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label class="text-sm">Nama produk<Input v-model="productForm.name" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Alamat produk<Input v-model="productForm.slug" placeholder="Otomatis dari nama jika kosong" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Publisher / merek<Input v-model="productForm.publisher" placeholder="Contoh: Moonton" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Margin persen<Input v-model.number="productForm.margin_percent" type="number" step="0.0001" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm md:col-span-2">Deskripsi<Textarea v-model="productForm.description" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Urutan<Input v-model.number="productForm.sort_order" type="number" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label v-if="tab === 'MANUAL'" class="text-sm md:col-span-3">Instruksi penanganan internal<Textarea v-model="productForm.manual_instructions" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <template v-if="tab === 'MANUAL'">
                        <label class="text-sm">Jam buka<Input v-model="productForm.manual_open_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Jam tutup<Input v-model="productForm.manual_close_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Zona waktu<select v-model="productForm.manual_timezone" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select></label>
                    </template>
                    <div class="md:col-span-3"><Button :disabled="productForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah {{ tab === 'MANUAL' ? 'produk manual' : 'produk otomatis' }}</Button><p v-if="Object.keys(productForm.errors).length" class="mt-2 text-sm text-red-300">{{ Object.values(productForm.errors).join(' · ') }}</p></div>
                </form>
                <div class="overflow-x-auto"><Table class="min-w-[880px]"><TableHeader><TableRow><TableHead>Produk</TableHead><TableHead>Kategori</TableHead><TableHead>Jenis</TableHead><TableHead>Nominal</TableHead><TableHead>Urutan</TableHead><TableHead>Status</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="item in visibleProducts" :key="item.id"><TableCell><strong>{{item.name}}</strong><small class="block text-muted-foreground">/{{item.slug}}<template v-if="item.publisher"> · {{item.publisher}}</template></small></TableCell><TableCell>{{categories.find(c=>String(c.id)===String(item.category_id))?.name||'Tanpa kategori'}}</TableCell><TableCell>{{item.fulfillment_mode==='MANUAL'?'Manual':'Otomatis'}}</TableCell><TableCell>{{item.packages.length}}</TableCell><TableCell>{{item.sort_order}}</TableCell><TableCell>{{item.is_active ? 'Aktif' : 'Nonaktif'}}</TableCell><TableCell><Button type="button" variant="outline" size="sm" @click="editProduct(item)">Edit</Button></TableCell></TableRow></TableBody></Table></div><p v-if="!visibleProducts.length" class="lf-admin-note">Tidak ada produk yang sesuai dengan filter.</p>
                <nav class="flex flex-wrap items-center justify-between gap-3"><span class="text-sm text-muted-foreground">{{matchingProducts.length}} produk · Halaman {{catalogPage}} dari {{totalCatalogPages}}</span><div class="flex gap-2"><Button type="button" variant="outline" :disabled="catalogPage <= 1" @click="catalogPage--">Sebelumnya</Button><Button type="button" variant="outline" :disabled="catalogPage >= totalCatalogPages" @click="catalogPage++">Berikutnya</Button></div></nav>
                <Card v-for="item in selectedProductItems" :key="item.id" class="space-y-4 p-4">
                    <header class="flex flex-wrap items-center justify-between gap-3"><h2>Edit {{item.name}}</h2><div class="flex flex-wrap gap-2"><Button type="button" variant="destructive" @click="deleteProduct(item)">Hapus produk</Button><Button type="button" variant="outline" @click="selectedProductId=null">Tutup</Button></div></header>
                    <nav class="lf-admin-tabs"><Button type="button" variant="ghost" :class="{active:editorTab==='info'}" @click="editorTab='info'">Informasi</Button><Button type="button" variant="ghost" :class="{active:editorTab==='nominal'}" @click="editorTab='nominal'">Nominal & Harga</Button><Button type="button" variant="ghost" :class="{active:editorTab==='display'}" @click="editorTab='display'">Tampilan Produk</Button><Button type="button" variant="ghost" @click="fieldsProductId=String(item.id);catalogTab='fields'">Data Pelanggan</Button><Button type="button" variant="ghost" :class="{active:editorTab==='fulfillment'}" @click="editorTab='fulfillment'">Penanganan</Button></nav>
                    <div v-show="editorTab==='info'" class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-4">
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Alamat produk<Input v-model="item.slug" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Publisher / merek<Input v-model="item.publisher" placeholder="Publisher / merek game" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Kategori<select v-model="item.category_id" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                        <label class="text-sm">Jenis penanganan<select v-model="item.fulfillment_mode" :disabled="item.packages.length>0" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="AUTO_PROVIDER">Otomatis</option><option value="MANUAL">Manual</option></select><span v-if="item.packages.length>0" class="mt-1 block text-xs text-slate-500">Kosongkan nominal terlebih dahulu jika jenis penanganan memang perlu diubah.</span></label>
                        <label class="text-sm">Margin produk (%)<Input v-model.number="item.margin_percent" type="number" step="0.0001" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Urutan<Input v-model.number="item.sort_order" type="number" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm md:col-span-3">Deskripsi<Textarea v-model="item.description" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="item.is_active" type="checkbox"> Produk aktif</label>
                        <label v-if="item.fulfillment_mode === 'MANUAL'" class="text-sm md:col-span-4">Instruksi internal<Textarea v-model="item.manual_instructions" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <template v-if="item.fulfillment_mode === 'MANUAL'">
                            <label class="text-sm">Jam buka<Input v-model="item.manual_open_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                            <label class="text-sm">Jam tutup<Input v-model="item.manual_close_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                            <label class="text-sm">Zona waktu<select v-model="item.manual_timezone" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select></label>
                        </template>
                        <div class="space-y-3 rounded-md border border-slate-700 p-3 md:col-span-4">
                            <label class="flex items-center gap-2 text-sm"><input v-model="item.nickname_check_enabled" type="checkbox"> Aktifkan cek nickname</label>
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
                    <Button type="button" class="rounded-md bg-slate-700 px-4 py-2 text-sm" @click="saveProduct(item)">Simpan produk</Button>
                    </div>
                    <div v-show="editorTab==='fulfillment'" class="space-y-4"><h3 class="font-semibold">Penanganan {{item.fulfillment_mode==='MANUAL'?'manual':'otomatis'}}</h3><p class="text-sm text-slate-500">Instruksi dan jam layanan dikelola pada Informasi. Sumber pemenuhan, prioritas, modal, dan format tujuan dikelola per nominal.</p><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" @click="router.post('/admin/catalog/products/'+item.id+'/sync',{}, {preserveScroll:true})">Sinkron semua nominal produk</Button><Table><TableHeader><TableRow><TableHead>Nominal</TableHead><TableHead>Sumber / kode</TableHead><TableHead>Status koneksi</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="pack in item.packages" :key="pack.id"><TableCell>{{pack.name}}</TableCell><TableCell><p v-for="mapping in pack.mappings" :key="mapping.id">{{providerLabel(mapping)}} · {{mapping.provider_code==='VOUCHER_STOCK' ? (mapping.stock_key||'-') : (mapping.external_sku||'Manual')}}</p></TableCell><TableCell><p v-for="mapping in pack.mappings" :key="mapping.id">{{mapping.is_active?'Aktif':'Nonaktif'}} · prioritas {{mapping.priority}}</p></TableCell><TableCell><Button type="button" variant="outline" @click="selectedPackageId=pack.id;editorTab='nominal'">Kelola sumber</Button></TableCell></TableRow></TableBody></Table></div>
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
                            <label class="flex items-center gap-2 text-sm"><input v-model="item.instant" type="checkbox"> Tampilkan label Instan</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="item.package_tabs_enabled" type="checkbox"> Gunakan tab untuk grup nominal</label>
                            <label v-if="item.package_tabs_enabled" class="text-sm md:col-span-2">Urutan tab nominal<Input :model-value="(item.package_tabs||[]).join(', ')" placeholder="Contoh: Diamonds, Weekly Pass" class="mt-1" @change="item.package_tabs=String($event.target.value).split(',').map(v=>v.trim()).filter(Boolean)" /><span class="mt-1 block text-xs text-muted-foreground">Pisahkan dengan koma. Nama harus sama dengan Grup / Tabel pada nominal.</span></label>
                        </div>
                        <Button type="button" variant="outline" @click="saveProduct(item)">Simpan tampilan produk</Button>
                    </Card>
                    <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4">
                        <div><h3 class="font-semibold">Pemberitahuan produk</h3><p class="mt-1 text-xs text-slate-500">Informasi publik yang tampil saat pelanggan melakukan pembelian, terpisah dari instruksi penanganan internal.</p></div>
                        <article v-for="notice in item.notices" :key="notice.id" class="grid gap-2 rounded border border-slate-200 p-3 md:grid-cols-[1fr_110px_auto]">
                            <Input v-model="notice.title" class="rounded border border-slate-200 p-2 text-xs" placeholder="Judul" />
                            <Input v-model.number="notice.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-xs" placeholder="Urutan" />
                            <label class="flex items-center gap-2 text-xs"><input v-model="notice.is_active" type="checkbox"> Aktif</label>
                            <Textarea v-model="notice.body" rows="2" class="rounded border border-slate-200 p-2 text-xs md:col-span-3" placeholder="Isi pemberitahuan"></Textarea>
                            <div class="flex gap-2 md:col-span-3"><Button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs text-white" @click="saveNotice(notice)">Simpan</Button><Button type="button" class="rounded bg-red-50 px-3 py-2 text-xs font-semibold text-red-700" @click="deleteNotice(notice)">Hapus</Button></div>
                        </article>
                        <div class="grid gap-2 rounded border border-dashed border-slate-300 p-3 md:grid-cols-[1fr_110px_auto]">
                            <Input v-model="noticeDraft(item.id).title" class="rounded border border-slate-200 p-2 text-xs" placeholder="Judul notice baru" />
                            <Input v-model.number="noticeDraft(item.id).sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-xs" placeholder="Urutan" />
                            <label class="flex items-center gap-2 text-xs"><input v-model="noticeDraft(item.id).is_active" type="checkbox"> Aktif</label>
                            <Textarea v-model="noticeDraft(item.id).body" rows="2" class="rounded border border-slate-200 p-2 text-xs md:col-span-3" placeholder="Informasi yang dilihat pelanggan"></Textarea>
                            <Button type="button" class="w-fit rounded bg-[#1769e8] px-3 py-2 text-xs font-bold text-white md:col-span-3" @click="addNotice(item)">Tambah pemberitahuan</Button>
                        </div>
                    </div>

                    </div>
                    <div v-show="editorTab==='nominal'" class="lf-admin-nominals space-y-3 rounded-md bg-slate-950 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold">Nominal / paket</h3><Button v-if="item.fulfillment_mode==='AUTO_PROVIDER'" type="button" variant="outline" @click="showImport=!showImport">Impor nominal Digiflazz</Button></div>
                        <Card v-if="showImport && item.fulfillment_mode==='AUTO_PROVIDER'" class="space-y-3 p-4">
                            <h4 class="font-semibold">Pilih SKU untuk {{item.name}}</h4>
                            <div class="grid gap-3 md:grid-cols-3"><label class="text-sm">Cari SKU<Input v-model="importSearch" class="mt-1"/></label><label class="text-sm">Merek<select v-model="importBrand" class="mt-1 block w-full rounded border p-2"><option value="">Semua brand</option><option v-for="brand in importBrands" :key="brand">{{brand}}</option></select></label><label class="text-sm">Margin nominal (%)<Input v-model.number="importForm.margin_percent" type="number" min="0" max="1000" step="0.0001" class="mt-1"/></label></div>
                            <p class="text-xs text-slate-500">Hanya SKU tersedia yang belum dipakai. Nominal hasil impor nonaktif sampai diperiksa.</p>
                            <label v-for="sku in importItems" :key="sku.id" class="flex items-start gap-3 rounded border p-2 text-sm"><input v-model="importForm.item_ids" type="checkbox" :value="sku.id"><span>{{sku.product_name}}<small class="block">{{sku.buyer_sku_code}} · Rp{{Number(sku.price_idr).toLocaleString('id-ID')}}</small></span></label>
                            <p v-if="!importMatches.length" class="text-sm">Belum ada SKU. Sinkronkan dahulu lewat menu Digiflazz.</p>
                            <div class="flex flex-wrap items-center gap-2"><Button type="button" variant="outline" :disabled="importPage===1" @click="importPage--">Sebelumnya</Button><span class="text-sm">Halaman {{importPage}} / {{Math.max(1,Math.ceil(importMatches.length/25))}}</span><Button type="button" variant="outline" :disabled="importPage*25>=importMatches.length" @click="importPage++">Berikutnya</Button></div>
                            <Button type="button" :disabled="importForm.processing||!importForm.item_ids.length" @click="importForm.post('/admin/catalog/products/'+item.id+'/import',{preserveScroll:true,onSuccess:()=>{importForm.item_ids=[];showImport=false;}})">Impor {{importForm.item_ids.length}} nominal</Button>
                        </Card>
                        <Table><TableHeader><TableRow><TableHead>Nominal</TableHead><TableHead>Sumber / Modal</TableHead><TableHead>Harga jual</TableHead><TableHead>Status</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody>
                            <TableRow v-for="(pack,index) in item.packages" :key="pack.id" draggable="true" @dragstart="draggedPackageId=pack.id" @dragover.prevent @drop.prevent="dropPackage(item,index)">
                                <TableCell><strong>{{pack.name}}</strong><p class="text-xs text-slate-500">{{pack.group_name||'-'}}</p></TableCell>
                                <TableCell><p v-for="mapping in pack.mappings" :key="mapping.id" class="text-xs">{{ providerLabel(mapping) }}<span v-if="mapping.provider_code==='VOUCHER_STOCK'"> · {{ mapping.stock_key || '-' }}</span><span v-else-if="mapping.external_sku"> · {{ mapping.external_sku }}</span> · Rp{{Number(mapping.cost_idr||0).toLocaleString('id-ID')}}</p></TableCell>
                                <TableCell>{{previewPrice(pack,item)}}</TableCell><TableCell>{{pack.is_active?'Aktif':'Nonaktif'}}</TableCell>
                                <TableCell><div class="flex flex-wrap gap-2"><Button type="button" variant="outline" @click="selectedPackageId=pack.id">Edit nominal</Button><Button v-if="item.fulfillment_mode==='MANUAL'" type="button" variant="outline" @click="duplicatePackage(item,pack)">Salin</Button><Button type="button" variant="outline" :disabled="index===0" @click="reorderPackage(item,index,-1)">Naik</Button><Button type="button" variant="outline" :disabled="index===item.packages.length-1" @click="reorderPackage(item,index,1)">Turun</Button><Button type="button" variant="destructive" @click="deletePackage(pack)">Hapus</Button></div></TableCell>
                            </TableRow>
                        </TableBody></Table>
                        <p v-if="!item.packages.length" class="text-sm text-slate-500">Belum ada nominal. Tambahkan manual atau impor dari Digiflazz.</p>
                        <div v-for="(pack,packIndex) in item.packages" v-show="selectedPackageId===pack.id" :key="pack.id" class="space-y-2 border-t border-slate-800 pt-3" draggable="true" @dragstart="draggedPackageId=pack.id" @dragover.prevent @drop.prevent="dropPackage(item,packIndex)">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="flex gap-2"><Button type="button" variant="outline" :disabled="packIndex===0" @click="reorderPackage(item,packIndex,-1)">Naik</Button><Button type="button" variant="outline" :disabled="packIndex===item.packages.length-1" @click="reorderPackage(item,packIndex,1)">Turun</Button></div>
                                <Button type="button" variant="outline" @click="selectedPackageId=null">Tutup editor nominal</Button>
                                <label class="text-xs">Kode internal<Input v-model="pack.code" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Nama nominal<Input v-model="pack.name" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Badge<Input v-model="pack.note" maxlength="80" placeholder="Contoh: Populer" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Grup / Tabel<select v-if="item.package_tabs_enabled" v-model="pack.group_name" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Pilih tab</option><option v-for="tabName in item.package_tabs||[]" :key="tabName" :value="tabName">{{tabName}}</option></select><Input v-else v-model="pack.group_name" placeholder="Contoh: Diamonds" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Nilai nominal<Input v-model.number="pack.nominal_value" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Urutan<Input v-model.number="pack.sort_order" type="number" min="0" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label>
                                <label class="flex gap-2 text-xs"><input v-model="pack.is_active" type="checkbox">Aktif</label>
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
                                <div><h4 class="text-sm font-semibold">Prioritas fulfillment</h4><p class="mt-1 text-[11px] text-slate-500">Angka lebih kecil dipakai lebih dulu. Sistem hanya pindah ke sumber berikutnya setelah kegagalan definitif. Pending, proses, timeout, atau status tidak pasti wajib direkonsiliasi dan tidak boleh langsung failover.</p></div>
                                <div v-for="mapping in [...pack.mappings.filter((entry) => entry.provider_code !== 'VOUCHER_STOCK')].sort((a,b)=>Number(a.priority)-Number(b.priority)||Number(a.cost_idr||0)-Number(b.cost_idr||0))" :key="mapping.id" class="flex flex-wrap items-end gap-2 rounded border border-slate-800 p-2 text-xs text-slate-300">
                                    <span class="min-w-40"><strong>{{ fulfillmentRole(pack, mapping) }} · Prioritas {{ mapping.priority }}</strong><br>{{ providerLabel(mapping) }}<span v-if="mapping.external_sku"> · {{ mapping.external_sku }}</span><br><span class="text-slate-500">Modal Rp{{Number(mapping.cost_idr||0).toLocaleString('id-ID')}}</span></span>
                                    <Button v-if="mapping.provider_code==='DIGIFLAZZ'" type="button" variant="outline" @click="router.post('/admin/catalog/mappings/'+mapping.id+'/sync',{}, {preserveScroll:true})">Sinkron harga</Button>
                                    <label v-if="mapping.provider_code === 'MANUAL'">Modal Rp<Input v-model.number="mapping.cost_idr" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                    <label v-if="mapping.provider_code === 'DIGIFLAZZ'" class="min-w-72">Format ID tujuan<Input v-model="mapping.customer_no_template" placeholder="{{user_id}}{{zone_id}}" class="mt-1 block w-full rounded bg-slate-800 p-2" /><span class="mt-1 block text-[11px] text-slate-500">Gunakan kode kolom di dalam {{ }}.</span></label>
                                    <label>Prioritas<Input v-model.number="mapping.priority" type="number" min="0" max="1000" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label>
                                    <label class="flex gap-2"><input v-model="mapping.is_active" type="checkbox">Aktif</label>
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
                                    <label class="text-xs">Prioritas<Input v-model.number="pack.stock_form.priority" type="number" min="0" max="1000" class="mt-1" /></label>
                                    <label class="flex items-center gap-2 self-end pb-2 text-xs"><input v-model="pack.stock_form.is_active" type="checkbox"> Aktif untuk checkout</label>
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
                        <form class="flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3" @submit.prevent="packageForm.post('/admin/catalog/products/' + item.id + '/packages', { onSuccess: () => packageForm.reset() })">
                            <label class="text-xs">Kode internal<Input v-model="packageForm.code" required class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Nama nominal<Input v-model="packageForm.name" required class="mt-1 block rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Badge<Input v-model="packageForm.note" maxlength="80" placeholder="Contoh: Populer" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Grup / Tabel<select v-if="item.package_tabs_enabled" v-model="packageForm.group_name" required class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Pilih tab</option><option v-for="tabName in item.package_tabs||[]" :key="tabName" :value="tabName">{{tabName}}</option></select><Input v-else v-model="packageForm.group_name" placeholder="Contoh: Weekly / Diamonds" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Nilai nominal<Input v-model.number="packageForm.nominal_value" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Urutan<Input v-model.number="packageForm.sort_order" type="number" min="0" required class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label>
                            <label v-if="item.fulfillment_mode === 'MANUAL'" class="text-xs">Modal Rp<Input v-model.number="packageForm.cost_idr" type="number" min="0" required class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <Button :disabled="packageForm.processing" class="rounded bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950">Tambah nominal</Button>
                            <span v-if="Object.keys(packageForm.errors).length" class="text-xs text-red-300">{{ Object.values(packageForm.errors).join(' · ') }}</span>
                        </form>
                    </div>
                </Card>
            </section>

            <section v-show="catalogTab === 'fields'" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Kolom data pelanggan</h2>
                <p class="text-sm text-slate-400">Atur data yang wajib atau opsional diisi pelanggan saat membeli produk. Urutan di halaman pelanggan mengikuti daftar di bawah.</p>
                <label class="block text-sm">Produk<select v-model="fieldsProductId" class="mt-1 w-full max-w-md rounded-md bg-slate-800 p-2"><option value="">Pilih produk</option><option v-for="item in products" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                <template v-if="fieldsProductId">
                    <Card v-for="(field, index) in fieldRows" :key="index" class="space-y-3 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong>Kolom {{ index + 1 }}</strong>
                            <div class="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" :disabled="index===0" :aria-label="'Naikkan kolom '+(index+1)" @click="moveField(index,-1)">Naik</Button>
                                <Button type="button" variant="outline" :disabled="index===fieldRows.length-1" :aria-label="'Turunkan kolom '+(index+1)" @click="moveField(index,1)">Turun</Button>
                                <Button type="button" variant="destructive" @click="fieldRows.splice(index,1)">Hapus</Button>
                            </div>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="text-sm">Nama kolom<Input v-model="field.label" maxlength="255" placeholder="Contoh: User ID" class="mt-1" /></label>
                            <label class="text-sm">Kode kolom<Input v-model="field.field_key" maxlength="80" placeholder="Contoh: user_id" class="mt-1" /><span class="mt-1 block text-xs text-slate-500">Huruf kecil, angka, dan garis bawah. Kode dipakai oleh cek nickname dan template pengiriman.</span></label>
                            <label class="text-sm">Contoh isian<Input v-model="field.placeholder" maxlength="255" placeholder="Contoh: 123456789" class="mt-1" /></label>
                            <label class="text-sm">Jenis isian<select v-model="field.type" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="text">Teks / ID</option><option value="tel">Nomor telepon</option><option value="email">Email</option></select></label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="field.is_required" type="checkbox"> Wajib diisi</label>
                        </div>
                    </Card>
                    <Button type="button" variant="outline" :disabled="fieldRows.length>=20" @click="addField">Tambah kolom</Button>
                    <Card class="space-y-3 p-4">
                        <h3 class="font-semibold">Pratinjau isian pelanggan</h3>
                        <p v-if="!fieldRows.length" class="text-sm text-slate-500">Belum ada kolom.</p>
                        <label v-for="(field,index) in fieldRows" :key="index" class="block text-sm">{{ field.label || 'Label kolom' }}{{ field.is_required ? ' *' : '' }}<Input :type="field.type" :placeholder="field.placeholder" disabled class="mt-1" /></label>
                    </Card>
                    <p v-if="fieldsError" role="alert" class="text-sm text-red-600">{{ fieldsError }}</p>
                    <Button type="button" :disabled="fieldsSaving" @click="saveFields">{{ fieldsSaving ? 'Menyimpan…' : 'Simpan kolom' }}</Button>
                </template>
            </section>


        </div>
    </AdminShell>
</template>
