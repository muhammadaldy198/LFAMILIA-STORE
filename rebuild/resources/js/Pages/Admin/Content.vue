<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import AdminMedia from '../../Components/Admin/AdminMedia.vue';

const props = defineProps({
    assets: { type: Array, default: () => [] },
    banners: { type: Array, default: () => [] },
    popups: { type: Array, default: () => [] },
    news: { type: Array, default: () => [] },
    faqs: { type: Array, default: () => [] },
    pages: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
});

const AREAS = [
    { key: 'storefront', label: 'Tampilan Toko', tabs: [
        ['banners', 'Banner Utama'], ['popup', 'Pop-up Pengumuman'],
        ['assets', 'Logo & Gambar Cadangan'], ['support', 'Teks Footer & Bantuan'],
    ] },
    { key: 'legal', label: 'Bantuan & Kebijakan', tabs: [['faq', 'Pertanyaan Umum (FAQ)'], ['legal', 'Kebijakan Toko']] },
    { key: 'news', label: 'Berita & Ulasan', tabs: [['news', 'Berita'], ['reviews', 'Ulasan Pelanggan']] },
];

const area = ref('storefront');
const tab = ref('banners');
const setArea = (key) => {
    area.value = key;
    tab.value = AREAS.find(item => item.key === key)?.tabs[0][0] || '';
};

const ASSET_LABEL = {
    logo: 'Logo toko', favicon: 'Favicon',
    banner_desktop: 'Banner cadangan desktop', banner_mobile: 'Banner cadangan mobile',
    footer_banner_desktop: 'Gambar footer desktop', footer_banner_mobile: 'Gambar footer mobile',
};
const PAGE_LABEL = {
    about: 'Tentang kami', terms: 'Syarat dan ketentuan', privacy: 'Kebijakan privasi',
    refund: 'Kebijakan pengembalian', contact: 'Kontak', faq: 'Bantuan',
};

// ---------- satu pop-up untuk semua suntingan ----------
const modal = ref(false);
const editing = ref(null);
const form = reactive({});
const errors = ref({});
const busy = ref(false);

const open = (type, row = null) => {
    editing.value = { type, row };
    errors.value = {};
    for (const key of Object.keys(form)) delete form[key];
    const source = row ? { ...row } : DEFAULTS[type]();
    Object.assign(form, source);
    modal.value = true;
};

const DEFAULTS = {
    banner: () => ({ title: '', subtitle: '', cta_label: '', cta_href: '', show_desktop: true, show_mobile: true, sort_order: 0, is_active: true }),
    popup: () => ({ title: '', body: '', dismiss_days: 7, is_active: true }),
    news: () => ({ slug: '', title: '', summary: '', body: '', source_label: 'LFAMILIA News', sort_order: 0, published_at: '', is_active: false }),
    faq: () => ({ question: '', answer: '', sort_order: 0, is_active: true }),
    page: () => ({ title: '', intro: '', body: '', is_active: true }),
    asset: () => ({ target_url: '', is_active: true }),
    review: () => ({ is_active: true }),
};

const URLS = {
    banner: '/admin/content/banners', popup: '/admin/content/popups', news: '/admin/content/news',
    faq: '/admin/content/faqs', review: '/admin/content/reviews',
};

const save = () => {
    const type = editing.value.type;
    const row = editing.value.row;
    const options = {
        preserveScroll: true,
        onStart: () => { busy.value = true; },
        onFinish: () => { busy.value = false; },
        onSuccess: () => { modal.value = false; },
        onError: bag => { errors.value = bag; },
    };
    if (type === 'review') {
        router.put(`/admin/content/reviews/${row.id}`, { is_active: !!form.is_active }, options);
    } else if (type === 'asset') {
        router.put(`/admin/catalog/assets/${row.id}`, { is_active: !!form.is_active, target_url: form.target_url || null }, options);
    } else if (type === 'page') {
        router.put(`/admin/content/pages/${row.key}`, {
            title: form.title, intro: form.intro || '', body: form.body || '', is_active: !!form.is_active,
        }, options);
    } else if (row) {
        router.put(`${URLS[type]}/${row.id}`, { ...form }, options);
    } else {
        router.post(URLS[type], { ...form }, options);
    }
};

const removeTarget = ref(null);
const remove = () => {
    const { type, row } = removeTarget.value;
    router.delete(`${URLS[type]}/${row.id}`, {
        preserveScroll: true,
        onFinish: () => { removeTarget.value = null; },
    });
};

// ---------- pengaturan teks bantuan & footer ----------
const support = reactive({
    support_widget_enabled: Boolean(props.settings?.['store.support_widget_enabled'] ?? true),
    support_cta_enabled: Boolean(props.settings?.['store.support_cta_enabled'] ?? true),
    support_cta_label: props.settings?.['store.support_cta_label'] || '',
    support_cta_title: props.settings?.['store.support_cta_title'] || '',
    support_cta_body: props.settings?.['store.support_cta_body'] || '',
    support_cta_button: props.settings?.['store.support_cta_button'] || '',
    footer_description: props.settings?.['store.footer_description'] || '',
    home_news_title: props.settings?.['store.home_news_title'] || '',
    home_news_intro: props.settings?.['store.home_news_intro'] || '',
});
const supportBusy = ref(false);
const saveSupport = () => {
    router.put('/admin/content/settings', { ...support }, {
        preserveScroll: true,
        onStart: () => { supportBusy.value = true; },
        onFinish: () => { supportBusy.value = false; },
    });
};

// ---------- saringan ulasan ----------
const reviewQuery = ref('');
const reviewRating = ref('');
const reviewStatus = ref('');
const reviewRows = computed(() => props.reviews.filter(row => {
    const term = reviewQuery.value.trim().toLowerCase();
    const matchTerm = !term
        || String(row.display_name || '').toLowerCase().includes(term)
        || String(row.product_name || '').toLowerCase().includes(term)
        || String(row.body || '').toLowerCase().includes(term);
    const matchRating = !reviewRating.value || Number(row.rating) === Number(reviewRating.value);
    const matchStatus = !reviewStatus.value || (reviewStatus.value === 'tampil' ? row.is_active : !row.is_active);
    return matchTerm && matchRating && matchStatus;
}));

const toggleReview = (row) => {
    router.put(`/admin/content/reviews/${row.id}`, { is_active: !row.is_active }, { preserveScroll: true });
};

const modalTitle = computed(() => {
    const type = editing.value?.type;
    const isNew = !editing.value?.row;
    const names = {
        banner: 'banner', popup: 'pop-up', news: 'berita', faq: 'FAQ', page: 'kebijakan',
        asset: 'aset', review: 'ulasan',
    };
    return `${isNew ? 'Tambah' : 'Ubah'} ${names[type] || ''}`.trim();
});
</script>

<template>
    <Head title="Banner & Konten" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Banner &amp; Konten</h1>
                <p>Kelola isi halaman toko yang dibaca pelanggan: banner, pop-up, logo, bantuan, kebijakan, berita, dan moderasi ulasan.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/content/presentation" class="adm-btn">Tampilan Toko</Link>
                <a href="/" target="_blank" rel="noreferrer" class="adm-btn">Lihat toko</a>
            </div>
        </header>

        <div class="adm-tabs" role="tablist" aria-label="Bagian konten">
            <button v-for="item in AREAS" :key="item.key" type="button" role="tab"
                :aria-selected="area === item.key" @click="setArea(item.key)">{{ item.label }}</button>
        </div>

        <div class="adm-tabs" role="tablist" aria-label="Sub bagian konten" style="margin-bottom:10px">
            <button v-for="[key, text] in (AREAS.find(item => item.key === area)?.tabs || [])" :key="key"
                type="button" role="tab" :aria-selected="tab === key" @click="tab = key">{{ text }}</button>
        </div>

        <!-- Banner Utama -->
        <section v-if="tab === 'banners'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Banner halaman utama</h2>
                </div>
                <button type="button" class="adm-btn primary" @click="open('banner')">Tambah banner</button>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Banner</th><th>Urutan</th><th>Tampil</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in banners" :key="row.id">
                            <td>
                                <strong>{{ row.title || 'Banner' }}</strong>
                                <div class="adm-note">{{ row.subtitle || 'Tanpa teks kecil' }}</div>
                            </td>
                            <td>{{ row.sort_order }}</td>
                            <td class="adm-note">
                                {{ [row.show_desktop ? 'desktop' : '', row.show_mobile ? 'ponsel' : ''].filter(Boolean).join(' + ') || 'Tidak tampil' }}
                            </td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <button type="button" class="adm-btn sm" @click="open('banner', row)">Ubah</button>
                                    <button type="button" class="adm-btn sm danger" @click="removeTarget = { type: 'banner', row }">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!banners.length" class="adm-empty">Belum ada banner utama. Selama kosong, halaman pelanggan memakai gambar banner cadangan yang aktif.</p>
        </section>

        <!-- Pop-up -->
        <section v-if="tab === 'popup'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Pop-up halaman utama</h2>

                </div>
                <button v-if="!popups.length" type="button" class="adm-btn primary" @click="open('popup')">Tambah pop-up</button>
            </div>
            <div v-for="row in popups" :key="row.id" class="adm-card-body">
                <div style="display:flex;gap:10px;align-items:flex-start">
                    <div style="flex:1;min-width:0">
                        <strong>{{ row.title || 'Pop-up' }}</strong>
                        <p class="adm-note" style="white-space:pre-wrap">{{ row.body }}</p>
                        <p class="adm-note">Jangan tampil lagi selama {{ row.dismiss_days }} hari</p>
                    </div>
                    <span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                </div>
                <div style="display:flex;gap:6px;margin-top:10px">
                    <button type="button" class="adm-btn sm" @click="open('popup', row)">Ubah</button>
                    <button type="button" class="adm-btn sm danger" @click="removeTarget = { type: 'popup', row }">Hapus</button>
                </div>
            </div>
            <p v-if="!popups.length" class="adm-empty">Belum ada pop-up pengumuman.</p>
        </section>

        <!-- Aset -->
        <section v-if="tab === 'assets'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Logo &amp; gambar cadangan</h2>
                </div>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Aset</th><th>Gambar</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in assets" :key="row.id">
                            <td><strong>{{ ASSET_LABEL[row.key] || 'Aset toko' }}</strong><div class="adm-note">{{ row.key }}</div></td>
                            <td class="adm-note">{{ row.image_url ? 'Terpasang' : 'Belum ada gambar' }}</td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><button type="button" class="adm-btn sm" @click="open('asset', row)">Ubah</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!assets.length" class="adm-empty">Belum ada aset toko yang bisa diatur.</p>
        </section>

        <!-- Teks bantuan -->
        <section v-if="tab === 'support'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Teks bantuan &amp; footer</h2>
                </div>
                <Link href="/admin/settings" class="adm-btn">Buka Pengaturan</Link>
            </div>
            <div class="adm-card-body">
                <div class="adm-grid">
                    <label class="adm-field full"><span>Judul bagian berita di halaman utama</span><input v-model="support.home_news_title" class="adm-input"></label>
                    <label class="adm-field full"><span>Pengantar bagian berita</span><textarea v-model="support.home_news_intro" class="adm-textarea" rows="2" /></label>
                    <label class="adm-field"><span>Label kecil bantuan</span><input v-model="support.support_cta_label" class="adm-input" maxlength="80"></label>
                    <label class="adm-field"><span>Teks tombol bantuan</span><input v-model="support.support_cta_button" class="adm-input" maxlength="80"></label>
                    <label class="adm-field full"><span>Judul bantuan</span><input v-model="support.support_cta_title" class="adm-input" maxlength="180"></label>
                    <label class="adm-field full"><span>Deskripsi bantuan</span><textarea v-model="support.support_cta_body" class="adm-textarea" rows="2" maxlength="1000" /></label>
                    <label class="adm-field full"><span>Kalimat footer</span><textarea v-model="support.footer_description" class="adm-textarea" rows="2" /></label>
                </div>
                <div style="display:grid;gap:8px;margin-top:10px">
                    <label class="adm-switch-row">
                        <button type="button" class="adm-switch" role="switch" :aria-checked="support.support_cta_enabled" @click="support.support_cta_enabled = !support.support_cta_enabled" />
                        Tampilkan blok bantuan di halaman utama
                    </label>
                    <label class="adm-switch-row">
                        <button type="button" class="adm-switch" role="switch" :aria-checked="support.support_widget_enabled" @click="support.support_widget_enabled = !support.support_widget_enabled" />
                        Tombol bantuan mengambang di semua halaman
                    </label>
                </div>
            </div>
            <div class="adm-card-body" style="border-top:1px solid var(--a-line);display:flex;justify-content:flex-end">
                <button type="button" class="adm-btn primary" :disabled="supportBusy" @click="saveSupport">Simpan teks bantuan</button>
            </div>
        </section>

        <!-- FAQ -->
        <section v-if="tab === 'faq'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Pertanyaan umum</h2>
                </div>
                <button type="button" class="adm-btn primary" @click="open('faq')">Tambah FAQ</button>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Pertanyaan</th><th>Urutan</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in faqs" :key="row.id">
                            <td><strong>{{ row.question }}</strong><div class="adm-note">{{ row.answer }}</div></td>
                            <td>{{ row.sort_order }}</td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <button type="button" class="adm-btn sm" @click="open('faq', row)">Ubah</button>
                                    <button type="button" class="adm-btn sm danger" @click="removeTarget = { type: 'faq', row }">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!faqs.length" class="adm-empty">Belum ada pertanyaan umum.</p>
        </section>

        <!-- Kebijakan -->
        <section v-if="tab === 'legal'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Kebijakan toko</h2>
                </div>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Halaman</th><th>Pengantar</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in pages" :key="row.key">
                            <td><strong>{{ row.title || PAGE_LABEL[row.key] || 'Halaman kebijakan' }}</strong><div class="adm-note">{{ row.key }}</div></td>
                            <td class="adm-note">{{ row.intro || 'Belum ada pengantar' }}</td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><button type="button" class="adm-btn sm" @click="open('page', row)">Ubah</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!pages.length" class="adm-empty">Belum ada halaman kebijakan.</p>
        </section>

        <!-- Berita -->
        <section v-if="tab === 'news'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Berita</h2>
                </div>
                <button type="button" class="adm-btn primary" @click="open('news')">Tambah berita</button>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Judul</th><th>Sumber</th><th>Publikasi</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in news" :key="row.id">
                            <td><strong>{{ row.title }}</strong><div class="adm-note">{{ row.summary }}</div></td>
                            <td class="adm-note">{{ row.source_label || 'Tanpa sumber' }}</td>
                            <td class="adm-note">{{ row.published_at || 'Belum dijadwalkan' }}</td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <button type="button" class="adm-btn sm" @click="open('news', row)">Ubah</button>
                                    <button type="button" class="adm-btn sm danger" @click="removeTarget = { type: 'news', row }">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!news.length" class="adm-empty">Belum ada berita.</p>
        </section>

        <!-- Ulasan -->
        <section v-if="tab === 'reviews'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Ulasan pelanggan</h2>
                    <p>Moderasi ulasan terverifikasi dari pesanan sukses. Isi ulasan tidak diedit Admin.</p>
                </div>
            </div>
            <form class="adm-filters" @submit.prevent>
                <label class="adm-field search">
                    <span>Cari</span>
                    <input v-model="reviewQuery" class="adm-input" placeholder="Nama pelanggan, produk, atau isi ulasan">
                </label>
                <label class="adm-field narrow">
                    <span>Bintang</span>
                    <select v-model="reviewRating" class="adm-select">
                        <option value="">Semua</option>
                        <option v-for="n in 5" :key="n" :value="n">{{ n }} bintang</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="reviewStatus" class="adm-select">
                        <option value="">Semua</option>
                        <option value="tampil">Tampil</option>
                        <option value="sembunyi">Disembunyikan</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="button" class="adm-btn" @click="reviewQuery = ''; reviewRating = ''; reviewStatus = ''">Hapus filter</button>
                </div>
            </form>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Pelanggan</th><th>Produk</th><th>Bintang</th><th>Ulasan</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in reviewRows" :key="row.id">
                            <td>{{ row.display_name }}</td>
                            <td class="adm-note">{{ row.product_name }}</td>
                            <td>{{ '★'.repeat(row.rating) }}{{ '☆'.repeat(5 - row.rating) }}</td>
                            <td class="adm-note" style="max-width:340px">{{ row.body }}</td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Tampil' : 'Disembunyikan' }}</span></td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    <button type="button" class="adm-btn sm" @click="toggleReview(row)">{{ row.is_active ? 'Sembunyikan' : 'Tampilkan' }}</button>
                                    <button type="button" class="adm-btn sm danger" @click="removeTarget = { type: 'review', row }">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!reviewRows.length" class="adm-empty">Tidak ada ulasan yang cocok dengan filter.</p>
        </section>

        <!-- Pop-up sunting -->
        <AdminModal v-model:open="modal" :title="modalTitle" :description="editing?.row ? 'Perubahan langsung dipakai halaman pelanggan.' : 'Data baru langsung tampil bila diaktifkan.'" :wide="editing?.type === 'news' || editing?.type === 'page'">
            <form id="content-form" class="adm-grid" @submit.prevent="save">
                <template v-if="editing?.type === 'banner'">
                    <label class="adm-field full"><span>Nama banner (juga teks alternatif gambar) <b class="req">*</b></span><input v-model="form.title" class="adm-input" required><span v-if="errors.title" class="err">{{ errors.title }}</span></label>
                    <label class="adm-field full"><span>Teks kecil pada banner</span><input v-model="form.subtitle" class="adm-input"></label>
                    <label class="adm-field"><span>Label tombol</span><input v-model="form.cta_label" class="adm-input"></label>
                    <label class="adm-field"><span>Alamat tujuan saat banner ditekan</span><input v-model="form.cta_href" class="adm-input" placeholder="Contoh: /promo atau https://..."></label>
                    <label class="adm-field"><span>Urutan tampil</span><input v-model.number="form.sort_order" type="number" min="0" class="adm-input"><span class="hint">Angka lebih kecil tampil lebih dulu.</span></label>
                    <div class="adm-field"><span>Tampil di</span>
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="form.show_desktop" @click="form.show_desktop = !form.show_desktop" /> Desktop</label>
                        <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="form.show_mobile" @click="form.show_mobile = !form.show_mobile" /> Ponsel</label>
                    </div>
                    <div v-if="editing.row" class="adm-grid full">
                        <div class="adm-field"><span>Gambar desktop</span><AdminMedia type="banner" :id="editing.row.id" collection="desktop" :url="editing.row.desktop_url" wide /></div>
                        <div class="adm-field"><span>Gambar ponsel</span><AdminMedia type="banner" :id="editing.row.id" collection="mobile" :url="editing.row.mobile_url" wide /></div>
                    </div>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Aktif</label>
                </template>

                <template v-else-if="editing?.type === 'popup'">
                    <label class="adm-field full"><span>Judul <b class="req">*</b></span><input v-model="form.title" class="adm-input" required></label>
                    <label class="adm-field full"><span>Isi <b class="req">*</b></span><textarea v-model="form.body" class="adm-textarea" rows="5" required /></label>
                    <label class="adm-field"><span>Jangan tampil lagi selama (hari)</span><input v-model.number="form.dismiss_days" type="number" min="0" max="365" class="adm-input"></label>
                    <div v-if="editing.row" class="adm-field full"><span>Gambar pop-up</span><AdminMedia type="popup" :id="editing.row.id" :url="editing.row.image_url" wide /></div>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Aktif</label>
                </template>

                <template v-else-if="editing?.type === 'news'">
                    <label class="adm-field full"><span>Judul <b class="req">*</b></span><input v-model="form.title" class="adm-input" required></label>
                    <label class="adm-field"><span>Sumber berita</span><input v-model="form.source_label" class="adm-input"></label>
                    <label class="adm-field"><span>Urutan tampil</span><input v-model.number="form.sort_order" type="number" min="0" class="adm-input"></label>
                    <label class="adm-field full"><span>Ringkasan</span><textarea v-model="form.summary" class="adm-textarea" rows="2" /></label>
                    <label class="adm-field full"><span>Isi</span><textarea v-model="form.body" class="adm-textarea" rows="6" /></label>
                    <label class="adm-field"><span>Tanggal publikasi</span><input v-model="form.published_at" type="datetime-local" class="adm-input"></label>
                    <label class="adm-field"><span>Alamat halaman</span><input v-model="form.slug" class="adm-input" placeholder="Dibuat otomatis dari judul"><span class="hint">Kosongkan untuk dibuat otomatis.</span></label>
                    <div v-if="editing.row" class="adm-field full"><span>Gambar berita</span><AdminMedia type="news" :id="editing.row.id" :url="editing.row.image_url" wide /></div>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Aktif</label>
                </template>

                <template v-else-if="editing?.type === 'faq'">
                    <label class="adm-field full"><span>Pertanyaan <b class="req">*</b></span><input v-model="form.question" class="adm-input" required></label>
                    <label class="adm-field full"><span>Jawaban <b class="req">*</b></span><textarea v-model="form.answer" class="adm-textarea" rows="4" required /></label>
                    <label class="adm-field"><span>Urutan tampil</span><input v-model.number="form.sort_order" type="number" min="0" class="adm-input"></label>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Aktif</label>
                </template>

                <template v-else-if="editing?.type === 'page'">
                    <label class="adm-field full"><span>Judul halaman</span><input v-model="form.title" class="adm-input"></label>
                    <label class="adm-field full"><span>Pengantar</span><textarea v-model="form.intro" class="adm-textarea" rows="2" /></label>
                    <label class="adm-field full"><span>Isi</span><textarea v-model="form.body" class="adm-textarea" rows="10" /><span class="hint">Awali paragraf dengan ## untuk judul bagian, dan - untuk item daftar.</span></label>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Aktif</label>
                </template>

                <template v-else-if="editing?.type === 'asset'">
                    <label class="adm-field full"><span>Tautan saat gambar ditekan</span><input v-model="form.target_url" class="adm-input" placeholder="https://..."></label>
                    <div class="adm-field full">
                        <span>{{ ASSET_LABEL[editing.row.key] || 'Gambar' }}</span>
                        <AdminMedia type="asset" :id="editing.row.id" :asset-key="editing.row.key" :url="editing.row.image_url" wide />
                        <span v-if="['footer_banner_desktop', 'footer_banner_mobile'].includes(editing.row.key)" class="hint">Rasio 3:1 — rekomendasi desktop 1920×640, mobile 1080×360</span>
                    </div>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Aktif dipakai toko</label>
                </template>

                <template v-else-if="editing?.type === 'review'">
                    <div class="adm-field full">
                        <span>Ulasan</span>
                        <p class="adm-note">{{ editing.row.display_name }} · {{ editing.row.product_name }}</p>
                        <p class="adm-note">{{ '★'.repeat(editing.row.rating) }}{{ '☆'.repeat(5 - editing.row.rating) }}</p>
                        <p style="font-size:12.5px">{{ editing.row.body }}</p>
                    </div>
                    <label class="adm-switch-row full"><button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" /> Tampilkan di halaman produk</label>
                </template>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="modal = false">Batal</button>
                <button type="submit" form="content-form" class="adm-btn primary" :disabled="busy">Simpan</button>
            </template>
        </AdminModal>

        <AdminModal :open="!!removeTarget" title="Hapus data" :description="removeTarget?.row?.title || removeTarget?.row?.question || removeTarget?.row?.display_name || ''" @update:open="value => { if (!value) removeTarget = null; }">
            <p class="adm-note">Data ini akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="removeTarget = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="remove">Hapus</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
