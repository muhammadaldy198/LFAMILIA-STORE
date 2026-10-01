<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';
const props = defineProps({ order: Object, tickets: Array });
const form = useForm({ order_number: props.order?.order_number || '', access_code: '', subject: '', message: '' });
const replies = {};
const replyForm = id => replies[id] ||= useForm({ message: '' });
const submit = () => { if (!form.processing) form.post('/support', { onSuccess: () => form.reset('subject', 'message', 'access_code') }); };
const verify = () => { if (!form.processing) form.post('/support/verify', { onSuccess: () => form.reset('access_code') }); };
const reply = id => { const f = replyForm(id); if (!f.processing) f.post('/support/' + id + '/messages', { onSuccess: () => f.reset() }); };
</script>
<template>
<Head title="Bantuan Guest" />
<CustomerShell>
<main class="lf-container lf-content-page">
 <p class="lf-eyebrow">PUSAT BANTUAN</p><h1>Bantuan tanpa akun</h1>
 <p class="lf-copy">Buat tiket atau baca balasan untuk pesanan guest. Gunakan nomor invoice dan kode akses yang diberikan saat checkout.</p>
 <form class="lf-guest-support-form" @submit.prevent="submit">
  <h2>Formulir bantuan</h2>
  <label>Nomor invoice<input v-model="form.order_number" required maxlength="80" autocomplete="off"></label>
  <label v-if="!order">Kode akses pesanan<input v-model="form.access_code" required minlength="64" maxlength="64" autocomplete="off" type="password"></label>
  <p v-if="!order" class="lf-copy">Kode akses menjaga percakapan tetap pribadi. Jika kode hilang, hubungi tim melalui kanal resmi di halaman Hubungi Kami.</p>
  <p v-for="(error, field) in form.errors" :key="field" role="alert" class="text-red-300">{{ error }}</p>
  <button class="lf-secondary" type="button" :disabled="form.processing" @click="verify">Lihat tiket dan balasan</button>
  <label>Subjek<input v-model="form.subject" required maxlength="150"></label>
  <label>Pesan<textarea v-model="form.message" required maxlength="5000" rows="5"></textarea></label>
  <button class="lf-primary" :disabled="form.processing">Kirim tiket</button>
 </form>
 <section v-if="order" class="lf-guest-support-history">
  <h2>Tiket untuk {{ order.order_number }}</h2>
  <p v-if="!tickets.length">Belum ada tiket untuk pesanan ini.</p>
  <article v-for="ticket in tickets" :key="ticket.id" class="lf-guest-support-form">
   <h3>#{{ ticket.id }} · {{ ticket.subject }}</h3><p>Status: {{ ticket.status }}</p>
   <p class="whitespace-pre-wrap">{{ ticket.message }}</p>
   <div v-for="message in ticket.messages" :key="message.id" class="lf-guest-support-message">
    <strong>{{ message.sender_type === 'ADMIN' ? 'Tim LFAMILIA' : 'Kamu' }}</strong>
    <p class="whitespace-pre-wrap">{{ message.message }}</p>
   </div>
   <form v-if="ticket.status !== 'CLOSED'" @submit.prevent="reply(ticket.id)">
    <label>Balasan<textarea v-model="replyForm(ticket.id).message" required maxlength="5000" rows="3"></textarea></label>
    <p v-for="error in replyForm(ticket.id).errors" :key="error" role="alert" class="text-red-300">{{ error }}</p>
    <button class="lf-primary" :disabled="replyForm(ticket.id).processing">Kirim balasan</button>
   </form>
  </article>
 </section>
 <Link href="/contact" class="lf-secondary">Hubungi Kami</Link>
</main>
</CustomerShell>
</template>
