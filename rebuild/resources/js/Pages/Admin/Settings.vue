<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    canExport: Boolean,
    summary: { type: Object, default: () => ({}) },
});

const form = reactive({
    store_name: props.settings['store.name'] || '',
    tagline: props.settings['store.tagline'] || '',
    seo_title: props.settings['store.seo_title'] || '',
    seo_description: props.settings['store.seo_description'] || '',
    support_whatsapp: props.settings['store.support_whatsapp'] || '',
    email: props.settings['store.email'] || '',
    business_hours: props.settings['store.business_hours'] || '',
    instagram_url: props.settings['store.instagram_url'] || '',
    discord_url: props.settings['store.discord_url'] || '',
    support_url: props.settings['store.support_url'] || '',
    legal_name: props.settings['store.legal_name'] || '',
    registration_id: props.settings['store.registration_id'] || '',
    address: props.settings['store.address'] || '',
});

watch(() => props.settings, value => {
    Object.assign(form, {
        store_name: value['store.name'] || '',
        tagline: value['store.tagline'] || '',
        seo_title: value['store.seo_title'] || '',
        seo_description: value['store.seo_description'] || '',
        support_whatsapp: value['store.support_whatsapp'] || '',
        email: value['store.email'] || '',
        business_hours: value['store.business_hours'] || '',
        instagram_url: value['store.instagram_url'] || '',
        discord_url: value['store.discord_url'] || '',
        support_url: value['store.support_url'] || '',
        legal_name: value['store.legal_name'] || '',
        registration_id: value['store.registration_id'] || '',
        address: value['store.address'] || '',
    });
}, { deep: true });

const busy = ref(false);
const save = () => {
    busy.value = true;
    router.put('/admin/settings', { ...form }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
</script>

<template>
    <Head title="Pengaturan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Pengaturan</h1>
                <p>Identitas toko dan kontak yang tampil di halaman pelanggan.</p>
            </div>
            <div class="adm-head-actions">
                <a href="/admin/message-templates" class="adm-btn">Template Email &amp; Notifikasi</a>
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
            </div>
        </header>



        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Toko &amp; kontak</h2></div></div>
            <div class="adm-card-body">
                <strong>Identitas toko</strong>
                <div class="adm-grid" style="margin-top:6px">
                    <label class="adm-field">
                        <span>Nama toko</span>
                        <input v-model="form.store_name" class="adm-input" minlength="2" maxlength="80" required>
                        <span class="hint">2–80 karakter.</span>
                    </label>
                    <label class="adm-field">
                        <span>Tagline</span>
                        <input v-model="form.tagline" class="adm-input" minlength="3" maxlength="160" required>
                        <span class="hint">3–160 karakter.</span>
                    </label>
                </div>

                <strong style="display:block;margin-top:14px">SEO Website</strong>
                <div class="adm-grid" style="margin-top:6px">
                    <label class="adm-field"><span>Judul SEO bawaan</span>
                        <input v-model="form.seo_title" class="adm-input" maxlength="100" placeholder="Nama toko" />
                    </label>
                    <label class="adm-field"><span>Deskripsi SEO bawaan</span>
                        <textarea v-model="form.seo_description" class="adm-textarea" maxlength="160" rows="2" placeholder="Deskripsi toko untuk mesin pencari" />
                    </label>
                </div>

                <strong style="display:block;margin-top:14px">Kontak</strong>
                <div class="adm-grid" style="margin-top:6px">
                    <label class="adm-field">
                        <span>WhatsApp</span>
                        <input v-model="form.support_whatsapp" class="adm-input" maxlength="32" placeholder="Contoh: 08123456789">
                        <span class="hint">Angka, spasi, tanda + atau - saja.</span>
                    </label>
                    <label class="adm-field">
                        <span>Email</span>
                        <input v-model="form.email" type="email" class="adm-input" maxlength="255" placeholder="bantuan@lfamiliastore.my.id">
                    </label>
                    <label class="adm-field">
                        <span>Jam layanan</span>
                        <input v-model="form.business_hours" class="adm-input" minlength="3" maxlength="160" required placeholder="Contoh: Setiap hari 08.00–22.00 WIB">
                        <span class="hint">Teks bebas, 3–160 karakter.</span>
                    </label>
                    <label class="adm-field">
                        <span>Instagram</span>
                        <input v-model="form.instagram_url" type="url" class="adm-input" maxlength="500" placeholder="https://instagram.com/namatoko">
                        <span class="hint">Harus memakai domain instagram.com.</span>
                    </label>
                    <label class="adm-field">
                        <span>Discord</span>
                        <input v-model="form.discord_url" type="url" class="adm-input" maxlength="500" placeholder="https://discord.gg/undangan">
                        <span class="hint">Harus memakai domain discord.com atau discord.gg.</span>
                    </label>
                    <label class="adm-field">
                        <span>Tautan bantuan</span>
                        <input v-model="form.support_url" class="adm-input" maxlength="500" placeholder="/bantuan atau https://…">
                        <span class="hint">Path internal atau alamat http/https.</span>
                    </label>
                </div>

                <strong style="display:block;margin-top:14px">Identitas usaha</strong>
                <div class="adm-grid" style="margin-top:6px">
                    <label class="adm-field">
                        <span>Nama legal</span>
                        <input v-model="form.legal_name" class="adm-input" maxlength="255">
                    </label>
                    <label class="adm-field">
                        <span>Nomor registrasi</span>
                        <input v-model="form.registration_id" class="adm-input" maxlength="120">
                    </label>
                    <label class="adm-field full">
                        <span>Alamat</span>
                        <textarea v-model="form.address" class="adm-textarea" rows="2" maxlength="1000" />
                        <span class="hint">Dua baris alamat usaha. Kosongkan bila tidak ditampilkan.</span>
                    </label>
                </div>

                <div style="display:flex;justify-content:flex-end;margin-top:12px">
                    <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan pengaturan toko</button>
                </div>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Ekspor pengaturan</h2>
                    <p>Unduh salinan pengaturan toko dalam satu berkas JSON. Kredensial integrasi dan kolom sensitif tidak disertakan.</p>
                </div>
            </div>
            <div class="adm-card-body">
                <p class="adm-note">
                    Berkas memuat pengaturan toko, katalog, promo, dan konten yang tidak sensitif.
                    Setiap unduhan dicatat di Catatan Audit sebagai <code class="adm-code">configuration.exported</code>.
                </p>
                <div style="display:flex;justify-content:flex-end;margin-top:10px">
                    <a v-if="canExport" href="/admin/configuration/export" class="adm-btn primary">Unduh pengaturan</a>
                    <p v-else class="adm-note">Hanya Super Admin yang bisa mengunduh pengaturan.</p>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>
