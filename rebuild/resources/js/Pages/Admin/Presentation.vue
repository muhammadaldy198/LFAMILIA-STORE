<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
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
const tab = ref('text'), query = ref(''), group = ref('');
const groups = computed(() => [...new Set(Object.values(registry.value).map(x => x.group))]);
const entries = computed(() => Object.entries(registry.value).filter(([, item]) =>
    (!group.value || item.group === group.value) && (!query.value || (item.label + ' ' + item.group).toLowerCase().includes(query.value.toLowerCase()))));
const sourceTypography = props.presentation?.typography || {};
const initialTypography = Object.fromEntries(['mobile', 'desktop'].map(viewport => [
    viewport,
    Object.fromEntries(Object.entries(fields.value).map(([key, definition]) => [
        key, sourceTypography?.[viewport]?.[key] ?? definition?.[viewport] ?? 0,
    ])),
]));
const form = useForm({
    text: { ...(props.presentation?.text || {}) },
    typography: initialTypography,
    sections: { ...(props.presentation?.sections || {}) },
});
const themeForm = useForm({colors:{...(props.theme || {})}});
const themePreview = computed(() => Object.fromEntries(Object.entries(themeForm.colors).filter(([,value])=>/^#[0-9A-Fa-f]{6}$/.test(value)).map(([key,value])=>['--lf-color-'+key,value])));
const restoreTheme = () => {themeForm.colors=Object.fromEntries(Object.entries(props.themeFields || {}).map(([key,value])=>[key,value.default]));};
const save = () => { if(tab.value === 'theme') {if(!themeForm.processing)themeForm.put(base + '/content/theme', {preserveScroll:true}); return;}  if (!form.processing) form.put(base + '/content/presentation', { preserveScroll: true }); };
const defaultText = key => { delete form.text[key]; };
const restoreTypography = () => {
    for (const viewport of ['mobile', 'desktop']) for (const [field, definition] of Object.entries(fields.value)) form.typography[viewport][field] = definition[viewport];
};
</script>
<template>
<Head title="Teks & Tampilan Customer" />
<AdminShell>
 <div class="lf-admin-page-heading"><div><h1>Teks & Tampilan Customer</h1><p>Kelola tulisan, font, panel, input, dan bagian homepage. Mobile dan desktop memiliki pengaturan terpisah.</p></div><Link :href="base + '/content'" class="lf-admin-button">Banner & Konten</Link></div>
 <nav class="lf-admin-tabs" aria-label="Pengaturan customer">
  <Button v-for="[key, label] in [['text','Teks halaman'],['typography','Font & Panel'],['sections','Bagian Homepage'],['theme','Warna Website']]" :key="key" type="button" :class="{active: tab === key}" @click="tab = key">{{ label }}</Button>
 </nav>
 <form @submit.prevent="save" class="lf-admin-editor">
  <p v-if="$page.props.status" role="status" class="lf-admin-success">{{ $page.props.status }}</p>
  <p v-for="error in form.errors" :key="error" role="alert" class="text-red-600">{{ error }}</p>
  <section v-if="tab === 'text'">
   <div class="lf-admin-filter-row"><label>Halaman<select v-model="group"><option value="">Semua halaman</option><option v-for="item in groups" :key="item" :value="item">{{ item.replace('Pages/','').replace('Components/','Layout/') }}</option></select></label><label>Cari tulisan<Input v-model="query" placeholder="Cari judul, tombol, atau informasi" /></label></div>
   <p class="lf-admin-note">Kosongkan perubahan untuk memakai teks bawaan. Produk, nominal, berita, FAQ dan isi kebijakan dikelola melalui menu masing-masing.</p>
   <div v-for="[key, item] in entries" :key="key" class="lf-admin-copy-field">
    <label>{{ item.label }}<Textarea :value="form.text[key] ?? item.default" rows="2" maxlength="5000" @input="form.text[key] = $event.target.value"></Textarea></label>
    <div><small>{{ item.group.replace('Pages/','').replace('Components/','Layout/') }}</small><Button type="button" @click="defaultText(key)">Gunakan bawaan</Button></div>
   </div>
   <p v-if="!entries.length">Tidak ada tulisan yang cocok.</p>
  </section>
  <section v-else-if="tab === 'typography'">
   <p class="lf-admin-note">Ukuran dalam px. Ketebalan 400 = normal, 500 = medium, 600 = semibold, 700 = bold. Nama nominal dan harga mempertahankan desain tersendiri.</p>
   <div class="lf-admin-typography-grid"><div><h2>Mobile</h2><label v-for="[key, field] in Object.entries(fields)" :key="key">{{ field.label }}<Input v-model.number="form.typography.mobile[key]" type="number" :min="field.min" :max="field.max" :step="field.step || 1" required /></label></div><div><h2>Desktop</h2><label v-for="[key, field] in Object.entries(fields)" :key="key">{{ field.label }}<Input v-model.number="form.typography.desktop[key]" type="number" :min="field.min" :max="field.max" :step="field.step || 1" required /></label></div></div>
   <Button type="button" class="lf-admin-button" @click="restoreTypography">Kembalikan ukuran bawaan</Button>
  </section>
  <section v-else-if="tab==='sections'"><h2>Bagian Homepage</h2><label v-for="[key, label] in Object.entries(sections)" :key="key" class="lf-admin-switch-field"><AdminSwitch v-model="form.sections[key]" />{{ label }}</label><p class="lf-admin-note">Banner dan pop-up memiliki tombol aktif masing-masing di Banner & Konten.</p></section>
  <section v-else-if="tab==='theme'">
   <h2>Warna Website</h2><p>Palet ini berlaku untuk halaman customer, checkout, dan pembayaran. Panel admin tetap putih. Ukuran checkout mengikuti desain final.</p>
   <div class="lf-theme-fields"><label v-for="[key, field] in Object.entries(themeFields || {})" :key="key">{{field.label}}<span><input v-model="themeForm.colors[key]" type="color" :aria-label="field.label"/><Input v-model="themeForm.colors[key]" maxlength="7" pattern="#[0-9A-Fa-f]{6}" required :aria-label="field.label+' HEX'"/></span><small v-if="themeForm.errors['colors.'+key]" role="alert">{{themeForm.errors['colors.'+key]}}</small></label></div>
   <div class="lf-theme-preview" :style="themePreview"><h3>Preview LFAMILIA</h3><p>Ini contoh teks utama dan teks sekunder.</p><div><span>Contoh kartu nominal</span><strong>Ringkasan Pesanan</strong></div><button type="button" disabled>Bayar Sekarang →</button><span class="lf-theme-success">Berhasil</span><span class="lf-theme-warning">Menunggu</span><span class="lf-theme-error">Gagal</span></div>
   <Button type="button" @click="restoreTheme">Kembalikan warna bawaan</Button><p class="lf-admin-note">Klik Simpan Perubahan untuk menerapkan palet. Warna wajib HEX 6 digit dan kontras teks akan diperiksa server.</p>
  </section>
  <div class="lf-admin-save-bar"><Button class="lf-admin-primary" :disabled="form.processing || themeForm.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan Perubahan' }}</Button><a href="/" target="_blank" rel="noreferrer" class="lf-admin-button">Lihat Toko</a></div>
 </form>
</AdminShell>
</template>
