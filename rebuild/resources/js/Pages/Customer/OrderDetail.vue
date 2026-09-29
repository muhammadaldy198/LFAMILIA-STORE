<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
import { rupiah } from '../../lib/money';

defineProps({ order: Object });
</script>

<template>
    <Head title="Detail pesanan" />
    <AccountShell>
        <Link href="/account/orders" class="text-sm text-cyan-300">← Semua pesanan</Link>
        <h1 class="text-3xl font-semibold">Pesanan {{ order.order_number }}</h1>
        <dl class="grid gap-4 rounded-xl border border-slate-800 bg-slate-900 p-5 sm:grid-cols-2">
            <div><dt class="text-sm text-slate-400">Produk</dt><dd>{{ order.product_name }} · {{ order.package_name }}</dd></div>
            <div><dt class="text-sm text-slate-400">Status</dt><dd>{{ order.status }}</dd></div>
            <div><dt class="text-sm text-slate-400">Total</dt><dd>{{ rupiah(order.total_idr) }}</dd></div>
            <div><dt class="text-sm text-slate-400">Dibuat</dt><dd>{{ order.created_at }}</dd></div>
            <div v-if="order.paid_at"><dt class="text-sm text-slate-400">Dibayar</dt><dd>{{ order.paid_at }}</dd></div>
        </dl>
        <Link href="/account/tickets" class="text-cyan-300">Butuh bantuan? Buat tiket</Link>
    </AccountShell>
</template>
