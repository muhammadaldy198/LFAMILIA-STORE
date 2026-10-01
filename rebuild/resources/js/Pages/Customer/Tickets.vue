<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';

defineProps({ tickets: Object, orders: Array });
const form = useForm({ subject: '', message: '', order_id: '' });
const submit = () => { if (!form.processing) form.post('/account/tickets', { onSuccess: () => form.reset() }); };
</script>

<template>
    <Head title="Tiket bantuan" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">Tiket bantuan</h1>
        <form class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5" @submit.prevent="submit">
            <h2 class="text-lg font-semibold">Buat tiket</h2>
            <label class="block">Subjek
                <input v-model="form.subject" required maxlength="150" class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="form.errors.subject" class="text-sm text-red-300">{{ form.errors.subject }}</span>
            </label>
            <label class="block">Pesanan (opsional)
                <select v-model="form.order_id" class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                    <option value="">Tanpa pesanan</option>
                    <option v-for="order in orders" :key="order.id" :value="order.id">{{ order.order_number }}</option>
                </select>
                <span v-if="form.errors.order_id" class="text-sm text-red-300">{{ form.errors.order_id }}</span>
            </label>
            <label class="block">Pesan
                <textarea v-model="form.message" required maxlength="5000" rows="5" class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300" />
                <span v-if="form.errors.message" class="text-sm text-red-300">{{ form.errors.message }}</span>
            </label>
            <button :disabled="form.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50">Kirim tiket</button>
        </form>
        <h2 class="text-xl font-semibold">Riwayat tiket</h2>
        <p v-if="!tickets.data.length" class="text-slate-400">Belum ada tiket.</p>
        <div v-for="ticket in tickets.data" :key="ticket.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
            <Link :href="'/account/tickets/' + ticket.id" class="font-semibold text-cyan-300 hover:underline">#{{ ticket.id }} · {{ ticket.subject }}</Link>
            <p class="mt-1 text-sm text-slate-400">{{ ticket.status }} · {{ ticket.created_at }}</p>
        </div>
        <nav aria-label="Halaman tiket" class="flex flex-wrap gap-2"><Link v-for="link in tickets.links" :key="link.label" :href="link.url || '#'" class="rounded-md px-3 py-2 text-sm" :class="link.active ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800 text-slate-200'" :aria-disabled="!link.url" v-html="link.label" /></nav>
    </AccountShell>
</template>
