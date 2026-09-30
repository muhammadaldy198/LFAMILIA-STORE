<script setup>
import { Head } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
defineProps({ items: Array });
const when=v=>v?new Date(v).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}):'-';
</script>
<template>
<Head title="Notifikasi" />
<AccountShell>
    <div><p class="lf-eyebrow">AKTIVITAS</p><h1 class="lf-account-title">Notifikasi transaksi</h1><p class="lf-account-copy">Ringkasan perubahan pesanan dan top up saldo terbaru dari akunmu.</p></div>
    <div v-if="items?.length" class="lf-account-list">
        <article v-for="item in items" :key="item.key" class="lf-account-card lf-notification-card">
            <span class="lf-notification-dot" :class="item.type.toLowerCase()"></span>
            <div><strong>{{item.title}}</strong><p>{{item.detail}}</p><small>{{when(item.at)}}</small></div>
        </article>
    </div>
    <div v-else class="lf-account-empty">Belum ada notifikasi transaksi.</div>
</AccountShell>
</template>
