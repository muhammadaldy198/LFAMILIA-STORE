<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';

const props = defineProps({ ticket: Object, messages: Array });
const form = useForm({ message: '' });
function reply() {
    if (form.processing || props.ticket.status === 'CLOSED') return;
    form.post('/account/tickets/' + props.ticket.id + '/messages', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Detail tiket" />
    <AccountShell>
        <Link href="/account/tickets" class="text-sm text-cyan-300">← Semua tiket</Link>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div><h1 class="text-3xl font-semibold">Tiket #{{ ticket.id }}</h1><p class="mt-1 text-sm text-slate-400">{{ticket.status}} · {{ticket.created_at}}</p></div>
        </div>

        <section class="mt-5 space-y-3">
            <article class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Pesan awal</div>
                <h2 class="mt-2 text-lg font-semibold">{{ ticket.subject }}</h2>
                <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-300">{{ ticket.message }}</p>
            </article>

            <article v-for="message in messages" :key="message.id" class="rounded-xl border p-4" :class="message.sender_type==='ADMIN' ? 'border-cyan-900/60 bg-cyan-950/20' : 'border-slate-800 bg-slate-900'">
                <div class="flex justify-between gap-3 text-[10px] text-slate-500">
                    <strong>{{message.sender_type==='ADMIN' ? (message.admin_name || 'Tim LFAMILIA') : (message.customer_name || 'Kamu')}}</strong>
                    <span>{{message.created_at}}</span>
                </div>
                <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">{{message.message}}</p>
            </article>
        </section>

        <form v-if="ticket.status !== 'CLOSED'" class="mt-4 rounded-xl border border-slate-800 bg-slate-900 p-4" @submit.prevent="reply">
            <label class="text-sm font-semibold">Balas tiket<textarea v-model="form.message" required maxlength="5000" rows="4" class="mt-2 block w-full rounded-lg border border-slate-700 bg-slate-950 p-3 text-sm" placeholder="Masukkan balasan"></textarea></label>
            <div class="mt-3 flex justify-end"><button :disabled="form.processing" class="lf-primary">Kirim balasan</button></div>
            <p role="alert" v-if="form.errors.message" class="mt-2 text-sm text-red-300">{{form.errors.message}}</p>
        </form>
        <p v-else class="mt-4 rounded-xl border border-white/10 bg-white/[0.03] p-4 text-sm text-white/45">Tiket sudah ditutup dan tidak menerima balasan baru.</p>
    </AccountShell>
</template>
