<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';

const props = defineProps({
    emailEvents: { type: Object, default: () => ({}) },
    notificationEvents: { type: Array, default: () => [] },
    templates: { type: Object, default: () => ({}) },
});
const currentTab = ref('emails');
const emailHint = '{{default_subject}} dan {{default_body}}';
const notificationHint = '{{default_title}} dan {{default_body}}';
const storeNameToken = '{{store_name}}';
const busy = ref(false);
const emails = reactive(Object.fromEntries(Object.keys(props.emailEvents).map(key => [
    key, {
        subject: props.templates.emails?.[key]?.subject ?? '',
        body: props.templates.emails?.[key]?.body ?? '',
    },
])));
const notifications = reactive(Object.fromEntries(props.notificationEvents.map(key => [
    key, {
        title: props.templates.notifications?.[key]?.title ?? '',
        body: props.templates.notifications?.[key]?.body ?? '',
    },
])));
const save = () => {
    busy.value = true;
    router.put('/admin/message-templates', { emails: { ...emails }, notifications: { ...notifications } }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
const testEmail = () => router.post('/admin/message-templates/test-email', {}, { preserveScroll: true });
const reset = (obj, key) => { obj[key] = { ...Object.fromEntries(Object.keys(obj[key]).map(k => [k, ''])) }; };
</script>

<template>
    <Head title="Template Email & Notifikasi" />
    <AdminLayout>
        <header class="adm-head">
            <div><h1>Template Email &amp; Notifikasi</h1>
                <p>Atur kata pengantar, judul, dan penutup. Data invoice, URL keamanan, dan pesan bawaan penting tidak dapat dihapus.</p>
            </div>
            <div class="adm-head-actions">
                <a href="/admin/settings" class="adm-btn">Pengaturan toko</a>
                <button type="button" class="adm-btn" @click="testEmail">Kirim tes ke email admin</button>
                <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan semua</button>
            </div>
        </header>
        <div class="adm-tabs" role="tablist" aria-label="Jenis template">
            <button type="button" role="tab" :aria-selected="currentTab === 'emails'" @click="currentTab = 'emails'">Email pelanggan</button>
            <button type="button" role="tab" :aria-selected="currentTab === 'notifications'" @click="currentTab = 'notifications'">Notifikasi admin</button>
        </div>
        <p class="adm-note">Gunakan <code class="adm-code" v-text="currentTab === 'emails' ? emailHint : notificationHint" />
            untuk mempertahankan teks bawaan. Variabel <code class="adm-code" v-text="storeNameToken" /> tersedia untuk email.
            Kosongkan kolom untuk kembali ke teks bawaan. Perubahan dicatat di log audit.</p>
        <template v-if="currentTab === 'emails'">
            <section v-for="(label, key) in emailEvents" :key="key" class="adm-card">
                <div class="adm-card-head"><div><h2>{{ label }}</h2></div>
                    <button type="button" class="adm-btn sm" @click="reset(emails, key)">Reset</button></div>
                <div class="adm-card-body">
                    <label class="adm-field"><span>Subjek</span>
                        <input v-model="emails[key].subject" class="adm-input" maxlength="255" placeholder="{{default_subject}}" /></label>
                    <label class="adm-field"><span>Isi pesan (dapat tambah teks sebelum/sesudah isi bawaan)</span>
                        <textarea v-model="emails[key].body" class="adm-textarea" rows="4" maxlength="10000" placeholder="{{default_body}}" /></label>
                </div>
            </section>
        </template>
        <template v-else>
            <p v-if="!notificationEvents.length" class="adm-note">Belum ada jenis notifikasi yang tersimpan. Jenis baru akan muncul setelah sistem mengirim notifikasi.</p>
            <section v-for="key in notificationEvents" :key="key" class="adm-card">
                <div class="adm-card-head"><div><h2>{{ key }}</h2></div>
                    <button type="button" class="adm-btn sm" @click="reset(notifications, key)">Reset</button></div>
                <div class="adm-card-body">
                    <label class="adm-field"><span>Judul</span>
                        <input v-model="notifications[key].title" class="adm-input" maxlength="255" placeholder="{{default_title}}" /></label>
                    <label class="adm-field"><span>Isi</span>
                        <textarea v-model="notifications[key].body" class="adm-textarea" rows="4" maxlength="10000" placeholder="{{default_body}}" /></label>
                </div>
            </section>
        </template>
    </AdminLayout>
</template>
