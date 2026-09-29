<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ admin: Object });
</script>

<template>
    <Head title="Panel Admin" />
    <main class="min-h-screen bg-slate-950 px-6 py-16 text-slate-100">
        <div class="mx-auto max-w-2xl space-y-5">
            <h1 class="text-3xl font-semibold">Panel Admin</h1>
            <p>{{ admin.name }} · {{ admin.role }}</p>
            <p>Fitur operasional panel lainnya dibangun pada M9.</p>
            <div class="flex flex-wrap gap-2">
                <Link v-if="admin.role === 'SUPER_ADMIN'" href="/admin/catalog" class="inline-block rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Kelola katalog</Link>
                <Link href="/admin/payments" class="inline-block rounded-md bg-slate-700 px-4 py-2 font-semibold">Pembayaran</Link>
            </div>
            <button type="button" class="rounded-md bg-slate-700 p-3 focus:outline-cyan-300" @click="router.post('/admin/logout')">Keluar</button>
        </div>
    </main>
</template>
