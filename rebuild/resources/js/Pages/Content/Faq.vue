<script setup>
import {Head,Link} from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';
defineProps({faqs:Array});
const statuses=[
 ['01','Menunggu Pembayaran','Selesaikan pembayaran sesuai total dan kanal yang dipilih.','amber'],
 ['02','Sedang Diproses','Pembayaran sudah diterima dan pesanan sedang diproses.','blue'],
 ['03','Berhasil','Produk atau layanan sudah berhasil diproses.','green'],
 ['04','Gagal / Refund','Jika transaksi gagal final, tim akan menangani sesuai kebijakan refund.','red'],
];
</script>
<template>
<Head title="Pertanyaan Umum"/>
<CustomerShell hide-support-cta>
<main class="lf-faq-page">
 <section class="lf-faq-hero">
  <div class="lf-container">
   <span class="lf-faq-kicker">PUSAT BANTUAN · FAQ</span>
   <h1>Jawaban cepat untuk transaksi kamu.</h1>
   <p>Cari penjelasan tentang pembayaran, status pesanan, pengiriman produk, dan pengembalian dana. Nomor invoice adalah cara tercepat untuk menelusuri transaksi.</p>
   <div class="lf-faq-actions"><Link href="/orders/check" class="lf-primary">Lacak Pesanan</Link><Link href="/contact" class="lf-secondary">Hubungi Bantuan</Link></div>
  </div>
 </section>

 <section class="lf-container lf-faq-status-section">
  <p class="lf-eyebrow">PANDUAN STATUS</p>
  <h2>Kenali status pesananmu</h2>
  <div class="lf-faq-status-grid">
   <article v-for="s in statuses" :key="s[0]" :class="'tone-'+s[3]"><span>{{s[0]}}</span><h3>{{s[1]}}</h3><p>{{s[2]}}</p></article>
  </div>
 </section>

 <section class="lf-container lf-faq-layout">
  <div>
   <p class="lf-eyebrow">PERTANYAAN POPULER</p><h2>Yang paling sering ditanyakan</h2>
   <div class="lf-faq-list">
    <details v-for="faq in faqs" :key="faq.id" class="lf-faq">
     <summary><span>{{faq.question}}</span><b>+</b></summary><p>{{faq.answer}}</p>
    </details>
    <div v-if="!faqs?.length" class="lf-empty">FAQ sedang diperbarui.</div>
   </div>
  </div>
  <aside class="lf-faq-help">
   <div class="lf-faq-help-icon">?</div><p class="lf-eyebrow">BUTUH BANTUAN CEPAT?</p><h3>Butuh bantuan cepat?</h3><p>Sertakan nomor invoice, data tujuan, dan screenshot kendala agar pemeriksaan lebih cepat. Jangan pernah mengirim password, PIN, atau kode OTP.</p>
   <Link href="/contact" class="lf-primary">Buka kanal bantuan →</Link>

  </aside>
 </section>
</main>
</CustomerShell>
</template>
