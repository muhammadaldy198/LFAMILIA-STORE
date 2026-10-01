<script setup>
import { useCustomerPresentation } from '../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../Components/AccountShell.vue';
import { rupiah } from '../lib/money';

defineProps({ customer: Object, balanceIdr: Number, orderCount: Number, ticketCount: Number });
</script>

<template>
    <Head :title="customerText(&quot;pages.account.attribute.title.682fab7a&quot;, &quot;Akun&quot;)" />
    <AccountShell>
        <div>
            <h1 class="text-3xl font-semibold">Halo, {{ customer.name }}</h1>
            <p class="mt-2 text-slate-400">{{ customer.email }} · {{ customer.membership_tier_code }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <Link href="/account/wallet" class="rounded-xl border border-slate-800 bg-slate-900 p-5 hover:border-cyan-500"><span class="text-sm text-slate-400">{{ customerText("pages.account.32d4363a", "Saldo") }}</span><strong class="mt-2 block text-xl">{{ rupiah(balanceIdr) }}</strong></Link>
            <Link href="/account/orders" class="rounded-xl border border-slate-800 bg-slate-900 p-5 hover:border-cyan-500"><span class="text-sm text-slate-400">{{ customerText("pages.account.517546c3", "Pesanan") }}</span><strong class="mt-2 block text-xl">{{ orderCount }}</strong></Link>
            <Link href="/account/tickets" class="rounded-xl border border-slate-800 bg-slate-900 p-5 hover:border-cyan-500"><span class="text-sm text-slate-400">{{ customerText("pages.account.d26d34be", "Tiket") }}</span><strong class="mt-2 block text-xl">{{ ticketCount }}</strong></Link>
        </div>
        <Link href="/account/profile" class="inline-block rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">{{ customerText("pages.account.31d5aa63", "Kelola profil") }}</Link>
    </AccountShell>
</template>
