<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { rupiah } from '../../lib/money';

defineProps({ order: Object });
</script>

<template>
    <Head title="Status pesanan" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <div class="mx-auto max-w-xl space-y-5">
            <Link href="/" class="text-cyan-300">← LFAMILIA STORE</Link>
            <h1 class="text-3xl font-semibold">Pesanan {{ order.order_number }}</h1>
            <dl class="grid gap-4 rounded-xl border border-slate-800 bg-slate-900 p-5 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-400">Produk</dt><dd>{{ order.product_name }}</dd></div>
                <div><dt class="text-sm text-slate-400">Status</dt><dd>{{ order.status }}</dd></div>
                <div><dt class="text-sm text-slate-400">Total</dt><dd>{{ rupiah(order.total_idr) }}</dd></div>
                <div><dt class="text-sm text-slate-400">Dibuat</dt><dd>{{ order.created_at }}</dd></div>
            </dl>
        </div>
    </main>
</template>
