<script setup>
import { useCustomerPresentation } from '../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import {ref,watch} from 'vue';
import {Link,router,usePage} from '@inertiajs/vue3';
import CustomerShell from './CustomerShell.vue';
const open=ref(false);
const page=usePage();
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
watch(()=>page.url,()=>{open.value=false});
</script>
<template>
 <CustomerShell>
  <main class="lf-container lf-account">
   <button type="button" class="lf-account-menu-toggle" :aria-expanded="open" aria-controls="customer-account-menu" @click="open=true">{{ customerText("components.accountshell.7453c709", "☰ Menu Akun") }}</button>
   <div v-if="open" class="lf-account-menu-backdrop" @click="open=false"></div>
   <aside id="customer-account-menu" class="lf-account-side" :class="{'is-open':open}" @keydown.esc="open=false">
    <div class="lf-account-menu-heading">
     <div class="lf-brand"><span class="lf-brand-mark">{{ customerText("components.accountshell.6ae24f17", "LF") }}</span><span><strong class="lf-brand-name">{{ customerText("components.accountshell.2ea48fb9", "AKUN LFAMILIA") }}</strong><span class="lf-brand-sub">{{ customerText("components.accountshell.d03c4dd9", "CUSTOMER") }}</span></span></div>
     <button type="button" class="lf-account-menu-close" :aria-label="customerText(&quot;components.accountshell.attribute.aria-label.8ab7199&quot;, &quot;Tutup menu akun&quot;)" @click="open=false">{{ customerText("components.accountshell.520b4356", "×") }}</button>
    </div>
    <nav class="lf-account-nav">
     <Link v-for="x in links" :key="x.href" :href="x.href" :class="{active:$page.url.split('?')[0]===x.href}" @click="open=false">{{ customerText('navigation.account.' + x.href, x.label) }}</Link>
     <button type="button" class="lf-account-logout" @click="router.post('/logout')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H4v16h5M14 8l4 4-4 4M8 12h10"/></svg><span>{{ customerText("components.accountshell.65c9b771", "Keluar") }}</span></button>
    </nav>
   </aside>
   <section class="lf-account-main space-y-4"><slot/></section>
  </main>
 </CustomerShell>
</template>
