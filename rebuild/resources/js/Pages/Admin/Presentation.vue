<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
const props = defineProps({ registry: Object, fields: Object, sections: Object, presentation: Object });
const base = usePage().props.adminPanel?.base_path || '/admin';
const tab = ref('text'), query = ref(''), group = ref('');
const groups = computed(() => [...new Set(Object.values(props.registry).map(x => x.group))]);
const entries = computed(() => Object.entries(props.registry).filter(([, item]) =>
    (!group.value || item.group === group.value) && (!query.value || (item.label + ' ' + item.group).toLowerCase().includes(query.value.toLowerCase()))));
const form = useForm({
    text: { ...props.presentation.text },
    typography: JSON.parse(JSON.stringify(props.presentation.typography)),
    sections: { ...props.presentation.sections },
});
const save = () => { if (!form.processing) form.put(base + '/content/presentation', { preserveScroll: true }); };
const defaultText = key => { delete form.text[key]; };
const restoreTypography = () => {
    for (const viewport of ['mobile', 'desktop']) for (const [field, definition] of Object.entries(props.fields)) form.typography[viewport][field] = definition[viewport];
};
</script>
<template>
<Head title="Teks & Tampilan Customer" />
<AdminShell>
 <div class="lf-admin-page-heading"><div><h1>Teks & Tampilan Customer</h1><p>Kelola tulisan, font, panel, input, dan bagian homepage. Mobile dan desktop memiliki pengaturan terpisah.</p></div><Link :href="base + '/content'" class="lf-admin-button">Banner & Konten</Link></div>
 <nav class="lf-admin-tabs" aria-label="Pengaturan customer">
  <Button v-for="[key, label] in [['text','Teks halaman'],['typography','Font & Panel'],['sections','Bagian Homepage']]" :key="key" type="button" :class="{active: tab === key}" @click="tab = key">{{ label }}</Button>
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
  <section v-else><h2>Bagian Homepage</h2><label v-for="[key, label] in Object.entries(sections)" :key="key" class="lf-admin-switch-field"><input v-model="form.sections[key]" type="checkbox">{{ label }}</label><p class="lf-admin-note">Banner dan pop-up memiliki tombol aktif masing-masing di Banner & Konten.</p></section>
  <div class="lf-admin-save-bar"><Button class="lf-admin-primary" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan Perubahan' }}</Button><a href="/" target="_blank" rel="noreferrer" class="lf-admin-button">Lihat Toko</a></div>
 </form>
</AdminShell>
</template>
