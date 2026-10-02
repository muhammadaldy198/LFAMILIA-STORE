<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';

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
const contentTab = ref('banners');
const contentTabs = [['banners','Banner'],['popup','Pop-up'],['assets','Logo & Gambar'],['support','Kontak & Footer'],['news','Berita'],['reviews','Ulasan'],['faq','FAQ'],['legal','Kebijakan']];
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
    support_whatsapp: props.settings?.['store.support_whatsapp'] || '',
    instagram_url: props.settings?.['store.instagram_url'] || '',
    email: props.settings?.['store.email'] || '',
    discord_url: props.settings?.['store.discord_url'] || '',
    support_url: props.settings?.['store.support_url'] || '',
    business_hours: props.settings?.['store.business_hours'] || '',
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
const deleteBanner = (item) => {
    if (confirm('Hapus banner ini?')) router.delete(base + '/content/banners/' + item.id, { preserveScroll: true });
};

const savePopup = (item) => router.put(base + '/content/popups/' + item.id, {
    title: item.title,
    body: item.body,
    dismiss_days: Number(item.dismiss_days || 0),
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });
const deletePopup = (item) => {
    if (confirm('Hapus pop-up ini?')) router.delete(base + '/content/popups/' + item.id, { preserveScroll: true });
};
const assetLabel = (key) => ({
    logo: 'Logo toko',
    favicon: 'Favicon',
    banner_desktop: 'Banner cadangan desktop',
    banner_mobile: 'Banner cadangan mobile',
    footer_banner_desktop: 'Gambar footer desktop',
    footer_banner_mobile: 'Gambar footer mobile',
    manual_qris: 'QRIS manual',
    payment_header: 'Gambar halaman pembayaran',
}[key] || key.replaceAll('_', ' '));

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

const deleteNews = (item) => {
    if (confirm('Hapus berita "' + item.title + '"?')) router.delete(base + '/content/news/' + item.id, { preserveScroll: true });
};

const saveFaq = (item) => router.put(base + '/content/faqs/' + item.id, {
    question: item.question,
    answer: item.answer,
    sort_order: Number(item.sort_order || 0),
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });

const deleteFaq = (item) => {
    if (confirm('Hapus FAQ ini?')) router.delete(base + '/content/faqs/' + item.id, { preserveScroll: true });
};
const saveReview = (item) => router.put(base + '/content/reviews/' + item.id, {
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });

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
            <div>
                <h1 class="text-3xl font-semibold">Banner & Konten</h1>
                <p class="mt-2 text-sm text-slate-500">Kelola konten halaman pelanggan tanpa mengubah kode aplikasi.</p>
            </div>

            <nav class="lf-admin-tabs" aria-label="Konten toko"><Button v-for="[key,label] in contentTabs" :key="key" type="button" :class="{active:contentTab===key}" @click="contentTab=key">{{label}}</Button><Link :href="base + '/content/presentation'">Teks & Tampilan</Link></nav>
            <section v-show="contentTab === 'banners'" class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold">Banner halaman utama</h2>
                        <p class="mt-1 text-xs text-slate-500">Atur beberapa banner, urutan, gambar desktop/mobile, tujuan klik, serta status tampil. Banner aktif digunakan langsung di halaman pelanggan.</p>
                    </div>
                </div>

                <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="bannerForm.post(base + '/content/banners', { preserveScroll: true, onSuccess: () => bannerForm.reset() })">
                    <Input v-model="bannerForm.title" required placeholder="Nama internal / alt banner" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model="bannerForm.cta_href" placeholder="Link klik, contoh /promo" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model.number="bannerForm.sort_order" type="number" min="0" placeholder="Urutan" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model="bannerForm.subtitle" placeholder="Catatan internal (opsional)" class="rounded border border-slate-200 p-2 text-sm md:col-span-3" />
                    <label class="flex items-center gap-2 text-xs"><input v-model="bannerForm.show_desktop" type="checkbox"> Desktop</label>
                    <label class="flex items-center gap-2 text-xs"><input v-model="bannerForm.show_mobile" type="checkbox"> Mobile</label>
                    <label class="flex items-center gap-2 text-xs"><input v-model="bannerForm.is_active" type="checkbox"> Aktif</label>
                    <div class="md:col-span-3"><Button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Tambah Banner</Button></div>
                </form>

                <div class="mt-5 space-y-4">
                    <article v-for="item in banners" :key="item.id" class="rounded-lg border border-slate-200 p-4">
                        <div class="grid gap-2 md:grid-cols-3">
                            <Input v-model="item.title" class="rounded border border-slate-200 p-2 text-sm" placeholder="Nama banner" />
                            <Input v-model="item.cta_href" class="rounded border border-slate-200 p-2 text-sm" placeholder="Link klik" />
                            <Input v-model.number="item.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-sm" placeholder="Urutan" />
                            <Input v-model="item.subtitle" class="rounded border border-slate-200 p-2 text-sm md:col-span-3" placeholder="Catatan internal" />
                        </div>
                        <div class="mt-3 flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-xs"><input v-model="item.show_desktop" type="checkbox"> Desktop</label>
                            <label class="flex items-center gap-2 text-xs"><input v-model="item.show_mobile" type="checkbox"> Mobile</label>
                            <label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label>
                        </div>
                        <div class="mt-3 grid gap-3 lg:grid-cols-2">
                            <div>
                                
                                <AdminMediaControl type="banner" :id="item.id" collection="desktop" :url="item.desktop_url" />
                            </div>
                            <div>
                                
                                <AdminMediaControl type="banner" :id="item.id" collection="mobile" :url="item.mobile_url" />
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <Button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveBanner(item)">Simpan</Button>
                            <Button type="button" class="rounded bg-red-50 px-3 py-2 text-xs font-bold text-red-700" @click="deleteBanner(item)">Hapus</Button>
                        </div>
                    </article>
                    <p v-if="!banners.length" class="rounded bg-slate-50 p-4 text-xs text-slate-500">Belum ada banner utama. Selama kosong, halaman pelanggan memakai gambar banner cadangan yang aktif.</p>
                </div>
            </section>

            <section v-show="contentTab === 'popup'" class="rounded-xl border border-slate-200 bg-white p-5">
                <div>
                    <h2 class="text-lg font-bold">Pop-up Homepage</h2>
                    <p class="mt-1 text-xs text-slate-500">Hanya satu pop-up pengumuman. Muncul saat pelanggan membuka halaman utama dan dapat disembunyikan sesuai masa yang ditentukan.</p>
                </div>

                <form v-if="!popups.length" class="mt-4 grid gap-3" @submit.prevent="popupForm.post(base + '/content/popups', { preserveScroll: true })">
                    <Input v-model="popupForm.title" required placeholder="Judul pop-up" class="rounded border border-slate-200 p-2 text-sm" />
                    <Textarea v-model="popupForm.body" required rows="5" placeholder="Isi pengumuman" class="rounded border border-slate-200 p-2 text-sm"></Textarea>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-xs">Jangan tampil lagi selama (hari)<Input v-model.number="popupForm.dismiss_days" type="number" min="0" max="365" class="mt-1 w-full rounded border border-slate-200 p-2 text-sm" /></label>
                        <label class="flex items-center gap-2 self-end pb-2 text-xs"><input v-model="popupForm.is_active" type="checkbox"> Aktif</label>
                    </div>
                    <div><Button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Simpan Pop-up</Button></div>
                </form>

                <article v-else class="mt-4 rounded-lg border border-slate-200 p-4">
                    <Input v-model="popups[0].title" class="w-full rounded border border-slate-200 p-2 text-sm" placeholder="Judul pop-up" />
                    <Textarea v-model="popups[0].body" rows="5" class="mt-3 w-full rounded border border-slate-200 p-2 text-sm" placeholder="Isi pengumuman"></Textarea>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="text-xs">Jangan tampil lagi selama (hari)<Input v-model.number="popups[0].dismiss_days" type="number" min="0" max="365" class="mt-1 w-full rounded border border-slate-200 p-2 text-sm" /></label>
                        <label class="flex items-center gap-2 self-end pb-2 text-xs"><input v-model="popups[0].is_active" type="checkbox"> Aktif</label>
                    </div>
                    <div class="mt-3 rounded-lg bg-slate-50 p-3">
                        <p class="mb-2 text-[11px] text-slate-500">Gambar pop-up bersifat opsional dan dapat diganti dari panel ini.</p>
                        <AdminMediaControl type="popup" :id="popups[0].id" :url="popups[0].image_url" />
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="savePopup(popups[0])">Simpan</Button>
                        <Button type="button" variant="destructive" @click="deletePopup(popups[0])">Hapus pop-up</Button>
                    </div>
                </article>
            </section>

            <section v-show="contentTab === 'assets'" class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Logo & gambar cadangan</h2>
                <p class="mt-1 text-xs text-slate-500">Kelola logo, favicon, banner cadangan, gambar footer, dan aset tampilan lain yang digunakan toko.</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <article v-for="asset in assets" :key="asset.id" class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <strong class="text-sm">{{ assetLabel(asset.key) }}</strong>
                            <label class="flex items-center gap-2 text-xs"><input v-model="asset.is_active" type="checkbox"> Aktif</label>
                        </div>
                        <label v-if="asset.key.includes('banner')" class="mt-3 block text-xs">
                            Link ketika banner ditekan
                            <Input v-model="asset.target_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2" placeholder="https://..." />
                        </label>
                        
                        <div class="mt-3"><AdminMediaControl type="asset" :asset-key="asset.key" :id="asset.id" :url="asset.image_url" /></div>
                        <Button type="button" class="mt-3 rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveAsset(asset)">Simpan</Button>
                    </article>
                </div>
            </section>

            <section v-show="contentTab === 'support'" class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Kontak, bantuan & footer</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="settingsForm.put(base + '/content/settings')">
                    <label class="text-xs">Judul berita halaman utama<Input v-model="settingsForm.home_news_title" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="text-xs">Jam layanan<Input v-model="settingsForm.business_hours" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="text-xs md:col-span-2">Pengantar berita halaman utama<Textarea v-model="settingsForm.home_news_intro" rows="2" class="mt-1 block w-full rounded border border-slate-200 p-2"></Textarea></label>
                    <label class="flex items-center gap-2 text-xs md:col-span-2"><input v-model="settingsForm.support_cta_enabled" type="checkbox"> Blok bantuan menjelang footer aktif</label>
                    <label class="text-xs">Label kecil bantuan<Input v-model="settingsForm.support_cta_label" maxlength="80" class="mt-1 block w-full rounded border border-slate-200 p-2" placeholder="BUTUH BANTUAN?" /></label>
                    <label class="text-xs">Teks tombol bantuan<Input v-model="settingsForm.support_cta_button" maxlength="80" class="mt-1 block w-full rounded border border-slate-200 p-2" placeholder="Hubungi Kami" /></label>
                    <label class="text-xs md:col-span-2">Judul bantuan<Input v-model="settingsForm.support_cta_title" maxlength="180" class="mt-1 block w-full rounded border border-slate-200 p-2" placeholder="Tim LFAMILIA siap membantu." /></label>
                    <label class="text-xs md:col-span-2">Deskripsi bantuan<Textarea v-model="settingsForm.support_cta_body" rows="2" maxlength="1000" class="mt-1 block w-full rounded border border-slate-200 p-2"></Textarea></label>
                    <label class="text-xs md:col-span-2">Deskripsi footer<Textarea v-model="settingsForm.footer_description" rows="2" class="mt-1 block w-full rounded border border-slate-200 p-2"></Textarea></label>
                    <label class="text-xs">WhatsApp<Input v-model="settingsForm.support_whatsapp" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="text-xs">Email<Input v-model="settingsForm.email" type="email" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="text-xs">Instagram URL<Input v-model="settingsForm.instagram_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="text-xs">Discord URL<Input v-model="settingsForm.discord_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="text-xs">Tautan halaman bantuan<Input v-model="settingsForm.support_url" type="text" class="mt-1 block w-full rounded border border-slate-200 p-2" /></label>
                    <label class="flex items-center gap-2 self-end text-xs"><input v-model="settingsForm.support_widget_enabled" type="checkbox"> Tombol bantuan mengambang aktif</label>
                    <div class="md:col-span-2"><Button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Simpan konten umum</Button></div>
                </form>
            </section>

            <section v-show="contentTab === 'news'" class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Berita</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="newsForm.post(base + '/content/news', { preserveScroll: true, onSuccess: () => newsForm.reset() })">
                    <Input v-model="newsForm.title" required placeholder="Judul berita" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model="newsForm.slug" placeholder="Slug (opsional)" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model="newsForm.source_label" placeholder="Sumber/label" class="rounded border border-slate-200 p-2 text-sm" />
                    <Textarea v-model="newsForm.summary" rows="2" placeholder="Ringkasan" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></Textarea>
                    <Textarea v-model="newsForm.body" rows="5" placeholder="Isi berita" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></Textarea>
                    <Input v-model.number="newsForm.sort_order" type="number" min="0" placeholder="Urutan" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model="newsForm.published_at" type="datetime-local" class="rounded border border-slate-200 p-2 text-sm" />
                    <label class="flex items-center gap-2 text-xs"><input v-model="newsForm.is_active" type="checkbox"> Aktif</label>
                    <div class="md:col-span-3"><Button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Tambah berita</Button></div>
                </form>

                <div class="mt-5 space-y-4">
                    <article v-for="item in news" :key="item.id" class="rounded-lg border border-slate-200 p-4">
                        <div class="grid gap-2 md:grid-cols-3">
                            <Input v-model="item.title" class="rounded border border-slate-200 p-2 text-sm" />
                            <Input v-model="item.slug" class="rounded border border-slate-200 p-2 text-sm" />
                            <Input v-model="item.source_label" class="rounded border border-slate-200 p-2 text-sm" />
                            <Textarea v-model="item.summary" rows="2" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></Textarea>
                            <Textarea v-model="item.body" rows="4" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></Textarea>
                            <Input v-model.number="item.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-sm" />
                            <Input v-model="item.published_at" type="datetime-local" class="rounded border border-slate-200 p-2 text-sm" />
                            <label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">Gunakan gambar berita dengan fokus utama di tengah agar tetap jelas pada berbagai ukuran layar.</p>
                        <div class="mt-3"><AdminMediaControl type="news" :id="item.id" :url="item.image_url" /></div>
                        <div class="mt-3 flex gap-2"><Button class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveNews(item)">Simpan</Button><Button class="rounded bg-red-50 px-3 py-2 text-xs font-bold text-red-700" @click="deleteNews(item)">Hapus</Button></div>
                    </article>
                </div>
            </section>

            <section v-show="contentTab === 'reviews'" class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-bold">Ulasan Pelanggan</h2><p class="mt-1 text-xs text-slate-500">Moderasi ulasan terverifikasi dari order sukses. Isi ulasan tidak diedit oleh Admin.</p></div><span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold">{{reviews.length}} ulasan</span></div>
                <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200">
                    <Table class="w-full min-w-[780px] text-left text-xs">
                        <TableHeader class="bg-slate-50 text-slate-500"><TableRow><TableHead class="p-3">Pelanggan</TableHead><TableHead class="p-3">Produk</TableHead><TableHead class="p-3">Rating</TableHead><TableHead class="p-3">Ulasan</TableHead><TableHead class="p-3">Status</TableHead><TableHead class="p-3">Aksi</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="item in reviews" :key="item.id" class="border-t border-slate-200">
                                <TableCell class="p-3 font-semibold">{{item.display_name}}</TableCell>
                                <TableCell class="p-3">{{item.product_name}}</TableCell>
                                <TableCell class="p-3 text-amber-500">{{'★'.repeat(item.rating)}}{{'☆'.repeat(5-item.rating)}}</TableCell>
                                <TableCell class="max-w-[360px] p-3 text-slate-600">{{item.body}}</TableCell>
                                <TableCell class="p-3"><label class="flex items-center gap-2"><input v-model="item.is_active" type="checkbox"> Tampil</label></TableCell>
                                <TableCell class="p-3"><Button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveReview(item)">Simpan</Button></TableCell>
                            </TableRow>
                            <TableRow v-if="!reviews.length"><TableCell colspan="6" class="p-6 text-center text-slate-400">Belum ada ulasan.</TableCell></TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>

            <section v-show="contentTab === 'faq'" class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Pertanyaan umum</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-[1fr_120px_auto]" @submit.prevent="faqForm.post(base + '/content/faqs', { preserveScroll: true, onSuccess: () => faqForm.reset() })">
                    <Input v-model="faqForm.question" required placeholder="Pertanyaan" class="rounded border border-slate-200 p-2 text-sm" />
                    <Input v-model.number="faqForm.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-sm" />
                    <label class="flex items-center gap-2 text-xs"><input v-model="faqForm.is_active" type="checkbox"> Aktif</label>
                    <Textarea v-model="faqForm.answer" required rows="3" placeholder="Jawaban" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></Textarea>
                    <div class="md:col-span-3"><Button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Tambah FAQ</Button></div>
                </form>
                <div class="mt-5 space-y-3">
                    <article v-for="item in faqs" :key="item.id" class="rounded-lg border border-slate-200 p-4">
                        <Input v-model="item.question" class="w-full rounded border border-slate-200 p-2 text-sm" />
                        <Textarea v-model="item.answer" rows="3" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm"></Textarea>
                        <div class="mt-2 flex flex-wrap items-center gap-3"><label class="text-xs">Urutan <Input v-model.number="item.sort_order" type="number" min="0" class="ml-1 w-20 rounded border border-slate-200 p-1" /></label><label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label><Button class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveFaq(item)">Simpan</Button><Button class="rounded bg-red-50 px-3 py-2 text-xs font-bold text-red-700" @click="deleteFaq(item)">Hapus</Button></div>
                    </article>
                </div>
            </section>

            <section v-show="contentTab === 'legal'" class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Kebijakan toko</h2><p class="lf-admin-note">Gunakan ## di awal paragraf untuk judul bagian, dan - di awal paragraf untuk item daftar.</p>
                <div class="mt-4 space-y-4">
                    <article v-for="item in pages" :key="item.key" class="rounded-lg border border-slate-200 p-4">
                        <strong class="text-xs uppercase text-slate-500">{{ item.key }}</strong>
                        <Input v-model="item.title" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm" />
                        <Textarea v-model="item.intro" rows="2" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm"></Textarea>
                        <Textarea v-model="item.body" rows="7" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm"></Textarea>
                        <div class="mt-2 flex gap-3"><label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label><Button class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="savePage(item)">Simpan</Button></div>
                    </article>
                </div>
            </section>
        </div>
    </AdminShell>
</template>
