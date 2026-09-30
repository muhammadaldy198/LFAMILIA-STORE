<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminMediaControl from '../../Components/AdminMediaControl.vue';

const props = defineProps({
    assets: Array,
    news: Array,
    faqs: Array,
    pages: Array,
    reviews: Array,
    settings: Object,
});

const assets = ref((props.assets || []).map((x) => ({ ...x })));
const news = ref((props.news || []).map((x) => ({ ...x })));
const faqs = ref((props.faqs || []).map((x) => ({ ...x })));
const pages = ref((props.pages || []).map((x) => ({ ...x })));
const reviews = ref((props.reviews || []).map((x) => ({ ...x })));

watch(() => props.assets, (v) => { assets.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.news, (v) => { news.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.faqs, (v) => { faqs.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.pages, (v) => { pages.value = (v || []).map((x) => ({ ...x })); });
watch(() => props.reviews, (v) => { reviews.value = (v || []).map((x) => ({ ...x })); });

const settingsForm = useForm({
    support_widget_enabled: Boolean(props.settings?.['store.support_widget_enabled'] ?? true),
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

const newsForm = useForm({
    slug: '', title: '', summary: '', body: '', source_label: 'LFAMILIA News',
    sort_order: 0, is_active: false, published_at: '',
});

const faqForm = useForm({
    question: '', answer: '', sort_order: 0, is_active: true,
});

const assetHint = (key) => ({
    logo: 'Patokan aset LFAMILIA lama: 320×320 (1:1) · PNG/WebP transparan',
    favicon: 'Rekomendasi 512×512 (1:1)',
    banner_desktop: '1920×600 · fokus elemen penting di area tengah',
    banner_mobile: '1080×1080 · aman untuk crop mobile',
    popup: 'Rekomendasi 1080×1080 · konten penting jangan terlalu dekat tepi',
    footer_banner_desktop: '2172×724 · rasio ±3:1',
    footer_banner_mobile: '1200×400 · rasio 3:1',
}[key] || 'Gunakan gambar tajam dengan ukuran file efisien.');

const saveAsset = (asset) => router.put('/admin/catalog/assets/' + asset.id, {
    is_active: Boolean(asset.is_active),
    target_url: asset.target_url || null,
});

const saveNews = (item) => router.put('/admin/content/news/' + item.id, {
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
    if (confirm('Hapus berita "' + item.title + '"?')) router.delete('/admin/content/news/' + item.id, { preserveScroll: true });
};

const saveFaq = (item) => router.put('/admin/content/faqs/' + item.id, {
    question: item.question,
    answer: item.answer,
    sort_order: Number(item.sort_order || 0),
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });

const deleteFaq = (item) => {
    if (confirm('Hapus FAQ ini?')) router.delete('/admin/content/faqs/' + item.id, { preserveScroll: true });
};
const saveReview = (item) => router.put('/admin/content/reviews/' + item.id, {
    is_active: Boolean(item.is_active),
}, { preserveScroll: true });

const savePage = (item) => router.put('/admin/content/pages/' + item.key, {
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
                <p class="mt-2 text-sm text-slate-500">Kelola konten customer frontend tanpa mengubah source code.</p>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Branding & banner</h2>
                <p class="mt-1 text-xs text-slate-500">Logo, favicon, banner atas, popup, serta banner bawah/footer.</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <article v-for="asset in assets" :key="asset.id" class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <strong class="text-sm">{{ asset.key }}</strong>
                            <label class="flex items-center gap-2 text-xs"><input v-model="asset.is_active" type="checkbox"> Aktif</label>
                        </div>
                        <label v-if="asset.key.includes('banner')" class="mt-3 block text-xs">
                            Link ketika banner ditekan
                            <input v-model="asset.target_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2" placeholder="https://...">
                        </label>
                        <p class="mt-3 rounded bg-slate-50 px-3 py-2 text-[11px] text-slate-500">{{ assetHint(asset.key) }}</p>
                        <div class="mt-3"><AdminMediaControl type="asset" :id="asset.id" :url="asset.image_url" /></div>
                        <button type="button" class="mt-3 rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveAsset(asset)">Simpan</button>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Customer support & homepage text</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="settingsForm.put('/admin/content/settings')">
                    <label class="text-xs">Judul berita homepage<input v-model="settingsForm.home_news_title" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="text-xs">Jam layanan<input v-model="settingsForm.business_hours" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="text-xs md:col-span-2">Intro berita homepage<textarea v-model="settingsForm.home_news_intro" rows="2" class="mt-1 block w-full rounded border border-slate-200 p-2"></textarea></label>
                    <label class="text-xs md:col-span-2">Deskripsi footer<textarea v-model="settingsForm.footer_description" rows="2" class="mt-1 block w-full rounded border border-slate-200 p-2"></textarea></label>
                    <label class="text-xs">WhatsApp<input v-model="settingsForm.support_whatsapp" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="text-xs">Email<input v-model="settingsForm.email" type="email" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="text-xs">Instagram URL<input v-model="settingsForm.instagram_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="text-xs">Discord URL<input v-model="settingsForm.discord_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="text-xs">Support URL<input v-model="settingsForm.support_url" type="url" class="mt-1 block w-full rounded border border-slate-200 p-2"></label>
                    <label class="flex items-center gap-2 self-end text-xs"><input v-model="settingsForm.support_widget_enabled" type="checkbox"> Floating bantuan aktif</label>
                    <div class="md:col-span-2"><button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Simpan konten umum</button></div>
                </form>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Berita</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="newsForm.post('/admin/content/news', { preserveScroll: true, onSuccess: () => newsForm.reset() })">
                    <input v-model="newsForm.title" required placeholder="Judul berita" class="rounded border border-slate-200 p-2 text-sm">
                    <input v-model="newsForm.slug" placeholder="Slug (opsional)" class="rounded border border-slate-200 p-2 text-sm">
                    <input v-model="newsForm.source_label" placeholder="Sumber/label" class="rounded border border-slate-200 p-2 text-sm">
                    <textarea v-model="newsForm.summary" rows="2" placeholder="Ringkasan" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></textarea>
                    <textarea v-model="newsForm.body" rows="5" placeholder="Isi berita" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></textarea>
                    <input v-model.number="newsForm.sort_order" type="number" min="0" placeholder="Urutan" class="rounded border border-slate-200 p-2 text-sm">
                    <input v-model="newsForm.published_at" type="datetime-local" class="rounded border border-slate-200 p-2 text-sm">
                    <label class="flex items-center gap-2 text-xs"><input v-model="newsForm.is_active" type="checkbox"> Aktif</label>
                    <div class="md:col-span-3"><button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Tambah berita</button></div>
                </form>

                <div class="mt-5 space-y-4">
                    <article v-for="item in news" :key="item.id" class="rounded-lg border border-slate-200 p-4">
                        <div class="grid gap-2 md:grid-cols-3">
                            <input v-model="item.title" class="rounded border border-slate-200 p-2 text-sm">
                            <input v-model="item.slug" class="rounded border border-slate-200 p-2 text-sm">
                            <input v-model="item.source_label" class="rounded border border-slate-200 p-2 text-sm">
                            <textarea v-model="item.summary" rows="2" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></textarea>
                            <textarea v-model="item.body" rows="4" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></textarea>
                            <input v-model.number="item.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-sm">
                            <input v-model="item.published_at" type="datetime-local" class="rounded border border-slate-200 p-2 text-sm">
                            <label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label>
                        </div>
                        <p class="mt-3 rounded bg-slate-50 px-3 py-2 text-[11px] text-slate-500">Gambar berita: rekomendasi 1200×675 (16:9), fokus utama di tengah agar aman saat card di-crop.</p>
                        <div class="mt-3"><AdminMediaControl type="news" :id="item.id" :url="item.image_url" /></div>
                        <div class="mt-3 flex gap-2"><button class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveNews(item)">Simpan</button><button class="rounded bg-red-50 px-3 py-2 text-xs font-bold text-red-700" @click="deleteNews(item)">Hapus</button></div>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-bold">Ulasan Pelanggan</h2><p class="mt-1 text-xs text-slate-500">Moderasi ulasan terverifikasi dari order sukses. Isi ulasan tidak diedit oleh Admin.</p></div><span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold">{{reviews.length}} ulasan</span></div>
                <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[780px] text-left text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="p-3">Pelanggan</th><th class="p-3">Produk</th><th class="p-3">Rating</th><th class="p-3">Ulasan</th><th class="p-3">Status</th><th class="p-3">Aksi</th></tr></thead>
                        <tbody>
                            <tr v-for="item in reviews" :key="item.id" class="border-t border-slate-200">
                                <td class="p-3 font-semibold">{{item.display_name}}</td>
                                <td class="p-3">{{item.product_name}}</td>
                                <td class="p-3 text-amber-500">{{'★'.repeat(item.rating)}}{{'☆'.repeat(5-item.rating)}}</td>
                                <td class="max-w-[360px] p-3 text-slate-600">{{item.body}}</td>
                                <td class="p-3"><label class="flex items-center gap-2"><input v-model="item.is_active" type="checkbox"> Tampil</label></td>
                                <td class="p-3"><button type="button" class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveReview(item)">Simpan</button></td>
                            </tr>
                            <tr v-if="!reviews.length"><td colspan="6" class="p-6 text-center text-slate-400">Belum ada ulasan.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">FAQ / Pertanyaan umum</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-[1fr_120px_auto]" @submit.prevent="faqForm.post('/admin/content/faqs', { preserveScroll: true, onSuccess: () => faqForm.reset() })">
                    <input v-model="faqForm.question" required placeholder="Pertanyaan" class="rounded border border-slate-200 p-2 text-sm">
                    <input v-model.number="faqForm.sort_order" type="number" min="0" class="rounded border border-slate-200 p-2 text-sm">
                    <label class="flex items-center gap-2 text-xs"><input v-model="faqForm.is_active" type="checkbox"> Aktif</label>
                    <textarea v-model="faqForm.answer" required rows="3" placeholder="Jawaban" class="rounded border border-slate-200 p-2 text-sm md:col-span-3"></textarea>
                    <div class="md:col-span-3"><button class="rounded bg-[#1769e8] px-4 py-2 text-xs font-bold text-white">Tambah FAQ</button></div>
                </form>
                <div class="mt-5 space-y-3">
                    <article v-for="item in faqs" :key="item.id" class="rounded-lg border border-slate-200 p-4">
                        <input v-model="item.question" class="w-full rounded border border-slate-200 p-2 text-sm">
                        <textarea v-model="item.answer" rows="3" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm"></textarea>
                        <div class="mt-2 flex flex-wrap items-center gap-3"><label class="text-xs">Urutan <input v-model.number="item.sort_order" type="number" min="0" class="ml-1 w-20 rounded border border-slate-200 p-1"></label><label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label><button class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="saveFaq(item)">Simpan</button><button class="rounded bg-red-50 px-3 py-2 text-xs font-bold text-red-700" @click="deleteFaq(item)">Hapus</button></div>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold">Informasi / legal</h2>
                <div class="mt-4 space-y-4">
                    <article v-for="item in pages" :key="item.key" class="rounded-lg border border-slate-200 p-4">
                        <strong class="text-xs uppercase text-slate-500">{{ item.key }}</strong>
                        <input v-model="item.title" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm">
                        <textarea v-model="item.intro" rows="2" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm"></textarea>
                        <textarea v-model="item.body" rows="7" class="mt-2 w-full rounded border border-slate-200 p-2 text-sm"></textarea>
                        <div class="mt-2 flex gap-3"><label class="flex items-center gap-2 text-xs"><input v-model="item.is_active" type="checkbox"> Aktif</label><button class="rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white" @click="savePage(item)">Simpan</button></div>
                    </article>
                </div>
            </section>
        </div>
    </AdminShell>
</template>
