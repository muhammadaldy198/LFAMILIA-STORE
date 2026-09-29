<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Sedang dibangun" />
    <main class="min-h-screen bg-slate-950 px-6 py-20 text-slate-100">
        <div class="mx-auto max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-widest text-cyan-300">LFAMILIA STORE</p>
            <h1 class="mt-6 text-4xl font-bold tracking-tight">Website sedang dibangun</h1>
            <p class="mt-4 max-w-xl text-lg text-slate-300">
                Fondasi aplikasi baru sedang disiapkan. Katalog dan checkout akan tersedia setelah pengujian selesai.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <Link href="/login" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Masuk akun</Link>
                <Link href="/orders/check" class="rounded-md border border-slate-700 px-4 py-2 text-slate-100">Cek pesanan guest</Link>
            </div>
        </div>
    </main>
</template>
