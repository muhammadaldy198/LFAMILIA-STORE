<script setup>
import { reactive } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useCustomerPresentation } from '../Composables/customerPresentation';
import SupportMessageActions from './SupportMessageActions.vue';
const { customerText } = useCustomerPresentation();
const page = usePage();
const form = reactive({ kind: 'Kendala transaksi', name: page.props.auth?.user?.name || '', phone: page.props.auth?.user?.phone || '', invoice: '', message: '' });
const kinds = ['Kendala transaksi', 'Pertanyaan produk', 'Pengembalian dana', 'Bantuan akun', 'Lainnya'];
</script>
<template>
 <section class="lf-contact-assistance">
  <p class="lf-eyebrow">{{ customerText('support.formLabel', 'FORMULIR BANTUAN') }}</p>
  <h2>{{ customerText('support.formTitle', 'Ceritakan kendalamu') }}</h2>
  <p class="lf-copy">{{ customerText('support.formIntro', 'Formulir ini akan menyiapkan pesan lengkap lalu membuka kanal bantuan resmi yang tersedia.') }}</p>
  <form @submit.prevent>
   <label>{{ customerText('support.field.kind', 'Jenis bantuan') }}<select v-model="form.kind"><option v-for="kind in kinds" :key="kind" :value="kind">{{ customerText('support.kind.' + kind, kind) }}</option></select></label>
   <label>{{ customerText('support.field.name', 'Nama') }}<input v-model="form.name" required maxlength="100" autocomplete="name" :placeholder="customerText('support.namePlaceholder', 'Nama kamu')"></label>
   <label>{{ customerText('support.field.phone', 'Nomor WhatsApp') }}<input v-model="form.phone" required type="tel" inputmode="tel" autocomplete="tel" maxlength="25" :placeholder="customerText('support.phonePlaceholder', '08xxxxxxxxxx')"></label>
   <label>{{ customerText('support.invoiceOptional', 'Nomor invoice (opsional)') }}<input v-model="form.invoice" maxlength="80" :placeholder="customerText('support.invoicePlaceholder', 'Masukkan nomor invoice')"></label>
   <label>{{ customerText('support.field.message', 'Pesan') }}<textarea v-model="form.message" required maxlength="5000" rows="5" :placeholder="customerText('support.messagePlaceholder', 'Jelaskan pertanyaan atau masalah...')"></textarea></label>
   <SupportMessageActions :fields="form" validate-form />
  </form>
  <p class="lf-copy lf-contact-assistance-note">{{ customerText('support.privacyNote', 'Jangan cantumkan password, PIN, kode OTP, atau data kartu pembayaran. Informasi yang kamu isi hanya disusun di perangkatmu sampai kamu memilih untuk mengirimnya melalui WhatsApp.') }}</p>
 </section>
</template>
<style scoped>
.lf-contact-assistance{margin-top:28px;padding:20px;border:1px solid #233044;border-radius:8px;background:#0b111b;max-width:850px}
.lf-contact-assistance h2{margin:8px 0 16px}
.lf-contact-assistance form{display:grid;gap:16px;margin-top:22px}
.lf-contact-assistance label{display:grid;gap:8px;font-weight:600;min-width:0}
.lf-contact-assistance input,.lf-contact-assistance select,.lf-contact-assistance textarea{width:100%;min-width:0}
.lf-contact-assistance-note{margin-top:18px}
@media(max-width:640px){.lf-contact-assistance{padding:16px}}
</style>
