<script setup>
import { Head, router } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';
defineProps({ accounts: Array });
function remove(id) {
    if (!confirm('Hapus akun game tersimpan ini?')) return;
    router.delete('/account/game-accounts/' + id, { preserveScroll: true });
}
</script>
<template>
<Head title="Akun Game" />
<AccountShell>
    <div><p class="lf-eyebrow">AKUN TERSIMPAN</p><h1 class="lf-account-title">Akun game</h1><p class="lf-account-copy">Akun yang disimpan dari checkout dapat dipakai lagi agar top up berikutnya lebih cepat.</p></div>
    <div v-if="accounts?.length" class="lf-account-list">
        <article v-for="account in accounts" :key="account.id" class="lf-account-card">
            <div class="lf-account-card-head"><div><strong>{{account.label}}</strong><small>{{account.product_name}}<template v-if="account.nickname"> · {{account.nickname}}</template></small></div><button type="button" class="lf-danger-link" @click="remove(account.id)">Hapus</button></div>
            <dl class="lf-account-kv"><div v-for="(value,key) in account.customer_input" :key="key"><dt>{{String(key).replaceAll('_',' ')}}</dt><dd>{{value}}</dd></div></dl>
        </article>
    </div>
    <div v-else class="lf-account-empty">Belum ada akun game tersimpan. Simpan akun dari halaman checkout setelah nickname berhasil diperiksa.</div>
</AccountShell>
</template>
