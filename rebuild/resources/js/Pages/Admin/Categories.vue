<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';

const props = defineProps({ categories: { type: Array, default: () => [] } });
const iconOptions = [['gamepad', 'Game'], ['ticket', 'Voucher'], ['play', 'Hiburan'], ['smartphone', 'Pulsa / Data'], ['zap', 'PLN / Listrik'], ['grid', 'Umum']];
const iconLabel = (value) => (iconOptions.find(([key]) => key === value)?.[1] ?? 'Umum');

const selected = ref(null);
const creating = ref(false);
const deleting = ref(null);
const error = ref('');

const form = useForm({ name: '', slug: '', icon: 'grid', sort_order: props.categories.length, is_active: true });

const openCreate = () => {
    error.value = '';
    form.reset();
    form.clearErrors();
    form.icon = 'grid';
    form.is_active = true;
    form.sort_order = props.categories.length;
    selected.value = null;
    creating.value = true;
};

const openEdit = (item) => {
    error.value = '';
    form.clearErrors();
    form.defaults({
        name: item.name, slug: item.slug || '', icon: item.icon || 'grid',
        sort_order: item.sort_order, is_active: item.is_active,
    });
    form.reset();
    creating.value = false;
    selected.value = item;
};

const close = () => { creating.value = false; selected.value = null; form.clearErrors(); };

const save = () => {
    error.value = '';
    const options = { preserveScroll: true, onSuccess: close, onError: (errors) => { error.value = Object.values(errors).join(' · '); } };
    if (creating.value) form.post('/admin/catalog/categories', options);
    else if (selected.value) form.put('/admin/catalog/categories/' + selected.value.id, options);
};

const remove = () => {
    const item = deleting.value;
    deleting.value = null;
    if (!item) return;
    router.delete('/admin/catalog/categories/' + item.id, {
        preserveScroll: true,
        onSuccess: () => { if (selected.value?.id === item.id) close(); },
        onError: (errors) => { error.value = Object.values(errors).join(' · '); },
    });
};
</script>

<template>
    <Head title="Kategori" />
    <AdminShell>
        <div class="space-y-5">
            <header class="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight">Kategori</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Pengelompokan produk di halaman toko. Urutan di sini menentukan posisi kategori: angka lebih kecil tampil lebih dulu.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child><Link href="/admin/catalog">Daftar produk</Link></Button>
                    <Button @click="openCreate">Tambah kategori</Button>
                </div>
            </header>

            <p v-if="error && !creating && !selected" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</p>

            <div v-if="!categories.length" role="status" class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Belum ada kategori. Tambahkan kategori pertama supaya produk bisa dikelompokkan di toko.
            </div>

            <ul v-else class="divide-y divide-border rounded-md border border-border">
                <li v-for="item in categories" :key="item.id">
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="openEdit(item)">
                        <span class="min-w-0 flex-1">
                            <strong class="block truncate">{{ item.name }}</strong>
                            <small class="mt-0.5 block text-muted-foreground">{{ iconLabel(item.icon) }} · {{ item.products_count }} produk · posisi {{ item.sort_order }}</small>
                        </span>
                        <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', item.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">
                            {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                    </button>
                </li>
            </ul>
        </div>

        <Sheet :open="creating || Boolean(selected)" @update:open="value => { if (!value) close(); }">
            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>{{ creating ? 'Tambah kategori' : 'Ubah kategori' }}</SheetTitle>
                    <SheetDescription>Nama, ikon, gambar, dan posisi kategori di halaman toko.</SheetDescription>
                </SheetHeader>

                <form class="mt-4 space-y-4" @submit.prevent="save">
                    <label class="lf-admin-field"><span>Nama kategori</span>
                        <Input v-model="form.name" required placeholder="Contoh: Mobile Legends" />
                        <span v-if="form.errors.name" role="alert" class="text-xs font-normal text-destructive">{{ form.errors.name }}</span>
                    </label>

                    <label class="lf-admin-field"><span>Ikon bila belum ada gambar</span>
                        <select v-model="form.icon" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option v-for="[value, label] in iconOptions" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </label>

                    <label class="lf-admin-field"><span>Posisi di toko</span>
                        <Input v-model.number="form.sort_order" type="number" min="0" required />
                        <span class="text-xs font-normal text-muted-foreground">Angka lebih kecil tampil lebih dulu.</span>
                    </label>

                    <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="form.is_active" /> Aktif di toko</label>

                    <details class="text-sm">
                        <summary class="cursor-pointer text-muted-foreground">Ubah alamat halaman kategori (lanjutan)</summary>
                        <label class="lf-admin-field mt-2"><span>Bagian alamat halaman</span>
                            <Input v-model="form.slug" placeholder="Dibuat otomatis dari nama" />
                        </label>
                    </details>

                    <div v-if="selected" class="rounded-md border border-border p-3">
                        <strong class="text-xs">Gambar kategori</strong>
                        <p class="mt-1 text-[11px] text-muted-foreground">Rekomendasi 512×512 (1:1). Dipakai sebagai ikon/tab kategori di toko.</p>
                        <div class="mt-2"><AdminMediaControl type="category" :id="selected.id" :url="selected.image_url" /></div>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">Gambar bisa diunggah setelah kategori disimpan.</p>

                    <p v-if="error" role="alert" class="text-sm text-destructive">{{ error }}</p>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        <Button v-if="selected" type="button" variant="destructive" @click="deleting = selected">Hapus</Button>
                        <Button type="button" variant="outline" @click="close">Batal</Button>
                        <Button type="submit" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan' }}</Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <AdminConfirmDialog
            :open="Boolean(deleting)"
            title="Hapus kategori"
            :message="deleting ? 'Kategori ' + deleting.name + ' akan dihapus. Kategori yang masih memiliki produk akan ditolak oleh sistem.' : ''"
            confirm-label="Hapus kategori"
            destructive
            @update:open="value => { if (!value) deleting = null; }"
            @confirm="remove"
        />
    </AdminShell>
</template>
