<script setup>
import {Link,router} from '@inertiajs/vue3';import CustomerShell from './CustomerShell.vue';
const links=[
 {href:'/account',label:'Ringkasan'},
 {href:'/account/wallet',label:'Saldo'},
 {href:'/account/orders',label:'Pesanan'},
 {href:'/account/codes',label:'Kode'},
 {href:'/account/game-accounts',label:'Akun Game'},
 {href:'/account/notifications',label:'Notifikasi'},
 {href:'/account/membership',label:'Membership'},
 {href:'/account/tickets',label:'Tiket'},
 {href:'/account/profile',label:'Profil'},
];
</script>
<template><CustomerShell><main class="lf-container lf-account"><aside class="lf-account-side"><div class="lf-brand"><span class="lf-brand-mark">LF</span><span><strong class="lf-brand-name">AKUN LFAMILIA</strong><span class="lf-brand-sub">CUSTOMER</span></span></div><nav class="lf-account-nav"><Link v-for="x in links" :key="x.href" :href="x.href" :class="{active:$page.url===x.href}">{{x.label}}</Link><button type="button" @click="router.post('/logout')">Keluar</button></nav></aside><section class="lf-account-main space-y-4"><slot/></section></main></CustomerShell></template>