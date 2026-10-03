<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
import { rupiah } from '../../lib/money';

defineProps({ orders: Object });
</script>

<template>
    <Head :title="customerText(&quot;pages.customer.orders.attribute.title.517546c3&quot;, &quot;Pesanan&quot;)" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">{{ customerText("pages.customer.orders.517546c3", "Pesanan") }}</h1>
        <p v-if="!orders.data.length" class="text-slate-400">{{ customerText("pages.customer.orders.d195c58c", "Belum ada pesanan.") }}</p>
        <div v-for="order in orders.data" :key="order.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
            <Link :href="'/account/orders/' + order.id" class="font-semibold text-cyan-300 hover:underline">{{ order.order_number }}</Link>
            <p class="mt-1">{{ order.product_name }} · {{ rupiah(order.total_idr) }}</p>
            <p class="mt-1 text-sm text-slate-400">{{ order.status }} · {{ order.created_at }}</p>
        </div>
        <nav :aria-label="customerText(&quot;pages.customer.orders.attribute.aria-label.5819e517&quot;, &quot;Halaman pesanan&quot;)" class="flex flex-wrap gap-2"><Link v-for="link in orders.links" :key="link.label" :href="link.url || '#'" class="rounded-md px-3 py-2 text-sm" :class="link.active ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800 text-slate-200'" :aria-disabled="!link.url"><span v-html="link.label" /></Link></nav>
    </AccountShell>
</template>
