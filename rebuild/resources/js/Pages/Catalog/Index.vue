<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ categories: Array, products: Object, filters: Object, logoUrl: String, bannerUrl: String, bannerTarget: String });
const search = ref(props.filters.q ?? '');
const query = (changes = {}) => {
    const params = { ...props.filters, ...changes };
    Object.keys(params).forEach((key) => { if (!params[key]) delete params[key]; });
    return '/?' + new URLSearchParams(params).toString();
};
const submit = () => router.get('/', { ...props.filters, q: search.value }, { preserveState: true });
</script>

<template>
    <Head title="Katalog" />
    <main class="min-h-screen bg-[#090e1b] text-slate-100">
        <header class="border-b border-white/10 bg-[#0c1424]">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4">
                <Link href="/" class="flex items-center gap-3 text-xl font-bold tracking-wide text-cyan-300">
                    <img v-if="logoUrl" :src="logoUrl" alt="" class="h-9 w-9 object-contain"> LFAMILIA STORE
                </Link>
                <nav aria-label="Navigasi utama" class="flex flex-wrap items-center gap-5 text-sm text-slate-300">
                    <Link href="/">Top up</Link><Link href="/orders/check">Cek pesanan</Link><Link href="/account">Akun</Link><Link href="/login">Masuk</Link>
                </nav>
            </div>
        </header>
        <div class="mx-auto max-w-7xl space-y-9 px-5 py-8">
            <a v-if="bannerUrl && bannerTarget" :href="bannerTarget" class="block"><img :src="bannerUrl" alt="Banner LFAMILIA STORE" class="max-h-72 w-full rounded-xl object-contain"></a>
            <img v-else-if="bannerUrl" :src="bannerUrl" alt="Banner LFAMILIA STORE" class="max-h-72 w-full rounded-xl object-contain">
            <section class="space-y-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[.25em] text-cyan-300">LFAMILIA STORE</p>
                    <h1 class="mt-2 text-3xl font-bold md:text-4xl">Jelajahi produk digital</h1>
                    <p class="mt-2 text-sm text-slate-400">Pilih kategori dan produk. Checkout akan tersedia setelah pengembangan tahap berikutnya.</p>
                </div>
                <form class="flex max-w-lg gap-2" @submit.prevent="submit">
                    <label class="sr-only" for="catalog-search">Cari produk</label>
                    <input id="catalog-search" v-model="search" maxlength="80" placeholder="Cari produk" class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-900 px-4 py-3 focus:outline-cyan-300">
                    <button class="rounded-lg bg-cyan-400 px-5 font-semibold text-slate-950">Cari</button>
                </form>
                <nav aria-label="Filter katalog" class="flex gap-2 overflow-x-auto pb-2 text-sm">
                    <Link :href="query({ category: '', mode: '' })" class="whitespace-nowrap rounded-full px-4 py-2" :class="!filters.category && !filters.mode ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800'">Semua</Link>
                    <Link :href="query({ category: '', mode: 'manual' })" class="whitespace-nowrap rounded-full px-4 py-2" :class="filters.mode === 'manual' ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800'">Produk Manual</Link>
                    <Link v-for="category in categories" :key="category.slug" :href="query({ category: category.slug, mode: '' })" class="whitespace-nowrap rounded-full px-4 py-2" :class="filters.category === category.slug ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800'">{{ category.name }}</Link>
                </nav>
            </section>
            <section>
                <h2 class="mb-4 text-xl font-semibold">Produk</h2>
                <p v-if="!products.data.length" class="rounded-lg border border-slate-800 p-8 text-slate-400">Belum ada produk aktif di pilihan ini.</p>
                <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Link v-for="product in products.data" :key="product.slug" :href="'/catalog/' + product.slug" class="group overflow-hidden rounded-xl border border-white/10 bg-slate-900 transition hover:border-cyan-400">
                        <div class="aspect-[4/3] bg-slate-800"><img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover"></div>
                        <div class="p-3"><p class="text-xs text-cyan-300">{{ product.category_name }}</p><h3 class="mt-1 font-semibold group-hover:text-cyan-300">{{ product.name }}</h3></div>
                    </Link>
                </div>
                <nav aria-label="Halaman katalog" class="mt-6 flex gap-2"><Link v-for="link in products.links" :key="link.label" :href="link.url || '#'" class="rounded-md px-3 py-2 text-sm" :class="link.active ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800'" v-html="link.label" /></nav>
            </section>
        </div>
    </main>
</template>
