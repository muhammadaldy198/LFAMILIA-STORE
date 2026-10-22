<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({ registry: Object, fields: Object, sections: Object, presentation: Object, theme: Object, themeFields: Object });
const registry = computed(() => props.registry || {});
const fields = computed(() => props.fields || {});
const sections = computed(() => props.sections || {});
const base = usePage().props.adminPanel?.base_path || '/admin';
const tab = ref('text'), query = ref(''), group = ref(''), textEditor = ref(null);

const openText = (key, item) => { textEditor.value = { key, item }; };
const groups = computed(() => [...new Set(Object.values(registry.value).map((x) => x.group))]);
const pageName = (value) => String(value).replace('Pages/', '').replace('Components/', 'Layout/');
const entries = computed(() => Object.entries(registry.value).filter(([, item]) =>
    (!group.value || item.group === group.value) && (!query.value || (item.label + ' ' + item.group).toLowerCase().includes(query.value.toLowerCase()))));

const sourceTypography = computed(() => props.presentation?.typography || {});
const initialTypography = Object.fromEntries(['mobile', 'desktop'].map((viewport) => [
    viewport,
    Object.fromEntries(Object.entries(fields.value).map(([key, definition]) => [
        key, sourceTypography.value?.[viewport]?.[key] ?? definition?.[viewport] ?? 0,
    ])),
]));

const form = useForm({
    text: { ...(props.presentation?.text || {}) },
    typography: initialTypography,
    sections: { ...(props.presentation?.sections || {}) },
});
const themeForm = useForm({ colors: { ...(props.theme || {}) } });
const themePreview = computed(() => Object.fromEntries(
    Object.entries(themeForm.colors).filter(([, value]) => /^#[0-9A-Fa-f]{6}$/.test(value)).map(([key, value]) => ['--lf-color-' + key, value]),
));

const tabs = [['text', 'Teks halaman'], ['typography', 'Font & Panel'], ['sections', 'Bagian Homepage'], ['theme', 'Warna Website']];
const save = () => {
    if (tab.value === 'theme') {
        if (!themeForm.processing) themeForm.put(base + '/content/theme', { preserveScroll: true });
        return;
    }
    if (!form.processing) form.put(base + '/content/presentation', { preserveScroll: true });
};
const defaultText = (key) => { delete form.text[key]; };
const restoreTheme = () => { themeForm.colors = Object.fromEntries(Object.entries(props.themeFields || {}).map(([key, value]) => [key, value.default])); };
const restoreTypography = () => {
    for (const viewport of ['mobile', 'desktop']) {
        for (const [field, definition] of Object.entries(fields.value)) form.typography[viewport][field] = definition[viewport];
    }
};
const busy = computed(() => form.processing || themeForm.processing);
</script>

<template>
    <Head title="Tampilan Toko" />
    <AdminShell>
        <div class="space-y-5 pb-20">
            <header class="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight">Tampilan Toko</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Tulisan, ukuran huruf, bagian halaman depan, dan warna yang dilihat pelanggan. Ponsel dan desktop punya pengaturan sendiri. Isi produk, berita, FAQ, dan kebijakan diatur di menunya masing-masing.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child><Link :href="base + '/content'">Banner & Konten</Link></Button>
                    <Button variant="outline" as-child><a href="/" target="_blank" rel="noreferrer">Lihat toko</a></Button>
                </div>
            </header>

            <nav class="lf-admin-tabs" aria-label="Bagian tampilan toko">
                <Button v-for="[key, label] in tabs" :key="key" type="button" :aria-current="tab === key ? 'page' : undefined" :class="{ active: tab === key }" @click="tab = key">{{ label }}</Button>
            </nav>

            <p v-if="$page.props.status" role="status" class="rounded-md border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-400">{{ $page.props.status }}</p>
            <p v-for="error in form.errors" :key="error" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{{ error }}</p>

            <form @submit.prevent="save">
                <section v-if="tab === 'text'" class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="text-sm">Halaman
                            <select v-model="group" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                                <option value="">Semua halaman</option>
                                <option v-for="item in groups" :key="item" :value="item">{{ pageName(item) }}</option>
                            </select>
                        </label>
                        <label class="text-sm">Cari tulisan<Input v-model="query" placeholder="Cari judul, tombol, atau informasi" class="mt-1" /></label>
                    </div>
                    <p class="text-xs text-muted-foreground">Kosongkan isian untuk memakai teks bawaan. Pilih satu baris untuk mengubahnya.</p>

                    <ul v-if="entries.length" class="divide-y divide-border rounded-md border border-border">
                        <li v-for="[key, item] in entries" :key="key">
                            <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="openText(key, item)">
                                <span class="min-w-0 flex-1">
                                    <strong class="block truncate">{{ item.label }}</strong>
                                    <small class="mt-0.5 block truncate text-muted-foreground">{{ form.text[key] ? form.text[key] : item.default }}</small>
                                </span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ form.text[key] ? 'Diubah' : 'Bawaan' }}</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">Tidak ada tulisan yang cocok dengan pencarian.</p>
                </section>

                <section v-else-if="tab === 'typography'" class="space-y-4">
                    <p class="text-xs text-muted-foreground">Ukuran dalam px. Ketebalan 400 = normal, 500 = medium, 600 = semibold, 700 = bold. Nama nominal dan harga memakai desainnya sendiri.</p>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div v-for="viewport in ['mobile', 'desktop']" :key="viewport" class="space-y-3 rounded-md border border-border p-4">
                            <h2 class="text-sm font-semibold">{{ viewport === 'mobile' ? 'Ponsel' : 'Desktop' }}</h2>
                            <label v-for="[key, field] in Object.entries(fields)" :key="key" class="grid grid-cols-[minmax(0,1fr)_100px] items-center gap-3 text-sm">
                                {{ field.label }}
                                <Input v-model.number="form.typography[viewport][key]" type="number" :min="field.min" :max="field.max" :step="field.step || 1" required />
                            </label>
                        </div>
                    </div>
                    <Button type="button" variant="outline" @click="restoreTypography">Kembalikan ukuran bawaan</Button>
                </section>

                <section v-else-if="tab === 'sections'" class="space-y-4">
                    <h2 class="text-sm font-semibold">Bagian halaman depan</h2>
                    <p class="text-xs text-muted-foreground">Matikan bagian yang tidak ingin ditampilkan. Banner dan pop-up punya tombol aktif sendiri di Banner & Konten.</p>
                    <div class="divide-y divide-border rounded-md border border-border">
                        <label v-for="[key, label] in Object.entries(sections)" :key="key" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                            {{ label }}<AdminSwitch v-model="form.sections[key]" />
                        </label>
                    </div>
                </section>

                <section v-else-if="tab === 'theme'" class="space-y-4">
                    <h2 class="text-sm font-semibold">Warna website</h2>
                    <p class="text-xs text-muted-foreground">Berlaku untuk halaman toko, checkout, dan pembayaran. Panel admin tetap gelap. Warna wajib HEX 6 digit dan kontras teks diperiksa server.</p>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <label v-for="[key, field] in Object.entries(themeFields || {})" :key="key" class="space-y-1.5 text-sm">
                            <span>{{ field.label }}</span>
                            <span class="flex gap-2">
                                <input v-model="themeForm.colors[key]" type="color" :aria-label="field.label" class="h-10 w-12 rounded border border-input bg-background" />
                                <Input v-model="themeForm.colors[key]" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required :aria-label="field.label + ' HEX'" />
                            </span>
                            <small v-if="themeForm.errors['colors.' + key]" role="alert" class="block text-xs text-destructive">{{ themeForm.errors['colors.' + key] }}</small>
                        </label>
                    </div>

                    <div class="rounded-md border border-border bg-card p-4">
                        <h3 class="text-sm font-semibold">Pratinjau</h3>
                        <div class="lf-theme-preview mt-3" :style="themePreview">
                            <h3>LFAMILIA</h3>
                            <p>Ini contoh teks utama dan teks sekunder.</p>
                            <div><span>Contoh kartu nominal</span><strong>Ringkasan Pesanan</strong></div>
                            <button type="button" disabled>Bayar Sekarang →</button>
                            <span class="lf-theme-success">Berhasil</span>
                            <span class="lf-theme-warning">Menunggu</span>
                            <span class="lf-theme-error">Gagal</span>
                        </div>
                    </div>
                    <Button type="button" variant="outline" @click="restoreTheme">Kembalikan warna bawaan</Button>
                </section>

                <div class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 px-4 py-3 backdrop-blur sm:static sm:mt-5 sm:rounded-md sm:border sm:px-4">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <span v-if="busy" class="mr-auto text-xs text-muted-foreground">Menyimpan…</span>
                        <Button :disabled="busy">{{ busy ? 'Menyimpan…' : 'Simpan perubahan' }}</Button>
                    </div>
                </div>
            </form>
        </div>

        <Sheet :open="Boolean(textEditor)" @update:open="(value) => { if (!value) textEditor = null; }">
            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>{{ textEditor?.item.label }}</SheetTitle>
                    <SheetDescription>{{ textEditor ? pageName(textEditor.item.group) : '' }}</SheetDescription>
                </SheetHeader>
                <div v-if="textEditor" class="mt-4 space-y-3">
                    <label class="lf-admin-field"><span>Teks yang tampil</span>
                        <Textarea v-model="form.text[textEditor.key]" rows="6" maxlength="5000" :placeholder="textEditor.item.default"></Textarea>
                    </label>
                    <p class="text-xs text-muted-foreground">Kosongkan untuk memakai teks bawaan.</p>
                    <div class="flex justify-between gap-2 border-t border-border pt-4">
                        <Button type="button" variant="outline" @click="defaultText(textEditor.key)">Gunakan bawaan</Button>
                        <Button type="button" @click="textEditor = null">Selesai</Button>
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    </AdminShell>
</template>
