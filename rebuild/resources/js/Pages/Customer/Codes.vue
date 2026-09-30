<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';

defineProps({ items: Array });
const copied = ref(null);
async function copy(item) {
    await navigator.clipboard.writeText(item.code);
    copied.value = item.id;
    window.setTimeout(() => { if (copied.value === item.id) copied.value = null; }, 1600);
}
</script>
<template>
<Head title="Kode Digital" />
<AccountShell>
    <div><p class="lf-eyebrow">PRODUK DIGITAL</p><h1 class="lf-account-title">Kode digital saya</h1><p class="lf-account-copy">Kode hanya tampil dari pesanan berhasil yang memang menghasilkan serial atau voucher.</p></div>
    <div v-if="items?.length" class="lf-account-list">
        <article v-for="item in items" :key="item.id" class="lf-account-card">
            <div class="lf-account-card-head"><div><strong>{{item.product_name}}</strong><small>{{item.package_name}} · {{item.order_number}}</small></div><span>TERKIRIM</span></div>
            <div class="lf-code-box"><code>{{item.code}}</code><button type="button" @click="copy(item)">{{copied===item.id?'Tersalin':'Salin'}}</button></div>
            <p v-if="item.note" class="lf-account-note">{{item.note}}</p>
            <small class="lf-account-muted">{{item.delivered_at}}</small>
        </article>
    </div>
    <div v-else class="lf-account-empty">Belum ada kode digital yang terkirim.</div>
</AccountShell>
</template>
