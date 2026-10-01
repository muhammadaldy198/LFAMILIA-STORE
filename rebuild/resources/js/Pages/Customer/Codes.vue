<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

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
<Head :title="customerText(&quot;pages.customer.codes.attribute.title.ba54136c&quot;, &quot;Kode Digital&quot;)" />
<AccountShell>
    <div><p class="lf-eyebrow">{{ customerText("pages.customer.codes.d0acb5f8", "PRODUK DIGITAL") }}</p><h1 class="lf-account-title">{{ customerText("pages.customer.codes.1fa3a3ba", "Kode digital saya") }}</h1><p class="lf-account-copy">{{ customerText("pages.customer.codes.7388bb65", "Kode hanya tampil dari pesanan berhasil yang memang menghasilkan serial atau voucher.") }}</p></div>
    <div v-if="items?.length" class="lf-account-list">
        <article v-for="item in items" :key="item.id" class="lf-account-card">
            <div class="lf-account-card-head"><div><strong>{{item.product_name}}</strong><small>{{item.package_name}} · {{item.order_number}}</small></div><span>{{ customerText("pages.customer.codes.5eb96a58", "TERKIRIM") }}</span></div>
            <div class="lf-code-box"><code>{{item.code}}</code><button type="button" @click="copy(item)">{{copied===item.id?'Tersalin':'Salin'}}</button></div>
            <p v-if="item.note" class="lf-account-note">{{item.note}}</p>
            <small class="lf-account-muted">{{item.delivered_at}}</small>
        </article>
    </div>
    <div v-else class="lf-account-empty">{{ customerText("pages.customer.codes.ae3c46fd", "Belum ada kode digital yang terkirim.") }}</div>
</AccountShell>
</template>
