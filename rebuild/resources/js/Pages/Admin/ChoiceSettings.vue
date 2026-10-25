<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';

const props = defineProps({ choices: { type: Object, default: () => ({}) } });
const sections = [
    { key: 'cancellations', title: 'Alasan pembatalan', help: 'Pembatalan dan penyelesaian dana tetap mengikuti aturan sistem.' },
    { key: 'support', title: 'Kategori tiket bantuan', help: 'Pilihan ini dipakai dalam panel layanan pelanggan.' },
];
const rows = ref({
    cancellations: (props.choices.cancellations || []).map(value => ({ ...value })),
    support: (props.choices.support || []).map(value => ({ ...value })),
});
const busy = ref(false);
const generatedCode = (name, section) => {
    const slug = String(name).normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-zA-Z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 30);
    return section === 'cancellations' ? slug.toLowerCase() : slug.toUpperCase();
};
const append = section => {
    rows.value[section].push({ code: '', label: '', active: true });
};
const save = () => {
    for (const section of sections) {
        for (const entry of rows.value[section.key]) {
            if (!entry.code) entry.code = generatedCode(entry.label, section.key);
        }
    }
    busy.value = true;
    router.put('/admin/choice-settings', rows.value, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
</script>

<template>
    <Head title="Alasan & Kategori" />
    <AdminLayout>
        <header class="adm-head">
            <div><h1>Alasan &amp; Kategori</h1><p>Ubah label, susunan, dan status pilihan tanpa menghapus histori lama.</p></div>
            <div class="adm-head-actions">
                <a href="/admin/settings" class="adm-btn">Pengaturan toko</a>
                <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan perubahan</button>
            </div>
        </header>
        <section v-for="section in sections" :key="section.key" class="adm-card">
            <div class="adm-card-head">
                <div><h2>{{ section.title }}</h2><p>{{ section.help }}</p></div>
                <button type="button" class="adm-btn" @click="append(section.key)">Tambah pilihan</button>
            </div>
            <div class="adm-card-body">
                <div v-for="(item, i) in rows[section.key]" :key="i" class="adm-grid" style="margin-bottom:12px">
                    <label class="adm-field">
                        <span>Nama pilihan</span>
                        <input v-model="item.label" class="adm-input" maxlength="80" required>
                    </label>
                    <label class="adm-field">
                        <span>Kode (otomatis jika baru)</span>
                        <input v-model="item.code" class="adm-input" maxlength="30" :readonly="props.choices[section.key]?.some(old => old.code === item.code)" placeholder="Terisi saat disimpan">
                    </label>
                    <label class="adm-field">
                        <span>Aktif</span>
                        <input v-model="item.active" type="checkbox" :disabled="item.code === 'lainnya' || item.code === 'GENERAL'">
                    </label>
                    <div class="adm-head-actions">
                        <button type="button" class="adm-btn sm" :disabled="i === 0" @click="[rows[section.key][i - 1], rows[section.key][i]] = [rows[section.key][i], rows[section.key][i - 1]]">Naik</button>
                        <button type="button" class="adm-btn sm" :disabled="i === rows[section.key].length - 1" @click="[rows[section.key][i + 1], rows[section.key][i]] = [rows[section.key][i], rows[section.key][i + 1]]">Turun</button>
                    </div>
                </div>
                <p class="adm-note">Kode lama dikunci karena dipakai histori. Nonaktifkan pilihan yang sudah tidak ingin ditampilkan.</p>
            </div>
        </section>
    </AdminLayout>
</template>
