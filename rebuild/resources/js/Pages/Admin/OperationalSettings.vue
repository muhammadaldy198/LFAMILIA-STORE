<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';

const props = defineProps({
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
});
const form = reactive({ ...props.values });
const busy = ref(false);
const save = () => {
    busy.value = true;
    router.put('/admin/operational-settings', { ...form }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
const reset = () => {
    for (const [key, field] of Object.entries(props.fields)) form[key] = field.default;
};
</script>

<template>
    <Head title="Pengaturan Operasional" />
    <AdminLayout>
        <header class="adm-head">
            <div><h1>Pengaturan Operasional</h1>
                <p>Atur durasi checkout, isi saldo, retensi kontak tamu, dan batas peringatan. Hanya Superadmin.</p>
            </div>
            <div class="adm-head-actions"><a href="/admin/settings" class="adm-btn">Kembali</a>
                <button type="button" class="adm-btn" @click="reset">Kembalikan bawaan</button>
                <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan</button>
            </div>
        </header>
        <section class="adm-card">
            <div class="adm-card-body">
                <p class="adm-note">Perubahan hanya berlaku untuk pesanan dan pengingat baru. Pesanan lama tetap memakai tanggal kedaluwarsa yang tersimpan. Jadwal internal dan aturan keamanan provider tidak diubah.</p>
                <div class="adm-grid">
                    <label v-for="(field, key) in fields" :key="key" class="adm-field">
                        <span>{{ field.label }}</span>
                        <input v-model.number="form[key]" type="number" class="adm-input" :min="field.min" :max="field.max" step="1" required>
                        <span class="hint">Minimum {{ field.min }}, maksimum {{ field.max }}. Bawaan {{ field.default }}.</span>
                    </label>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>
