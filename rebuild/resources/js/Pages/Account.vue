<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AccountShell from '../Components/AccountShell.vue';
import { rupiah } from '../lib/money';

defineProps({ customer: Object, balanceIdr: Number, orderCount: Number, ticketCount: Number });
</script>

<template>
    <Head title="Akun" />
    <AccountShell>
        <div>
            <h1 class="text-3xl font-semibold">Halo, {{ customer.name }}</h1>
            <p class="mt-2 text-slate-400">{{ customer.email }} · {{ customer.membership_tier_code }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <Link href="/account/wallet" class="rounded-xl border border-slate-800 bg-slate-900 p-5 hover:border-cyan-500"><span class="text-sm text-slate-400">Saldo</span><strong class="mt-2 block text-xl">{{ rupiah(balanceIdr) }}</strong></Link>
            <Link href="/account/orders" class="rounded-xl border border-slate-800 bg-slate-900 p-5 hover:border-cyan-500"><span class="text-sm text-slate-400">Pesanan</span><strong class="mt-2 block text-xl">{{ orderCount }}</strong></Link>
            <Link href="/account/tickets" class="rounded-xl border border-slate-800 bg-slate-900 p-5 hover:border-cyan-500"><span class="text-sm text-slate-400">Tiket</span><strong class="mt-2 block text-xl">{{ ticketCount }}</strong></Link>
        </div>
        <Link href="/account/profile" class="inline-block rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950">Kelola profil</Link>
    </AccountShell>
</template>
