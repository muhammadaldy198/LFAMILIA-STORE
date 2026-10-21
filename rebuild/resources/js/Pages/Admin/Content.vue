<script setup>
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';

const props = defineProps({
    assets: Array,
    banners: Array,
    popups: Array,
    news: Array,
    faqs: Array,
    pages: Array,
    reviews: Array,
    settings: Object,
});
const contentArea = ref('storefront'); // 'storefront' | 'legal' | 'news'
const contentTab = ref('banners');
const selectedPopupId = ref(null);
const selectedAssetId = ref(null);
const selectedReviewId = ref(null);

// Satu dialog konfirmasi bertema untuk semua tindakan hapus.
const pendingAction = ref(null);
const ask = (title, message, confirmLabel, run) => { pendingAction.value = { title, message, confirmLabel, run }; };
const runPending = () => { const action = pendingAction.value; pendingAction.value = null; action?.run(); };
const selectedBannerId = ref(null);
const selectedFaqId = ref(null);
const selectedPageKey = ref(null);
const selectedNewsId = ref(null);
const bannerFormOpen = ref(false);
const faqFormOpen = ref(false);
const newsFormOpen = ref(false);
const popupFormOpen = ref(false);

const areas = [
    { key: 'storefront', label: 'Tampilan Toko', tabs: [['banners','Banner Utama'],['popup','Pop-up Pengumuman'],['assets','Logo & Gambar Cadangan'],['support','Teks Footer & Bantuan']] },
    { key: 'legal', label: 'Bantuan & Kebijakan', tabs: [['faq','Pertanyaan Umum (FAQ)'],['legal','Kebijakan Toko']] },
    { key: 'news', label: 'Berita & Ulasan', tabs: [['news','Berita'],['reviews','Ulasan Pelanggan']] },
];

const setArea = (areaKey) => {
    contentArea.value = areaKey;
    const found = areas.find(a => a.key === areaKey);
    if (found && found.tabs.length) contentTab.value = found.tabs[0][0];
};
const page = usePage();
const base = page.props.adminPanel?.base_path || '/admin';

const assets = ref((props.assets || []).map((x) => ({ ...x })));
const banners = ref((props.banners || []).map((x) => ({ ...x })));
const popups = ref((props.popups || []).map((x) => ({ ...x })));
const news = ref((props.news || []).map((x) => ({ ...x })));
const faqs = ref((props.faqs || []).map((x) => ({ ...x })));
const pages = ref((props.pages || []).map((x) => ({ ...x })));
const reviews = ref((props.reviews || []).map((x) => ({ ...x })));

watch(() => props.assets, (v) => { assets.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.banners, (v) => { banners.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.popups, (v) => { popups.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.news, (v) => { news.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.faqs, (v) => { faqs.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.pages, (v) => { pages.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.reviews, (v) => { reviews.value = (v || []).map((x) => ({ ...x })); });

const settingsForm = useForm({
    support_widget_enabled: Boolean(props.settings?.['store.support_widget_enabled'] ?? true),
    support_cta_enabled: Boolean(props.settings?.['store.support_cta_enabled'] ?? true),
    support_cta_label: props.settings?.['store.support_cta_label'] || 'BUTUH BANTUAN?',
    support_cta_title: props.settings?.['store.support_cta_title'] || 'Tim LFAMILIA siap membantu.',
    support_cta_body: props.settings?.['store.support_cta_body'] || 'Butuh bantuan memilih produk, pembayaran, atau mengecek status pesanan? Hubungi tim kami.',
    support_cta_button: props.settings?.['store.support_cta_button'] || 'Hubungi Kami',
    footer_description: props.settings?.['store.footer_description'] || '',
    home_news_title: props.settings?.['store.home_news_title'] || '',
    home_news_intro: props.settings?.['store.home_news_intro'] || '',
});

const bannerForm = useForm({
    title: 'Banner Baru', subtitle: '', cta_label: '', cta_href: '',
    show_desktop: true, show_mobile: true, sort_order: 0, is_active: true,
});

const popupForm = useForm({
    title: 'Pengumuman LFAMILIA STORE', body: '', dismiss_days: 7, is_active: true,
});

const newsForm = useForm({
    slug: '', title: '', summary: '', body: '', source_label: 'LFAMILIA News',
    sort_order: 0, is_active: false, published_at: '',
});

const faqForm = useForm({
    question: '', answer: '', sort_order: 0, is_active: true,
});

const saveAsset = (asset) => router.put(base + '/catalog/assets/' + asset.id, {
    is_active: Boolean(asset.is_active),
    target_url: asset.target_url || null,
});
const saveBanner = (item) => router.put(base + '/content/banners/' + item.id, {
    title: item.title,
    subtitle: item.subtitle || '',
    cta_label: item.cta_label || '',
    cta_href: item.cta_href || null,
    show_desktop: Boolean(item.show_desktop),
    show_mobile: Boolean(item.show_mobile),
    sort_order: Number(item.sort_order || 0),
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });
const deleteBanner = (item) => ask('Hapus banner', 'Banner "' + (item.title || 'tanpa nama') + '" akan dihapus dari halaman utama.', 'Hapus banner', () => {
    router.delete(base + '/content/banners/' + item.id, { preserveScroll: true });
});

const savePopup = (item) => router.put(base + '/content/popups/' + item.id, {
    title: item.title,
    body: item.body,
    dismiss_days: Number(item.dismiss_days || 0),
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });
const deletePopup = (item) => ask('Hapus pop-up', 'Pop-up "' + (item.title || 'tanpa judul') + '" tidak akan muncul lagi di halaman utama.', 'Hapus pop-up', () => {
    router.delete(base + '/content/popups/' + item.id, { preserveScroll: true, onSuccess: () => { selectedPopupId.value = null; } });
});
const assetHasTarget = (key) => ['banner_desktop', 'banner_mobile'].includes(key);
const assetLabel = (key) => ({
    logo: 'Logo toko',
    favicon: 'Favicon',
    banner_desktop: 'Banner cadangan desktop',
    banner_mobile: 'Banner cadangan mobile',
    footer_banner_desktop: 'Gambar footer desktop',
    footer_banner_mobile: 'Gambar footer mobile',
    manual_qris: 'QRIS manual',
    payment_header: 'Gambar halaman pembayaran',
}[key] || 'Aset toko');
const pageLabel = (key) => ({
    about: 'Tentang kami',
    terms: 'Syarat dan ketentuan',
    privacy: 'Kebijakan privasi',
    refund: 'Kebijakan pengembalian',
    contact: 'Kontak',
    faq: 'Bantuan',
}[key] || 'Halaman kebijakan');

const saveNews = (item) => router.put(base + '/content/news/' + item.id, {
    slug: item.slug || '',
    title: item.title,
    summary: item.summary || '',
    body: item.body || '',
    source_label: item.source_label || '',
    sort_order: Number(item.sort_order || 0),
    is_active: Boolean(item.is_active),
    published_at: item.published_at || null,
}, { preserveScroll: true });

const deleteNews = (item) => ask('Hapus berita', 'Berita "' + item.title + '" akan dihapus permanen.', 'Hapus berita', () => {
    router.delete(base + '/content/news/' + item.id, { preserveScroll: true, onSuccess: () => { selectedNewsId.value = null; } });
});

const saveFaq = (item) => router.put(base + '/content/faqs/' + item.id, {
    question: item.question,
    answer: item.answer,
    sort_order: Number(item.sort_order || 0),
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });

const deleteFaq = (item) => ask('Hapus FAQ', 'Pertanyaan "' + item.question + '" akan dihapus dari halaman bantuan.', 'Hapus FAQ', () => {
    router.delete(base + '/content/faqs/' + item.id, { preserveScroll: true, onSuccess: () => { selectedFaqId.value = null; } });
});
const saveReview = (item) => router.put(base + '/content/reviews/' + item.id, {
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });

const deleteReview = (item) => ask('Hapus ulasan', 'Ulasan dari ' + item.display_name + ' akan dihapus permanen. Untuk sekadar menyembunyikan, matikan tombol Tampil.', 'Hapus ulasan', () => {
    router.delete(base + '/content/reviews/' + item.id, { preserveScroll: true });
});

const savePage = (item) => router.put(base + '/content/pages/' + item.key, {
    title: item.title,
    intro: item.intro || '',
    body: item.body || '',
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });
</script>

<template>
    <Head title="Banner & Konten" />
    <AdminShell>
        <div class="space-y-6">
            <header class="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight">Banner & Konten</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">Kelola isi halaman toko yang dibaca pelanggan: banner, pop-up, logo, bantuan, kebijakan, berita, dan moderasi ulasan. Pilih satu baris untuk membukanya di panel samping.</p>
                </div>
            </header>

            <!-- Navigasi 3 Area Konten -->
            <div class="flex flex-wrap items-center gap-2 border-b border-border pb-3">
                <Button
                    v-for="area in areas"
                    :key="area.key"
                    type="button"
                    :variant="contentArea === area.key ? 'default' : 'outline'"
                    size="sm"
                    @click="setArea(area.key)"
                >
                    {{ area.label }}
                </Button>
                <Button variant="ghost" size="sm" as-child class="ml-auto text-xs">
                    <Link :href="base + '/content/presentation'">Tema & Presentasi Toko →</Link>
                </Button>
            </div>

            <!-- Sub-tab di dalam area yang dipilih -->
            <nav class="lf-admin-tabs" aria-label="Sub bagian konten">
                <Button
                    v-for="[key, label] in (areas.find(a => a.key === contentArea)?.tabs || [])"
                    :key="key"
                    type="button"
                    :aria-current="contentTab === key ? 'page' : undefined"
                    :class="{ active: contentTab === key }"
                    @click="contentTab = key"
                >
                    {{ label }}
                </Button>
            </nav>
            <section v-show="contentTab === 'banners'" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Banner halaman utama</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Banner aktif langsung tampil di halaman pelanggan. Pilih satu baris untuk mengubah teks, gambar, dan statusnya.</p>
                    </div>
                    <Button type="button" @click="bannerFormOpen = true">Tambah banner</Button>
                </div>

                <Sheet :open="bannerFormOpen" @update:open="(value) => { bannerFormOpen = value; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-2xl">
                        <SheetHeader><SheetTitle>Tambah banner</SheetTitle><SheetDescription>Banner aktif langsung tampil di halaman pelanggan.</SheetDescription></SheetHeader>
                        <form class="mt-4 grid gap-3" @submit.prevent="bannerForm.post(base + '/content/banners', { preserveScroll: true, onSuccess: () => { bannerForm.reset(); bannerFormOpen = false; } })">
                            <label class="lf-admin-field"><span>Nama banner (juga menjadi teks alternatif gambar)</span><Input v-model="bannerForm.title" required placeholder="Contoh: Promo akhir pekan" /><span v-if="bannerForm.errors.title" role="alert" class="text-xs font-normal text-destructive">{{ bannerForm.errors.title }}</span></label>
                            <label class="lf-admin-field"><span>Teks kecil pada banner (opsional)</span><Input v-model="bannerForm.subtitle" placeholder="Teks yang tampil di banner" /><span v-if="bannerForm.errors.subtitle" role="alert" class="text-xs font-normal text-destructive">{{ bannerForm.errors.subtitle }}</span></label>
                            <label class="lf-admin-field"><span>Alamat tujuan saat banner ditekan</span><Input v-model="bannerForm.cta_href" placeholder="Contoh: /promo atau https://..." /><span v-if="bannerForm.errors.cta_href" role="alert" class="text-xs font-normal text-destructive">{{ bannerForm.errors.cta_href }}</span></label>
                            <label class="lf-admin-field"><span>Urutan tampil</span><Input v-model.number="bannerForm.sort_order" type="number" min="0" placeholder="Urutan" /><span class="text-xs font-normal text-muted-foreground">Angka lebih kecil tampil lebih dulu.</span><span v-if="bannerForm.errors.sort_order" role="alert" class="text-xs font-normal text-destructive">{{ bannerForm.errors.sort_order }}</span></label>
                            <div class="flex flex-wrap gap-4">
                                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="bannerForm.show_desktop" /> Tampil di desktop</label>
                                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="bannerForm.show_mobile" /> Tampil di ponsel</label>
                                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="bannerForm.is_active" /> Aktif</label>
                            </div>
                            <div class="flex justify-end gap-2 border-t border-border pt-4"><Button type="button" variant="outline" @click="bannerFormOpen = false">Batal</Button><Button :disabled="bannerForm.processing">Tambah banner</Button></div>
                        </form>
                    </SheetContent>
                </Sheet>

                <ul v-if="banners.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="item in banners" :key="item.id">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="selectedBannerId = item.id">
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate">{{ item.title || 'Banner' }}</strong>
                                <small class="mt-0.5 block truncate text-muted-foreground">
                                    Posisi {{ item.sort_order }}<template v-if="item.subtitle"> · {{ item.subtitle }}</template>
                                    · {{ item.show_desktop ? 'desktop' : '' }}{{ item.show_desktop && item.show_mobile ? ' + ' : '' }}{{ item.show_mobile ? 'ponsel' : '' }}
                                </small>
                            </span>
                            <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', item.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                        </button>
                        <Sheet :open="selectedBannerId === item.id" @update:open="(value) => { if (!value) selectedBannerId = null; }">
                        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-3xl">
                        <SheetHeader><SheetTitle>Ubah banner</SheetTitle><SheetDescription>Ubah teks, gambar, dan status tampil banner. Pratinjau mengikuti tampilan toko.</SheetDescription></SheetHeader>
                        <div v-if="selectedBannerId === item.id" class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="space-y-3">
                        <label class="lf-admin-field"><span>Nama banner (juga menjadi teks alternatif gambar)</span><Input v-model="item.title" placeholder="Nama banner" /></label>
                        <label class="lf-admin-field"><span>Teks kecil pada banner</span><Input v-model="item.subtitle" placeholder="Teks yang tampil pada banner" /></label>
                        <label class="lf-admin-field"><span>Alamat tujuan saat banner ditekan</span><Input v-model="item.cta_href" placeholder="Contoh: /promo atau https://..." /></label>
                        <label class="lf-admin-field"><span>Urutan tampil</span><Input v-model.number="item.sort_order" type="number" min="0" /><span class="text-xs font-normal text-muted-foreground">Angka lebih kecil tampil lebih dulu.</span></label>
                        <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.show_desktop" /> Tampil di desktop</label>
                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.show_mobile" /> Tampil di ponsel</label>
                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.is_active" /> Aktif</label>
                        </div>
                        <AdminMediaControl type="banner" :id="item.id" collection="desktop" :url="item.desktop_url" />
                        <AdminMediaControl type="banner" :id="item.id" collection="mobile" :url="item.mobile_url" />
                        <div class="flex gap-2 border-t border-border pt-4">
                        <Button type="button" variant="destructive" @click="deleteBanner(item)">Hapus</Button>
                        <Button type="button" variant="outline" @click="selectedBannerId = null">Tutup</Button>
                        <Button type="button" class="ml-auto" @click="saveBanner(item)">Simpan</Button>
                        </div>
                        </div>
                        <aside class="rounded-lg border border-border bg-muted p-4" aria-label="Pratinjau banner">
                        <h3 class="font-semibold">Pratinjau banner</h3>
                        <p class="mt-1 text-xs text-muted-foreground">Desktop</p>
                        <div class="mt-2 overflow-hidden rounded bg-card">
                        <img v-if="item.desktop_url" :src="item.desktop_url" :alt="item.title || 'Banner toko'" class="max-h-52 w-full object-cover" />
                        <div v-else class="flex h-36 items-center justify-center text-sm text-muted-foreground">Gambar desktop belum dipilih</div>
                        <p class="p-3 text-sm">{{ item.subtitle || item.title }}</p>
                        </div>
                        <p class="mt-3 text-xs text-muted-foreground">Ponsel</p>
                        <div class="mx-auto mt-2 max-w-[240px] overflow-hidden rounded border bg-card">
                        <img v-if="item.mobile_url || item.desktop_url" :src="item.mobile_url || item.desktop_url" :alt="item.title || 'Banner toko'" class="h-36 w-full object-cover" />
                        <div v-else class="flex h-36 items-center justify-center text-xs text-muted-foreground">Gambar ponsel belum dipilih</div>
                        <p class="p-2 text-xs">{{ item.subtitle || item.title }}</p>
                        </div>
                        </aside>
                        </div>
                        </SheetContent>
                        </Sheet>
                    </li>
                </ul>
                <p v-else class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                    Belum ada banner utama. Selama kosong, halaman pelanggan memakai gambar banner cadangan yang aktif.
                </p>

            </section>

            <section v-show="contentTab === 'popup'" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Pop-up halaman utama</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Hanya satu pop-up pengumuman. Muncul saat pelanggan membuka halaman utama dan bisa disembunyikan sesuai masa yang ditentukan.</p>
                    </div>
                    <Button v-if="!popups.length" type="button" @click="popupFormOpen = true">Tambah pop-up</Button>
                </div>

                <Sheet :open="popupFormOpen" @update:open="(value) => { popupFormOpen = value; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                        <SheetHeader><SheetTitle>Tambah pop-up</SheetTitle><SheetDescription>Pop-up tampil sekali saat pelanggan membuka halaman utama.</SheetDescription></SheetHeader>
                        <form class="mt-4 grid gap-3" @submit.prevent="popupForm.post(base + '/content/popups', { preserveScroll: true, onSuccess: () => { popupFormOpen = false; } })">
                            <label class="lf-admin-field"><span>Judul</span><Input v-model="popupForm.title" required placeholder="Judul pop-up" /><span v-if="popupForm.errors.title" role="alert" class="text-xs font-normal text-destructive">{{ popupForm.errors.title }}</span></label>
                            <label class="lf-admin-field"><span>Isi</span><Textarea v-model="popupForm.body" required rows="5" placeholder="Isi pengumuman"></Textarea><span v-if="popupForm.errors.body" role="alert" class="text-xs font-normal text-destructive">{{ popupForm.errors.body }}</span></label>
                            <label class="lf-admin-field"><span>Jangan tampil lagi selama (hari)</span><Input v-model.number="popupForm.dismiss_days" type="number" min="0" max="365" /><span v-if="popupForm.errors.dismiss_days" role="alert" class="text-xs font-normal text-destructive">{{ popupForm.errors.dismiss_days }}</span></label>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="popupForm.is_active" /> Aktif</label>
                            <div class="flex justify-end gap-2 border-t border-border pt-4"><Button type="button" variant="outline" @click="popupFormOpen = false">Batal</Button><Button :disabled="popupForm.processing">Simpan pop-up</Button></div>
                        </form>
                    </SheetContent>
                </Sheet>

                <ul v-if="popups.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="item in popups" :key="item.id">
                        <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="selectedPopupId = item.id">
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate">{{ item.title || 'Pop-up' }}</strong>
                                <small class="mt-0.5 block truncate text-muted-foreground">{{ item.body }}</small>
                            </span>
                            <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', item.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                        </button>
                    </li>
                </ul>

                <Sheet :open="selectedPopupId !== null" @update:open="(value) => { if (!value) selectedPopupId = null; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-2xl">
                        <SheetHeader><SheetTitle>Ubah pop-up</SheetTitle><SheetDescription>Ubah teks, gambar, dan masa tampil pop-up.</SheetDescription></SheetHeader>
                        <template v-for="item in popups" :key="'popup-'+item.id">
                            <div v-if="selectedPopupId === item.id" class="mt-4 grid gap-4">
                                <div class="space-y-3">
                                    <label class="lf-admin-field"><span>Judul</span><Input v-model="item.title" /></label>
                                    <label class="lf-admin-field"><span>Isi</span><Textarea v-model="item.body" rows="5"></Textarea></label>
                                    <label class="lf-admin-field"><span>Jangan tampil lagi selama (hari)</span><Input v-model.number="item.dismiss_days" type="number" min="0" max="365" /></label>
                                    <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.is_active" /> Aktif</label>
                                    <p class="text-xs text-muted-foreground">Gambar pop-up bersifat opsional.</p>
                                    <AdminMediaControl type="popup" :id="item.id" :url="item.image_url" />
                                    <div class="flex gap-2 border-t border-border pt-4"><Button type="button" @click="savePopup(item)">Simpan</Button><Button type="button" variant="destructive" @click="deletePopup(item)">Hapus pop-up</Button></div>
                                </div>
                                <aside class="rounded-lg border border-border bg-muted p-4" aria-label="Pratinjau pop-up">
                                    <h3 class="font-semibold">Pratinjau pop-up</h3>
                                    <div class="mx-auto mt-4 max-w-sm rounded-xl bg-card p-5 shadow-sm">
                                        <img v-if="item.image_url" :src="item.image_url" :alt="item.title" class="mb-3 max-h-40 w-full object-contain" />
                                        <h4 class="text-lg font-bold">{{ item.title }}</h4>
                                        <p class="mt-2 whitespace-pre-wrap text-sm">{{ item.body }}</p>
                                    </div>
                                </aside>
                            </div>
                        </template>
                    </SheetContent>
                </Sheet>
            </section>

            <section v-show="contentTab === 'assets'" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold">Logo & gambar cadangan</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Logo, favicon, banner cadangan, gambar footer, dan aset tampilan lain yang dipakai toko. Pilih satu untuk mengganti gambar atau mengatur tautannya.</p>
                </div>
                <ul v-if="assets.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="asset in assets" :key="asset.id">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="selectedAssetId = asset.id">
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate">{{ assetLabel(asset.key) }}</strong>
                                <small class="mt-0.5 block truncate text-muted-foreground">{{ asset.image_url ? 'Gambar terpasang' : 'Belum ada gambar' }}<template v-if="asset.target_url"> · {{ asset.target_url }}</template></small>
                            </span>
                            <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', asset.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ asset.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">Belum ada aset toko yang bisa diatur.</p>

                <Sheet :open="selectedAssetId !== null" @update:open="(value) => { if (!value) selectedAssetId = null; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                        <SheetHeader><SheetTitle>Ubah aset toko</SheetTitle><SheetDescription>Gambar dan tautan aset yang tampil di halaman pelanggan.</SheetDescription></SheetHeader>
                        <template v-for="asset in assets" :key="'asset-'+asset.id">
                            <form v-if="selectedAssetId === asset.id" class="mt-4 space-y-4" @submit.prevent="saveAsset(asset)">
                                <p class="text-sm font-semibold">{{ assetLabel(asset.key) }}</p>
                                <label v-if="assetHasTarget(asset.key)" class="lf-admin-field"><span>Tautan saat gambar ditekan</span>
                                    <Input v-model="asset.target_url" type="url" placeholder="https://..." />
                                </label>
                                <AdminMediaControl type="asset" :asset-key="asset.key" :id="asset.id" :url="asset.image_url" />
                                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="asset.is_active" /> Aktif dipakai toko</label>
                                <div class="flex justify-end gap-2 border-t border-border pt-4"><Button type="button" variant="outline" @click="selectedAssetId = null">Tutup</Button><Button>Simpan</Button></div>
                            </form>
                        </template>
                    </SheetContent>
                </Sheet>
            </section>

            <section v-show="contentTab === 'support'" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Teks bantuan & footer</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Blok bantuan di halaman utama, tombol bantuan mengambang, dan kalimat footer. Nomor kontak serta akun sosial diatur di menu Pengaturan.</p>
                    </div>
                    <Button type="button" variant="outline" as-child><Link :href="base + '/settings'">Buka Pengaturan</Link></Button>
                </div>

                <form class="divide-y divide-border rounded-md border border-border" @submit.prevent="settingsForm.put(base + '/content/settings')">
                    <div class="grid gap-3 p-4 md:grid-cols-2">
                        <label class="lf-admin-field md:col-span-2"><span>Judul bagian berita di halaman utama</span><Input v-model="settingsForm.home_news_title" /></label>
                        <label class="lf-admin-field md:col-span-2"><span>Pengantar bagian berita</span><Textarea v-model="settingsForm.home_news_intro" rows="2"></Textarea></label>
                    </div>
                    <div class="grid gap-3 p-4 md:grid-cols-2">
                        <label class="flex items-center gap-2 text-sm md:col-span-2"><AdminSwitch v-model="settingsForm.support_cta_enabled" /> Tampilkan blok bantuan di halaman utama</label>
                        <label class="lf-admin-field"><span>Label kecil</span><Input v-model="settingsForm.support_cta_label" maxlength="80" /></label>
                        <label class="lf-admin-field"><span>Teks tombol</span><Input v-model="settingsForm.support_cta_button" maxlength="80" /></label>
                        <label class="lf-admin-field md:col-span-2"><span>Judul bantuan</span><Input v-model="settingsForm.support_cta_title" maxlength="180" /></label>
                        <label class="lf-admin-field md:col-span-2"><span>Deskripsi bantuan</span><Textarea v-model="settingsForm.support_cta_body" rows="2" maxlength="1000"></Textarea></label>
                    </div>
                    <div class="grid gap-3 p-4">
                        <label class="lf-admin-field"><span>Kalimat footer</span><Textarea v-model="settingsForm.footer_description" rows="2"></Textarea></label>
                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="settingsForm.support_widget_enabled" /> Tombol bantuan mengambang di semua halaman</label>
                    </div>
                    <div class="flex justify-end p-4">
                        <Button :disabled="settingsForm.processing">{{ settingsForm.processing ? 'Menyimpan…' : 'Simpan teks bantuan' }}</Button>
                    </div>
                </form>
            </section>

            <section v-show="contentTab === 'news'" class="space-y-4">
                <h2 class="text-lg font-bold">Berita</h2>
                <div class="mt-4"><Button type="button" class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white" @click="newsFormOpen = true">Tambah berita</Button></div>
                <Sheet :open="newsFormOpen" @update:open="(value) => { newsFormOpen = value; }">
                <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-2xl">
                <SheetHeader><SheetTitle>Tambah berita</SheetTitle><SheetDescription>Berita aktif tampil di halaman berita pelanggan.</SheetDescription></SheetHeader>
                <form class="grid gap-3 md:grid-cols-3" @submit.prevent="newsForm.post(base + '/content/news', { preserveScroll: true, onSuccess: () => { newsForm.reset(); newsFormOpen = false; } })">
                    <label class="lf-admin-field "><span>Judul</span><Input v-model="newsForm.title" required placeholder="Judul berita" class="rounded border border-border p-2 text-sm" /><span v-if="newsForm.errors.title" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.title }}</span></label>
                    <details><summary class="cursor-pointer text-sm">Ubah tautan berita (lanjutan)</summary><label class="lf-admin-field mt-2"><span>Bagian alamat halaman</span><Input v-model="newsForm.slug" placeholder="Dibuat otomatis dari judul" class="rounded border border-border p-2 text-sm" /><span v-if="newsForm.errors.slug" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.slug }}</span></label></details>
                    <label class="lf-admin-field "><span>Sumber berita</span><Input v-model="newsForm.source_label" placeholder="Sumber/label" class="rounded border border-border p-2 text-sm" /><span v-if="newsForm.errors.source_label" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.source_label }}</span></label>
                    <label class="lf-admin-field md:col-span-3"><span>Ringkasan</span><Textarea v-model="newsForm.summary" rows="2" placeholder="Ringkasan" class="rounded border border-border p-2 text-sm"></Textarea><span v-if="newsForm.errors.summary" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.summary }}</span></label>
                    <label class="lf-admin-field md:col-span-3"><span>Isi</span><Textarea v-model="newsForm.body" rows="5" placeholder="Isi berita" class="rounded border border-border p-2 text-sm"></Textarea><span v-if="newsForm.errors.body" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.body }}</span></label>
                    <label class="lf-admin-field "><span>Urutan tampil</span><Input v-model.number="newsForm.sort_order" type="number" min="0" placeholder="Urutan" class="rounded border border-border p-2 text-sm" /><span v-if="newsForm.errors.sort_order" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.sort_order }}</span></label>
                    <label class="lf-admin-field "><span>Tanggal publikasi</span><Input v-model="newsForm.published_at" type="datetime-local" class="rounded border border-border p-2 text-sm" /><span v-if="newsForm.errors.published_at" role="alert" class="text-sm font-normal text-destructive">{{ newsForm.errors.published_at }}</span></label>
                    <label class="flex items-center gap-2 text-xs"><AdminSwitch v-model="newsForm.is_active" /> Aktif</label>
                    <div class="md:col-span-3 flex gap-2"><Button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Tambah berita</Button><Button type="button" variant="outline" class="px-4 py-2 text-xs" @click="newsFormOpen = false">Batal</Button></div>
                </form>
                </SheetContent>
                </Sheet>

                <div class="mt-5">
                    <div class="divide-y divide-border border-t border-border">
                        <button v-for="item in news" :key="item.id" type="button" class="flex w-full items-start justify-between gap-3 py-3 text-left" :aria-expanded="selectedNewsId === item.id" @click="selectedNewsId = selectedNewsId === item.id ? null : item.id">
                            <span class="min-w-0"><strong class="block truncate text-sm">{{ item.title }}</strong><span class="mt-1 block text-xs text-muted-foreground">{{ item.is_active ? 'Aktif' : 'Nonaktif' }} · {{ item.source_label || 'Tanpa sumber' }}</span></span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ selectedNewsId === item.id ? 'Tutup' : 'Edit' }}</span>
                        </button>
                        <p v-if="!news.length" class="py-5 text-sm text-muted-foreground">Belum ada berita.</p>
                    </div>
                </div>
                <Sheet :open="selectedNewsId !== null" @update:open="(value) => { if (!value) selectedNewsId = null; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-2xl">
                        <SheetHeader><SheetTitle>Edit berita</SheetTitle><SheetDescription>Ubah isi dan gambar berita. Pratinjau di bawah mengikuti tampilan halaman pelanggan.</SheetDescription></SheetHeader>
                    <template v-for="item in news" :key="'editor-'+item.id">
                        <div v-if="selectedNewsId === item.id" class="mt-4 grid gap-4">
                            <div class="space-y-3">
                                <label class="lf-admin-field"><span>Judul</span><Input v-model="item.title" class="text-sm" /></label>
                                <details><summary class="cursor-pointer text-sm">Ubah tautan berita (lanjutan)</summary><label class="lf-admin-field mt-2"><span>Bagian alamat halaman</span><Input v-model="item.slug" class="text-sm" /></label></details>
                                <label class="lf-admin-field"><span>Sumber berita</span><Input v-model="item.source_label" class="text-sm" /></label>
                                <label class="lf-admin-field"><span>Ringkasan</span><Textarea v-model="item.summary" rows="2" class="text-sm" /></label>
                                <label class="lf-admin-field"><span>Isi</span><Textarea v-model="item.body" rows="5" class="text-sm" /></label>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="lf-admin-field"><span>Urutan tampil</span><Input v-model.number="item.sort_order" type="number" min="0" class="text-sm" /></label>
                                    <label class="lf-admin-field"><span>Tanggal publikasi</span><Input v-model="item.published_at" type="datetime-local" class="text-sm" /></label>
                                </div>
                                <label class="flex items-center gap-2 text-xs"><AdminSwitch v-model="item.is_active" /> Aktif</label>
                                <p class="text-xs text-muted-foreground">Gunakan gambar berita dengan fokus utama di tengah agar tetap jelas pada berbagai ukuran layar.</p>
                                <AdminMediaControl type="news" :id="item.id" :url="item.image_url" />
                                <div class="flex gap-2"><Button type="button" @click="saveNews(item)">Simpan</Button><Button type="button" variant="destructive" @click="deleteNews(item)">Hapus</Button></div>
                            </div>
                            <aside class="rounded-lg border border-border bg-muted p-4" aria-label="Pratinjau berita">
                                <h3 class="font-semibold">Pratinjau berita</h3>
                                <article class="mt-3 overflow-hidden rounded-lg border border-border bg-card">
                                    <img v-if="item.image_url" :src="item.image_url" :alt="item.title" class="max-h-56 w-full object-cover">
                                    <div class="p-4"><p class="text-xs text-muted-foreground">{{ item.source_label }} · {{ item.published_at || 'Belum dijadwalkan' }}</p><h4 class="mt-2 text-lg font-bold">{{ item.title }}</h4><p class="mt-2 text-sm text-muted-foreground">{{ item.summary }}</p><p class="mt-3 whitespace-pre-wrap text-sm">{{ item.body }}</p></div>
                                </article>
                            </aside>
                        </div>
                    </template>
                    </SheetContent>
                </Sheet>
            </section>

            <section v-show="contentTab === 'reviews'" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Ulasan pelanggan</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Moderasi ulasan terverifikasi dari pesanan sukses. Isi ulasan tidak diedit Admin — hanya ditampilkan atau disembunyikan.</p>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ reviews.length }} ulasan</p>
                </div>

                <ul v-if="reviews.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="item in reviews" :key="item.id">
                        <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="selectedReviewId = item.id">
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate">{{ item.display_name }} · {{ item.product_name }}</strong>
                                <small class="mt-0.5 block truncate text-muted-foreground">{{ '★'.repeat(item.rating) }}{{ '☆'.repeat(5 - item.rating) }} · {{ item.body }}</small>
                            </span>
                            <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', item.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ item.is_active ? 'Tampil' : 'Disembunyikan' }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">Tinjau</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">Belum ada ulasan masuk.</p>

                <Sheet :open="selectedReviewId !== null" @update:open="(value) => { if (!value) selectedReviewId = null; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                        <SheetHeader><SheetTitle>Tinjau ulasan</SheetTitle><SheetDescription>Putuskan apakah ulasan ini tampil di halaman produk.</SheetDescription></SheetHeader>
                        <template v-for="item in reviews" :key="'review-'+item.id">
                            <div v-if="selectedReviewId === item.id" class="mt-4 space-y-4">
                                <div class="rounded-md border border-border p-4">
                                    <p class="text-sm font-semibold">{{ item.display_name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ item.product_name }}</p>
                                    <p class="mt-2 text-amber-500">{{ '★'.repeat(item.rating) }}{{ '☆'.repeat(5 - item.rating) }}</p>
                                    <p class="mt-2 whitespace-pre-wrap text-sm">{{ item.body }}</p>
                                </div>
                                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.is_active" /> Tampilkan di halaman produk</label>
                                <div class="flex justify-end gap-2 border-t border-border pt-4">
                                    <Button type="button" variant="destructive" @click="deleteReview(item)">Hapus permanen</Button>
                                    <Button type="button" variant="outline" @click="selectedReviewId = null">Tutup</Button>
                                    <Button type="button" @click="saveReview(item)">Simpan</Button>
                                </div>
                            </div>
                        </template>
                    </SheetContent>
                </Sheet>
            </section>

            <section v-show="contentTab === 'faq'" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Pertanyaan umum</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Pertanyaan yang sering ditanyakan pelanggan. Pilih satu baris untuk mengubah pertanyaan, jawaban, dan urutannya.</p>
                    </div>
                    <Button type="button" @click="faqFormOpen = true">Tambah FAQ</Button>
                </div>

                <Sheet :open="faqFormOpen" @update:open="(value) => { faqFormOpen = value; }">
                    <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                        <SheetHeader><SheetTitle>Tambah FAQ</SheetTitle><SheetDescription>Pertanyaan aktif tampil di halaman bantuan pelanggan.</SheetDescription></SheetHeader>
                        <form class="mt-4 grid gap-3" @submit.prevent="faqForm.post(base + '/content/faqs', { preserveScroll: true, onSuccess: () => { faqForm.reset(); faqFormOpen = false; } })">
                            <label class="lf-admin-field"><span>Pertanyaan</span><Input v-model="faqForm.question" required placeholder="Pertanyaan" /><span v-if="faqForm.errors.question" role="alert" class="text-xs font-normal text-destructive">{{ faqForm.errors.question }}</span></label>
                            <label class="lf-admin-field"><span>Jawaban</span><Textarea v-model="faqForm.answer" required rows="4" placeholder="Jawaban"></Textarea><span v-if="faqForm.errors.answer" role="alert" class="text-xs font-normal text-destructive">{{ faqForm.errors.answer }}</span></label>
                            <label class="lf-admin-field"><span>Urutan tampil</span><Input v-model.number="faqForm.sort_order" type="number" min="0" /><span class="text-xs font-normal text-muted-foreground">Angka lebih kecil tampil lebih dulu.</span></label>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="faqForm.is_active" /> Aktif</label>
                            <div class="flex justify-end gap-2 border-t border-border pt-4"><Button type="button" variant="outline" @click="faqFormOpen = false">Batal</Button><Button :disabled="faqForm.processing">Tambah FAQ</Button></div>
                        </form>
                    </SheetContent>
                </Sheet>

                <ul v-if="faqs.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="item in faqs" :key="item.id">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="selectedFaqId = item.id">
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate">{{ item.question }}</strong>
                                <small class="mt-0.5 block truncate text-muted-foreground">{{ item.answer }}</small>
                            </span>
                            <span class="shrink-0 text-xs text-muted-foreground">Posisi {{ item.sort_order }}</span>
                            <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', item.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                        </button>
                        <Sheet :open="selectedFaqId === item.id" @update:open="(value) => { if (!value) selectedFaqId = null; }">
                        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                            <SheetHeader><SheetTitle>Ubah FAQ</SheetTitle><SheetDescription>Ubah pertanyaan, jawaban, dan status tampil.</SheetDescription></SheetHeader>

                                <div v-if="selectedFaqId === item.id" class="mt-4 space-y-3">
                                    <label class="lf-admin-field"><span>Pertanyaan</span><Input v-model="item.question" /></label>
                                    <label class="lf-admin-field"><span>Jawaban</span><Textarea v-model="item.answer" rows="5"></Textarea></label>
                                    <label class="lf-admin-field"><span>Urutan tampil</span><Input v-model.number="item.sort_order" type="number" min="0" /></label>
                                    <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.is_active" /> Aktif</label>
                                    <div class="flex justify-end gap-2 border-t border-border pt-4">
                                        <Button type="button" variant="destructive" @click="deleteFaq(item)">Hapus</Button>
                                        <Button type="button" variant="outline" @click="selectedFaqId = null">Tutup</Button>
                                        <Button type="button" @click="saveFaq(item)">Simpan</Button>
                                    </div>
                                </div>

                        </SheetContent>
                    </Sheet>
</li>
                </ul>
                <p v-else class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">Belum ada pertanyaan umum.</p>

                            </section>


            <section v-show="contentTab === 'legal'" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold">Kebijakan toko</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Halaman kebijakan yang dibaca pelanggan. Pilih satu baris untuk mengubah isinya dan melihat pratinjau.</p>
                </div>

                <ul v-if="pages.length" class="divide-y divide-border rounded-md border border-border">
                    <li v-for="item in pages" :key="item.key">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="selectedPageKey = item.key">
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate">{{ item.title || pageLabel(item.key) }}</strong>
                                <small class="mt-0.5 block truncate text-muted-foreground">{{ item.intro || 'Belum ada pengantar' }}</small>
                            </span>
                            <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', item.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                        </button>
                        <Sheet :open="selectedPageKey === item.key" @update:open="(value) => { if (!value) selectedPageKey = null; }">
                            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-4xl">
                                <SheetHeader><SheetTitle>Ubah kebijakan</SheetTitle><SheetDescription>Perubahan langsung dipakai halaman kebijakan pelanggan.</SheetDescription></SheetHeader>
                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div class="space-y-3">
                                        <p class="text-xs text-muted-foreground">{{ pageLabel(item.key) }}</p>
                                        <label class="lf-admin-field"><span>Judul halaman</span><Input v-model="item.title" /></label>
                                        <label class="lf-admin-field"><span>Pengantar</span><Textarea v-model="item.intro" rows="2"></Textarea></label>
                                        <label class="lf-admin-field"><span>Isi</span><Textarea v-model="item.body" rows="10"></Textarea>
                                            <span class="text-xs font-normal text-muted-foreground">Awali paragraf dengan ## untuk judul bagian, dan - untuk item daftar.</span>
                                        </label>
                                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="item.is_active" /> Aktif</label>
                                        <div class="flex justify-end gap-2 border-t border-border pt-4">
                                            <Button type="button" variant="outline" @click="selectedPageKey = null">Tutup</Button>
                                            <Button type="button" @click="savePage(item)">Simpan</Button>
                                        </div>
                                    </div>
                                    <aside class="rounded-lg border border-border bg-muted p-4" aria-label="Pratinjau kebijakan">
                                        <h3 class="font-semibold">Pratinjau halaman</h3>
                                        <h4 class="mt-3 text-lg font-bold">{{ item.title }}</h4>
                                        <p class="mt-2 whitespace-pre-wrap text-sm">{{ item.intro }}</p>
                                        <p class="mt-3 whitespace-pre-wrap text-sm">{{ item.body }}</p>
                                    </aside>
                                </div>
                            </SheetContent>
                        </Sheet>
                    </li>
                </ul>
                <p v-else class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">Belum ada halaman kebijakan.</p>
            </section>
        </div>

        <AdminConfirmDialog
            :open="Boolean(pendingAction)"
            :title="pendingAction?.title || ''"
            :message="pendingAction?.message || ''"
            :confirm-label="pendingAction?.confirmLabel || 'Hapus'"
            destructive
            @update:open="value => { if (!value) pendingAction = null; }"
            @confirm="runPending"
        />
    </AdminShell>
</template>
