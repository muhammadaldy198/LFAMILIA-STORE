<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
});

const modal = ref(false);
const editing = ref(null);
const busy = ref(false);
const errors = ref({});
const form = reactive({ name: '', slug: '', sort_order: 0, is_active: true, icon: 'grid' });
const advanced = ref(false);

const openCreate = () => {
    editing.value = null;
    Object.assign(form, { name: '', slug: '', sort_order: 0, is_active: true, icon: 'grid' });
    errors.value = {};
    advanced.value = false;
    modal.value = true;
};

const openEdit = (row) => {
    editing.value = row;
    Object.assign(form, {
        name: row.name, slug: row.slug || '', sort_order: row.sort_order ?? 0,
        is_active: Boolean(row.is_active), icon: row.icon || 'grid',
    });
    errors.value = {};
    advanced.value = false;
    modal.value = true;
};

const save = () => {
    const options = {
        preserveScroll: true,
        onStart: () => { busy.value = true; },
        onFinish: () => { busy.value = false; },
        onSuccess: () => { modal.value = false; },
        onError: (bag) => { errors.value = bag; },
    };
    if (editing.value) router.put(`/admin/catalog/categories/${editing.value.id}`, { ...form }, options);
    else router.post('/admin/catalog/categories', { ...form }, options);
};

// Setelah unggah/hapus gambar, salinan kategori di pop-up disegarkan dari data server.
watch(() => props.categories, () => {
    if (!editing.value) return;
    const fresh = props.categories.find(row => row.id === editing.value.id);
    if (fresh) editing.value = fresh;
});

const imageBusy = ref(false);
const uploadImage = (event) => {
    const file = event.target.files?.[0];
    if (!file || !editing.value) return;
    imageBusy.value = true;
    router.post(`/admin/catalog/media/category/${editing.value.id}`, { image: file, collection: 'image' }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { imageBusy.value = false; event.target.value = ''; },
    });
};
const removeImage = () => {
    if (!editing.value) return;
    router.delete(`/admin/catalog/media/category/${editing.value.id}`, {
        data: { collection: 'image' }, preserveScroll: true,
    });
};

const removeTarget = ref(null);
const remove = () => {
    if (!removeTarget.value) return;
    router.delete(`/admin/catalog/categories/${removeTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { removeTarget.value = null; },
    });
};
</script>

<template>
    <Head title="Kategori" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Kategori</h1>
                <p>Kategori mengelompokkan produk di toko. Urutan lebih kecil tampil lebih dulu.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/catalog" class="adm-btn">Daftar produk</Link>
                <button type="button" class="adm-btn primary" @click="openCreate">Tambah kategori</button>
            </div>
        </header>

        <div class="adm-grid two">
            <article v-for="row in categories" :key="row.id" class="adm-card" style="margin:0">
                <div class="adm-card-body">
                    <div style="display:flex;gap:10px;align-items:flex-start">
                        <img v-if="row.image_url" :src="row.image_url" :alt="row.name" style="width:48px;height:48px;object-fit:contain;border-radius:7px;border:1px solid var(--a-line);background:#fafbf9">
                        <div style="min-width:0;flex:1">
                            <strong style="font-size:13.5px">{{ row.name }}</strong>
                            <p class="adm-note">{{ row.products_count }} produk · posisi {{ row.sort_order }}</p>
                            <p class="adm-note">{{ row.slug }}</p>
                        </div>
                        <span class="adm-tag" :class="row.is_active ? 'ok' : 'plain'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>
                    <div style="display:flex;gap:6px;margin-top:10px">
                        <button type="button" class="adm-btn sm" @click="openEdit(row)">Ubah</button>
                        <button type="button" class="adm-btn sm danger" @click="removeTarget = row">Hapus</button>
                    </div>
                </div>
            </article>
            <p v-if="!categories.length" class="adm-empty">Belum ada kategori. Tambahkan kategori pertama.</p>
        </div>

        <AdminModal v-model:open="modal" :title="editing ? 'Ubah kategori' : 'Tambah kategori'" :description="editing ? editing.slug : 'Kategori baru tidak tampil di toko sampai diaktifkan.'">
            <form id="category-form" class="adm-grid" @submit.prevent="save">
                <label class="adm-field full">
                    <span>Nama kategori <b class="req">*</b></span>
                    <input v-model="form.name" class="adm-input" maxlength="255" required>
                    <span v-if="errors.name" class="err">{{ errors.name }}</span>
                </label>
                <label class="adm-field">
                    <span>Posisi di toko</span>
                    <input v-model="form.sort_order" type="number" min="0" class="adm-input">
                    <span class="hint">Angka lebih kecil tampil lebih dulu.</span>
                    <span v-if="errors.sort_order" class="err">{{ errors.sort_order }}</span>
                </label>
                <label class="adm-switch-row">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" />
                    Aktif di toko
                </label>

                <div class="full">
                    <button type="button" class="adm-btn link" @click="advanced = !advanced">Ubah alamat halaman kategori (lanjutan)</button>
                </div>
                <label v-if="advanced" class="adm-field full">
                    <span>Alamat halaman</span>
                    <input v-model="form.slug" class="adm-input" placeholder="contoh: top-up-game" maxlength="255">
                    <span class="hint">Kosongkan untuk dibuat otomatis dari nama.</span>
                    <span v-if="errors.slug" class="err">{{ errors.slug }}</span>
                </label>

                <div v-if="editing" class="adm-field full">
                    <span>Gambar kategori</span>
                    <p class="adm-note">Rekomendasi 512×512 piksel, PNG atau WebP transparan. Bila tidak ada gambar, kartu di toko tampil tanpa gambar.</p>
                    <img v-if="editing.image_url" :src="editing.image_url" :alt="editing.name" style="width:96px;height:96px;object-fit:contain;border:1px solid var(--a-line);border-radius:7px;background:#fafbf9;margin-top:6px">
                    <p v-else class="adm-note">Belum ada gambar.</p>
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="adm-input" style="padding:5px;margin-top:6px" :disabled="imageBusy" @change="uploadImage">
                    <button v-if="editing.image_url" type="button" class="adm-btn sm danger" style="justify-self:start;margin-top:6px" :disabled="imageBusy" @click="removeImage">Hapus gambar</button>
                </div>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="modal = false">Batal</button>
                <button type="submit" form="category-form" class="adm-btn primary" :disabled="busy">Simpan</button>
            </template>
        </AdminModal>

        <AdminModal :open="!!removeTarget" title="Hapus kategori" :description="removeTarget?.name || ''" @update:open="value => { if (!value) removeTarget = null; }">
            <p class="adm-note">Kategori yang masih memiliki produk tidak dapat dihapus. Pindahkan produknya lebih dulu.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="removeTarget = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="remove">Hapus kategori</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
