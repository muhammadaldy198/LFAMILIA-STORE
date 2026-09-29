<script setup>
import { Link, router } from '@inertiajs/vue3';

const links = [
    { href: '/account', label: 'Ringkasan' },
    { href: '/account/wallet', label: 'Saldo' },
    { href: '/account/orders', label: 'Pesanan' },
    { href: '/account/membership', label: 'Membership' },
    { href: '/account/tickets', label: 'Tiket' },
    { href: '/account/profile', label: 'Profil' },
];
</script>

<template>
    <main class="min-h-screen bg-slate-950 text-slate-100">
        <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 md:grid-cols-[220px_1fr] md:py-12">
            <aside class="space-y-4">
                <Link href="/" class="text-lg font-bold tracking-wide text-cyan-300">LFAMILIA STORE</Link>
                <nav aria-label="Menu akun" class="flex gap-2 overflow-x-auto pb-2 md:flex-col">
                    <Link v-for="link in links" :key="link.href" :href="link.href" class="whitespace-nowrap rounded-md px-3 py-2 text-sm hover:bg-slate-800 focus:outline-cyan-300" :class="$page.url === link.href ? 'bg-slate-800 text-cyan-300' : 'text-slate-300'">{{ link.label }}</Link>
                </nav>
                <button type="button" class="rounded-md px-3 py-2 text-sm text-slate-300 hover:bg-slate-800 focus:outline-cyan-300" @click="router.post('/logout')">Keluar</button>
            </aside>
            <section class="min-w-0 space-y-6">
                <slot />
            </section>
        </div>
    </main>
</template>
