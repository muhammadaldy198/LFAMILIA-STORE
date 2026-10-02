<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { computed, reactive, ref, watch } from 'vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';

const props = defineProps({ categories: Array, products: Array, assets: Array });
const cloneProducts = (items) => items.map((item) => ({
    ...item,
    packages: item.packages.map((pack) => ({
        ...pack,
        mappings: pack.mappings.map((mapping) => ({ ...mapping })),
    })),
    notices: (item.notices || []).map((notice) => ({ ...notice })),
}));
const categories = ref(props.categories.map((item) => ({ ...item })));
const products = ref(cloneProducts(props.products));
const assets = ref(props.assets.map((item) => ({ ...item })));
watch(() => props.categories, (items) => { categories.value = items.map((item) => ({ ...item })); });
watch(() => props.products, (items) => { products.value = cloneProducts(items); });
watch(() => props.assets, (items) => { assets.value = items.map((item) => ({ ...item })); });

const page = usePage();
const initialSearch = new URLSearchParams(page.url.split('?')[1] || '').get('q') || '';
const searchedProduct = products.value.find(item => item.name.toLowerCase().includes(initialSearch.toLowerCase()));
const tab = ref(initialSearch && searchedProduct ? searchedProduct.fulfillment_mode : 'AUTO_PROVIDER');
const catalogTab = ref('products'), catalogSearch = ref(initialSearch), catalogPage = ref(1);
const catalogTabs = [['products','Produk'],['categories','Kategori'],['fields','Kolom Data Akun'],['media','Media Toko']];
const matchingProducts = computed(() => products.value.filter(item => item.fulfillment_mode === tab.value && (!catalogSearch.value || [item.name,item.slug,...item.packages.map(p => p.name)].join(' ').toLowerCase().includes(catalogSearch.value.toLowerCase()))));
const totalCatalogPages = computed(() => Math.max(1, Math.ceil(matchingProducts.value.length / 10)));
const visibleProducts = computed(() => matchingProducts.value.slice((catalogPage.value - 1) * 10, catalogPage.value * 10));
watch([tab,catalogSearch], () => {catalogPage.value = 1;});
const categoryForm = useForm({ name: '', sort_order: 0 });
const productForm = useForm({ category_id: '', name: '', publisher: '', description: '', fulfillment_mode: 'AUTO_PROVIDER', manual_instructions: '', manual_open_time: '', manual_close_time: '', manual_timezone: 'Asia/Jakarta', margin_percent: 0, sort_order: 0 });
const packageForm = useForm({ product_id: '', code: '', name: '', note: '', group_name: '', nominal_value: '', sort_order: 0, cost_idr: '' });
const noticeDrafts = reactive({});
const fieldsProductId = ref('');
const fieldsText = ref('');
watch(fieldsProductId, (id) => {
    const product = products.value.find((item) => String(item.id) === String(id));
    fieldsText.value = product?.fields.map((field) => [field.field_key, field.label, field.placeholder || '', field.type, field.is_required ? '1' : '0'].join('|')).join('\n') ?? '';
});
const fieldsError = ref('');
const saveFields = () => {
    const fields = fieldsText.value.trim() ? fieldsText.value.trim().split('\n').map((line) => {
        const parts = line.split('|').map((part) => part.trim());
        const [field_key, label] = parts;
        const legacyFourColumns = parts.length === 4;
        const placeholder = legacyFourColumns ? '' : (parts[2] || '');
        const type = legacyFourColumns ? parts[2] : parts[3];
        const required = legacyFourColumns ? parts[3] : parts[4];
        return { field_key, label, placeholder: placeholder || null, type, is_required: required === '1' };
    }) : [];
    fieldsError.value = '';
    router.put('/admin/catalog/products/' + fieldsProductId.value + '/fields', { fields }, {
        onError: (errors) => { fieldsError.value = Object.values(errors).join(' · '); },
    });
};
const saveCategory = (item) => router.put('/admin/catalog/categories/' + item.id, { name: item.name, sort_order: item.sort_order, is_active: item.is_active });
const deleteCategory = (item) => {
    if (confirm('Hapus kategori "' + item.name + '"? Kategori yang masih memiliki produk tidak akan bisa dihapus.')) {
        router.delete('/admin/catalog/categories/' + item.id, { preserveScroll: true });
    }
};
const saveProduct = (item) => router.put('/admin/catalog/products/' + item.id, {
    category_id: item.category_id, name: item.name, publisher: item.publisher || '', description: item.description,
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
});
const saveMapping = (mapping) => router.put('/admin/catalog/mappings/' + mapping.id, {
    priority: mapping.priority, is_active: mapping.is_active,
    ...(mapping.provider_code === 'MANUAL' ? { cost_idr: mapping.cost_idr } : {}),
    ...(mapping.provider_code === 'DIGIFLAZZ' ? { customer_no_template: mapping.customer_no_template || null } : {}),
});
const saveAsset = (asset) => router.put('/admin/catalog/assets/' + asset.id, { is_active: asset.is_active, target_url: asset.target_url || null });
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
    if (confirm('Hapus notice produk ini?')) router.delete('/admin/catalog/notices/' + notice.id, { preserveScroll: true });
};
</script>

<template>
    <Head title="Kelola katalog" />
    <AdminShell>
        <div class="mx-auto max-w-7xl space-y-8">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><Link href="/admin/panel" class="text-sm text-cyan-300">← Panel Admin</Link><h1 class="mt-2 text-3xl font-bold">Katalog & media</h1></div><Link href="/" class="text-sm text-cyan-300">Lihat katalog pelanggan</Link></div>
            <p class="text-sm text-slate-400">Produk baru tidak langsung aktif. SKU Digiflazz hanya masuk melalui sinkronisasi provider; pengaturan integrasi menyusul di M9.</p>

            <nav class="lf-admin-tabs"><Button v-for="[key,label] in catalogTabs" :key="key" type="button" :class="{active:catalogTab===key}" @click="catalogTab=key">{{label}}</Button></nav>
            <section v-show="catalogTab === 'categories'" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Kategori</h2>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="categoryForm.post('/admin/catalog/categories', { onSuccess: () => categoryForm.reset() })">
                    <label class="text-sm">Nama kategori<Input v-model="categoryForm.name" required class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Urutan<Input v-model.number="categoryForm.sort_order" type="number" min="0" required class="mt-1 block w-24 rounded-md bg-slate-800 p-2" /></label>
                    <Button :disabled="categoryForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah</Button>
                    <span v-if="categoryForm.errors.name" class="text-sm text-red-300">{{ categoryForm.errors.name }}</span>
                </form>
                <div v-for="item in categories" :key="item.id" class="space-y-3 border-t border-slate-800 pt-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Urutan<Input v-model.number="item.sort_order" type="number" min="0" class="mt-1 block w-24 rounded-md bg-slate-800 p-2" /></label>
                        <label class="flex gap-2 text-sm"><input v-model="item.is_active" type="checkbox">Aktif</label>
                        <Button type="button" class="rounded-md bg-slate-700 px-3 py-2 text-sm" @click="saveCategory(item)">Simpan</Button>
                        <Button type="button" class="rounded-md bg-red-50 px-3 py-2 text-sm font-semibold text-red-700" @click="deleteCategory(item)">Hapus</Button>
                        <span class="text-xs text-slate-500">/{{ item.slug }}</span>
                    </div>
                    <div class="rounded-md border border-slate-200 p-3">
                        <strong class="text-xs">Gambar kategori</strong>
                        <p class="mt-1 text-[11px] text-slate-500">Rekomendasi 512×512 (1:1). Dipakai sebagai ikon/tab kategori.</p>
                        <div class="mt-2"><AdminMediaControl type="category" :id="item.id" :url="item.image_url" /></div>
                    </div>
                </div>
            </section>

            <section v-show="catalogTab === 'products'" class="space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Produk</h2><label class="block">Cari produk atau nominal<Input v-model="catalogSearch" placeholder="Nama produk, slug, atau nominal" /></label><nav class="flex items-center gap-3"><Button type="button" :disabled="catalogPage <= 1" @click="catalogPage--">Sebelumnya</Button><span>{{catalogPage}} / {{totalCatalogPages}} · {{matchingProducts.length}} produk</span><Button type="button" :disabled="catalogPage >= totalCatalogPages" @click="catalogPage++">Berikutnya</Button></nav>
                <div class="flex gap-2"><Button v-for="mode in ['AUTO_PROVIDER', 'MANUAL']" :key="mode" type="button" class="rounded-md px-4 py-2 text-sm" :class="tab === mode ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800'" @click="tab = mode">{{ mode === 'MANUAL' ? 'Produk Manual' : 'Produk Provider' }}</Button></div>
                <form class="grid gap-3 md:grid-cols-3" @submit.prevent="productForm.fulfillment_mode = tab; productForm.post('/admin/catalog/products', { onSuccess: () => productForm.reset() })">
                    <label class="text-sm">Kategori<select v-model="productForm.category_id" required class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="">Pilih kategori</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                    <label class="text-sm">Nama produk<Input v-model="productForm.name" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Publisher<Input v-model="productForm.publisher" placeholder="Contoh: Moonton" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Margin persen<Input v-model.number="productForm.margin_percent" type="number" step="0.0001" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm md:col-span-2">Deskripsi<Textarea v-model="productForm.description" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label class="text-sm">Urutan<Input v-model.number="productForm.sort_order" type="number" min="0" required class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <label v-if="tab === 'MANUAL'" class="text-sm md:col-span-3">Instruksi fulfillment internal<Textarea v-model="productForm.manual_instructions" rows="2" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                    <template v-if="tab === 'MANUAL'">
                        <label class="text-sm">Jam buka<Input v-model="productForm.manual_open_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Jam tutup<Input v-model="productForm.manual_close_time" type="time" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Zona waktu<select v-model="productForm.manual_timezone" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="Asia/Jakarta">WIB — Asia/Jakarta</option><option value="Asia/Makassar">WITA — Asia/Makassar</option><option value="Asia/Jayapura">WIT — Asia/Jayapura</option></select></label>
                    </template>
                    <div class="md:col-span-3"><Button :disabled="productForm.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Tambah {{ tab === 'MANUAL' ? 'produk manual' : 'produk provider' }}</Button><p v-if="Object.keys(productForm.errors).length" class="mt-2 text-sm text-red-300">{{ Object.values(productForm.errors).join(' · ') }}</p></div>
                </form>
                <div v-for="item in visibleProducts" :key="item.id" class="space-y-4 border-t border-slate-700 pt-5">
                    <div class="grid gap-3 md:grid-cols-4">
                        <label class="text-sm">Nama<Input v-model="item.name" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Publisher<Input v-model="item.publisher" placeholder="Publisher / brand game" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
                        <label class="text-sm">Kategori<select v-model="item.category_id" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                        <label class="text-sm">Margin %<Input v-model.number="item.margin_percent" type="number" step="0.0001" min="0" class="mt-1 block w-full rounded-md bg-slate-800 p-2" /></label>
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
                                <label class="text-xs">Game code<Input v-model="item.nickname_game_code" placeholder="mobile-legends" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Field User ID<select v-model="item.nickname_user_field_key" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Pilih field</option><option v-for="field in item.fields" :key="field.field_key" :value="field.field_key">{{ field.label }} ({{ field.field_key }})</option></select></label>
                                <label class="text-xs">Field Server / Zone<select v-model="item.nickname_server_field_key" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="">Tidak dipakai</option><option v-for="field in item.fields" :key="field.field_key" :value="field.field_key">{{ field.label }} ({{ field.field_key }})</option></select></label>
                            </div>
                            <p class="text-xs text-slate-500">Credential layanan nickname tetap dikelola di Integrasi; customer tidak melihat nama provider.</p>
                        </div>
                    </div>
                    <Button type="button" class="rounded-md bg-slate-700 px-4 py-2 text-sm" @click="saveProduct(item)">Simpan produk</Button>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-md border border-slate-200 p-3"><strong class="text-xs">Gambar produk / card</strong><div class="mt-2"><AdminMediaControl type="product" :id="item.id" :url="item.image_url" /></div></div>
                        <div class="rounded-md border border-slate-200 p-3"><strong class="text-xs">Banner halaman produk</strong><div class="mt-2"><AdminMediaControl type="product" :id="item.id" collection="banner" :url="item.banner_url" /></div></div>
                    </div>
                    <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4">
                        <div><h3 class="font-semibold">Notice produk</h3><p class="mt-1 text-xs text-slate-500">Informasi publik yang tampil di checkout, terpisah dari instruksi fulfillment internal.</p></div>
                        <article v-for="notice in item.notices" :key="notice.id" class="grid gap-2 rounded border border-slate-200 p-3 md:grid-cols-[1fr_110px_auto]">
                            <Input v-model="notice.title" class="rounded border border-slate-200 p-2 text-xs" placeholder="Judul" />
                            <Input v-model.number="notice.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-xs" placeholder="Urutan" />
                            <label class="flex items-center gap-2 text-xs"><input v-model="notice.is_active" type="checkbox"> Aktif</label>
                            <Textarea v-model="notice.body" rows="2" class="rounded border border-slate-200 p-2 text-xs md:col-span-3" placeholder="Isi notice"></Textarea>
                            <div class="flex gap-2 md:col-span-3"><Button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs text-white" @click="saveNotice(notice)">Simpan</Button><Button type="button" class="rounded bg-red-50 px-3 py-2 text-xs font-semibold text-red-700" @click="deleteNotice(notice)">Hapus</Button></div>
                        </article>
                        <div class="grid gap-2 rounded border border-dashed border-slate-300 p-3 md:grid-cols-[1fr_110px_auto]">
                            <Input v-model="noticeDraft(item.id).title" class="rounded border border-slate-200 p-2 text-xs" placeholder="Judul notice baru" />
                            <Input v-model.number="noticeDraft(item.id).sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-xs" placeholder="Urutan" />
                            <label class="flex items-center gap-2 text-xs"><input v-model="noticeDraft(item.id).is_active" type="checkbox"> Aktif</label>
                            <Textarea v-model="noticeDraft(item.id).body" rows="2" class="rounded border border-slate-200 p-2 text-xs md:col-span-3" placeholder="Informasi yang dilihat customer"></Textarea>
                            <Button type="button" class="w-fit rounded bg-[#1769e8] px-3 py-2 text-xs font-bold text-white md:col-span-3" @click="addNotice(item)">Tambah Notice</Button>
                        </div>
                    </div>

                    <div class="space-y-3 rounded-md bg-slate-950 p-4">
                        <h3 class="font-semibold">Nominal / paket</h3>
                        <div v-for="pack in item.packages" :key="pack.id" class="space-y-2 border-t border-slate-800 pt-3">
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="text-xs">Kode internal<Input v-model="pack.code" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Nama nominal<Input v-model="pack.name" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Badge<Input v-model="pack.note" maxlength="80" placeholder="Contoh: Populer" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Grup / Tabel<Input v-model="pack.group_name" placeholder="Contoh: Diamonds" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Nilai nominal<Input v-model.number="pack.nominal_value" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label class="text-xs">Urutan<Input v-model.number="pack.sort_order" type="number" min="0" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label>
                                <label class="flex gap-2 text-xs"><input v-model="pack.is_active" type="checkbox">Aktif</label>
                                <Button type="button" class="rounded bg-slate-700 px-3 py-2 text-xs" @click="savePackage(pack)">Simpan</Button>
                            </div>
                            <div class="rounded border border-slate-700 p-2">
                                <p class="mb-2 text-[11px] text-slate-400">Gambar nominal: rekomendasi 512×512 (1:1), PNG/WebP transparan bila memungkinkan.</p>
                                <AdminMediaControl type="package" :id="pack.id" :url="pack.image_url" />
                            </div>
                            <div v-for="mapping in pack.mappings" :key="mapping.id" class="flex flex-wrap items-end gap-2 text-xs text-slate-300">
                                <span>{{ mapping.provider_code }}<span v-if="mapping.external_sku"> · {{ mapping.external_sku }}</span></span>
                                <label v-if="mapping.provider_code === 'MANUAL'">Modal Rp<Input v-model.number="mapping.cost_idr" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                                <label v-if="mapping.provider_code === 'DIGIFLAZZ'" class="min-w-72">Template customer_no<Input v-model="mapping.customer_no_template" placeholder="{{user_id}}{{zone_id}}" class="mt-1 block w-full rounded bg-slate-800 p-2" /><span class="mt-1 block text-[11px] text-slate-500">Gunakan placeholder field produk. Jika hanya satu field, boleh dikosongkan.</span></label>
                                <label>Prioritas<Input v-model.number="mapping.priority" type="number" min="0" class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label>
                                <label class="flex gap-2"><input v-model="mapping.is_active" type="checkbox">Aktif</label>
                                <Button type="button" class="rounded bg-slate-700 px-3 py-2" @click="saveMapping(mapping)">Simpan mapping</Button>
                            </div>
                        </div>
                        <form class="flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3" @submit.prevent="packageForm.post('/admin/catalog/products/' + item.id + '/packages', { onSuccess: () => packageForm.reset() })">
                            <label class="text-xs">Kode internal<Input v-model="packageForm.code" required class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Nama nominal<Input v-model="packageForm.name" required class="mt-1 block rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Badge<Input v-model="packageForm.note" maxlength="80" placeholder="Contoh: Populer" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Grup / Tabel<Input v-model="packageForm.group_name" placeholder="Contoh: Weekly / Diamonds" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Nilai nominal<Input v-model.number="packageForm.nominal_value" type="number" min="0" class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <label class="text-xs">Urutan<Input v-model.number="packageForm.sort_order" type="number" min="0" required class="mt-1 block w-20 rounded bg-slate-800 p-2" /></label>
                            <label v-if="item.fulfillment_mode === 'MANUAL'" class="text-xs">Modal Rp<Input v-model.number="packageForm.cost_idr" type="number" min="0" required class="mt-1 block w-28 rounded bg-slate-800 p-2" /></label>
                            <Button :disabled="packageForm.processing" class="rounded bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950">Tambah nominal</Button>
                            <span v-if="Object.keys(packageForm.errors).length" class="text-xs text-red-300">{{ Object.values(packageForm.errors).join(' · ') }}</span>
                        </form>
                    </div>
                </div>
            </section>

            <section v-show="catalogTab === 'fields'" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Field input produk</h2>
                <p class="text-sm text-slate-400">Satu baris per field: kode|label|placeholder|tipe|wajib (1/0). Tipe: text, tel, email. Contoh: user_id|User ID|Contoh: 123456789|text|1. Format lama 4 kolom tetap diterima.</p>
                <select v-model="fieldsProductId" class="w-full max-w-md rounded-md bg-slate-800 p-2"><option value="">Pilih produk</option><option v-for="item in products" :key="item.id" :value="item.id">{{ item.name }}</option></select>
                <Textarea v-if="fieldsProductId" v-model="fieldsText" rows="5" aria-label="Daftar field input" class="block w-full rounded-md bg-slate-800 p-3 font-mono text-sm" />
                <p v-if="fieldsError" class="text-sm text-red-300">{{ fieldsError }}</p>
                <Button v-if="fieldsProductId" type="button" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950" @click="saveFields">Simpan field</Button>
            </section>

            <section v-show="catalogTab === 'media'" id="media" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Media toko</h2>
                <div v-for="asset in assets" :key="asset.id" class="space-y-2 border-t border-slate-800 pt-3">
                    <div class="flex flex-wrap items-end gap-3"><strong>{{ asset.key }}</strong><label class="flex gap-2 text-sm"><input v-model="asset.is_active" type="checkbox">Aktif</label><label v-if="asset.key.startsWith('banner')" class="text-sm">Tautan banner<Input v-model="asset.target_url" type="url" class="mt-1 block rounded bg-slate-800 p-2" /></label><Button type="button" class="rounded bg-slate-700 px-3 py-2 text-sm" @click="saveAsset(asset)">Simpan</Button></div>
                    <AdminMediaControl type="asset" :asset-key="asset.key" :id="asset.id" :url="asset.image_url" />
                </div>
            </section>
        </div>
    </AdminShell>
</template>
