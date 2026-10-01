<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
defineProps({ items: Array });
const when=v=>v?new Date(v).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}):'-';
</script>
<template>
<Head :title="customerText(&quot;pages.customer.notifications.attribute.title.b4d63cec&quot;, &quot;Notifikasi&quot;)" />
<AccountShell>
    <div><p class="lf-eyebrow">{{ customerText("pages.customer.notifications.66f6ee7d", "AKTIVITAS") }}</p><h1 class="lf-account-title">{{ customerText("pages.customer.notifications.bd0ef076", "Notifikasi transaksi") }}</h1><p class="lf-account-copy">{{ customerText("pages.customer.notifications.f52771b3", "Ringkasan perubahan pesanan dan top up saldo terbaru dari akunmu.") }}</p></div>
    <div v-if="items?.length" class="lf-account-list">
        <article v-for="item in items" :key="item.key" class="lf-account-card lf-notification-card">
            <span class="lf-notification-dot" :class="item.type.toLowerCase()"></span>
            <div><strong>{{item.title}}</strong><p>{{item.detail}}</p><small>{{when(item.at)}}</small></div>
        </article>
    </div>
    <div v-else class="lf-account-empty">{{ customerText("pages.customer.notifications.cdb11299", "Belum ada notifikasi transaksi.") }}</div>
</AccountShell>
</template>
