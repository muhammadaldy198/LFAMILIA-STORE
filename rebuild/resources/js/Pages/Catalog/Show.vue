<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({ product: Object, packages: Array, fields: Array, faviconUrl: String });
</script>

<template>
    <Head :title="product.name"><link v-if="faviconUrl" rel="icon" :href="faviconUrl"></Head>
    <main class="min-h-screen bg-[#090e1b] text-slate-100">
        <header class="border-b border-white/10 bg-[#0c1424] px-5 py-4"><div class="mx-auto flex max-w-6xl items-center justify-between"><Link href="/" class="font-bold text-cyan-300">LFAMILIA STORE</Link><Link href="/orders/check" class="text-sm text-slate-300">Cek pesanan</Link></div></header>
        <div class="mx-auto max-w-6xl space-y-7 px-5 py-8">
            <Link href="/" class="text-sm text-cyan-300">← Katalog</Link>
            <img v-if="product.banner_url" :src="product.banner_url" :alt="'Banner ' + product.name" class="max-h-64 w-full rounded-xl object-contain">
            <div class="flex items-center gap-5">
                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-24 w-24 rounded-xl object-cover">
                <div><p class="text-sm text-cyan-300">{{ product.category_name }}</p><h1 class="text-3xl font-bold">{{ product.name }}</h1><p v-if="product.description" class="mt-2 max-w-2xl text-sm text-slate-400">{{ product.description }}</p></div>
            </div>
            <section v-if="fields.length" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Data yang dibutuhkan</h2>
                <p class="text-sm text-slate-400">Data akan diisi ketika checkout tersedia.</p>
                <ul class="flex flex-wrap gap-2"><li v-for="field in fields" :key="field.field_key" class="rounded-md bg-slate-800 px-3 py-2 text-sm">{{ field.label }}{{ field.is_required ? ' *' : '' }}</li></ul>
            </section>
            <section class="space-y-4">
                <h2 class="text-xl font-semibold">Pilih nominal</h2>
                <p v-if="!packages.length" class="text-slate-400">Belum ada nominal aktif.</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="item in packages" :key="item.id" class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900 p-4">
                        <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="h-12 w-12 rounded-md object-contain">
                        <span class="font-semibold">{{ item.name }}</span>
                    </div>
                </div>
                <p class="text-sm text-slate-400">Harga dan tombol pembayaran akan tampil setelah checkout M6 selesai.</p>
            </section>
        </div>
    </main>
</template>
