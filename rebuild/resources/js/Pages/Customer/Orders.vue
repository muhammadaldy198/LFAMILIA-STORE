<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
import CustomerPagination from '../../Components/CustomerPagination.vue';
import { rupiah } from '../../lib/money';
import { orderStatusInfo } from '../../lib/orderStatus';

defineProps({ orders: Object });
const statusLabel = status => orderStatusInfo(status).label;
</script>

<template>
    <Head :title="customerText('pages.customer.orders.attribute.title.517546c3', 'Pesanan')" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">{{ customerText("pages.customer.orders.517546c3", "Pesanan") }}</h1>
        <p v-if="!orders.data.length" class="text-slate-400">{{ customerText("pages.customer.orders.d195c58c", "Belum ada pesanan.") }}</p>
        <div v-for="order in orders.data" :key="order.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
            <Link :href="'/account/orders/' + order.id" class="font-semibold text-cyan-300 hover:underline">{{ order.order_number }}</Link>
            <p class="mt-1">{{ order.product_name }} · {{ rupiah(order.total_idr) }}</p>
            <p class="mt-1 text-sm text-slate-400">{{ statusLabel(order.status) }} · {{ order.created_at }}</p>
        </div>
        <CustomerPagination :links="orders.links || []" :label="customerText('pages.customer.orders.attribute.aria-label.5819e517', 'Halaman pesanan')" />
    </AccountShell>
</template>
