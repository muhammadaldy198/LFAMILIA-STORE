<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';

const props = defineProps({ products: Array });
const page = usePage();
const base = page.props.adminPanel?.base_path || '/staff';
const products = ref((props.products || []).map((item) => ({
    ...item,
    packages: (item.packages || []).map((pack) => ({ ...pack })),
    notices: (item.notices || []).map((notice) => ({ ...notice })),
})));
watch(() => props.products, (items) => {
    products.value = (items || []).map((item) => ({
        ...item,
        packages: (item.packages || []).map((pack) => ({ ...pack })),
        notices: (item.notices || []).map((notice) => ({ ...notice })),
    }));
});
const noticeDrafts = reactive({});
const draft = (id) => noticeDrafts[id] || (noticeDrafts[id] = { title: '', body: '', sort_order: 0, is_active: true });

function saveProduct(item) {
    router.put(base + '/catalog/products/' + item.id + '/content', {
        publisher: item.publisher || null,
        description: item.description || null,
        manual_instructions: item.manual_instructions || null,
        manual_open_time: item.manual_open_time || null,
        manual_close_time: item.manual_close_time || null,
        manual_timezone: item.manual_timezone || 'Asia/Jakarta',
    }, { preserveScroll: true });
}
function savePackage(item) {
    router.put(base + '/catalog/packages/' + item.id + '/content', {
        name: item.name,
        note: item.note || null,
        group_name: item.group_name || null,
    }, { preserveScroll: true });
}
function addNotice(product) {
    const value = draft(product.id);
    if (!value.title.trim() || !value.body.trim()) return;
    router.post(base + '/catalog/products/' + product.id + '/notices', value, {
        preserveScroll: true,
        onSuccess: () => { noticeDrafts[product.id] = { title: '', body: '', sort_order: 0, is_active: true }; },
    });
}
function saveNotice(notice) {
    router.put(base + '/catalog/notices/' + notice.id, {
        title: notice.title,
        body: notice.body,
        sort_order: Number(notice.sort_order || 0),
        is_active: Boolean(notice.is_active),
    }, { preserveScroll: true });
}
function deleteNotice(notice) {
    if (confirm('Hapus notice produk ini?')) router.delete(base + '/catalog/notices/' + notice.id, { preserveScroll: true });
}
</script>

<template>
<Head title="Konten Produk" />
<AdminShell>
<div class="mx-auto max-w-7xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold">Konten Produk</h1>
        <p class="mt-1 text-sm text-slate-400">Workspace STAFF untuk gambar, deskripsi, instruksi, jam layanan, badge nominal, dan notice. Harga, provider, SKU, margin, serta routing tidak tersedia di halaman ini.</p>
    </div>

    <article v-for="product in products" :key="product.id" class="space-y-5 rounded-xl border border-slate-800 bg-slate-900 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-semibold">{{product.name}}</h2><p class="text-xs text-slate-500">{{product.fulfillment_mode}} · {{product.is_active ? 'Aktif' : 'Nonaktif'}}</p></div>
            <button class="rounded bg-slate-700 px-4 py-2 text-sm" @click="saveProduct(product)">Simpan konten produk</button>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <label class="text-sm">Publisher<input v-model="product.publisher" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <label class="mt-3 block text-sm">Deskripsi<textarea v-model="product.description" rows="5" class="mt-1 block w-full rounded bg-slate-800 p-2"></textarea></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <AdminMediaControl type="product" :id="product.id" collection="image" :url="product.image_url" />
                <AdminMediaControl type="product" :id="product.id" collection="banner" :url="product.banner_url" />
            </div>
        </div>

        <section v-if="product.fulfillment_mode === 'MANUAL'" class="rounded-lg border border-slate-700 p-4">
            <h3 class="font-semibold">Instruksi & jam layanan manual</h3>
            <textarea v-model="product.manual_instructions" rows="4" class="mt-3 block w-full rounded bg-slate-800 p-2" placeholder="Instruksi internal / operasional produk"></textarea>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <label class="text-xs">Jam buka<input v-model="product.manual_open_time" type="time" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <label class="text-xs">Jam tutup<input v-model="product.manual_close_time" type="time" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <label class="text-xs">Zona waktu<select v-model="product.manual_timezone" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="Asia/Jakarta">WIB</option><option value="Asia/Makassar">WITA</option><option value="Asia/Jayapura">WIT</option></select></label>
            </div>
        </section>

        <section class="space-y-3">
            <h3 class="font-semibold">Nominal & gambar</h3>
            <div v-for="pack in product.packages" :key="pack.id" class="grid gap-3 rounded-lg border border-slate-800 p-3 lg:grid-cols-[1fr_1fr_140px_320px]">
                <label class="text-xs">Nama nominal<input v-model="pack.name" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <label class="text-xs">Grup<input v-model="pack.group_name" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <label class="text-xs">Badge<input v-model="pack.note" maxlength="80" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <div class="space-y-2"><AdminMediaControl type="package" :id="pack.id" :url="pack.image_url" /><button class="rounded bg-slate-700 px-3 py-2 text-xs" @click="savePackage(pack)">Simpan nominal</button></div>
            </div>
        </section>

        <section class="space-y-3 rounded-lg border border-slate-700 p-4">
            <h3 class="font-semibold">Notice produk</h3>
            <div v-for="notice in product.notices" :key="notice.id" class="grid gap-2 border-t border-slate-800 pt-3 md:grid-cols-[1fr_2fr_90px_auto]">
                <input v-model="notice.title" class="rounded bg-slate-800 p-2" placeholder="Judul">
                <textarea v-model="notice.body" rows="2" class="rounded bg-slate-800 p-2" placeholder="Isi notice"></textarea>
                <label class="text-xs">Urutan<input v-model.number="notice.sort_order" type="number" min="0" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                <div class="flex items-center gap-2"><label class="text-xs"><input v-model="notice.is_active" type="checkbox"> Aktif</label><button class="rounded bg-slate-700 px-3 py-2 text-xs" @click="saveNotice(notice)">Simpan</button><button class="text-xs text-red-300" @click="deleteNotice(notice)">Hapus</button></div>
            </div>
            <div class="grid gap-2 border-t border-slate-800 pt-3 md:grid-cols-[1fr_2fr_90px_auto]">
                <input v-model="draft(product.id).title" class="rounded bg-slate-800 p-2" placeholder="Judul notice baru">
                <textarea v-model="draft(product.id).body" rows="2" class="rounded bg-slate-800 p-2" placeholder="Isi notice"></textarea>
                <input v-model.number="draft(product.id).sort_order" type="number" min="0" class="rounded bg-slate-800 p-2">
                <button class="rounded bg-cyan-300 px-3 py-2 text-xs font-semibold text-slate-950" @click="addNotice(product)">Tambah</button>
            </div>
        </section>
    </article>
</div>
</AdminShell>
</template>
