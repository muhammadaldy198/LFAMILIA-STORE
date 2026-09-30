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
<CustomerShell>
<main class="lf-faq-page">
 <section class="lf-faq-hero">
  <div class="lf-container">
   <span class="lf-faq-kicker">PUSAT BANTUAN LFAMILIA</span>
   <h1>Ada yang bisa kami bantu?</h1>
   <p>Temukan jawaban untuk pembayaran, proses top up, status pesanan, promo, akun, dan layanan LFAMILIA.</p>
   <div class="lf-faq-actions"><Link href="/orders/check" class="lf-primary">Cek Pesanan</Link><Link href="/contact" class="lf-secondary">Hubungi Bantuan</Link></div>
  </div>
 </section>

 <section class="lf-container lf-faq-status-section">
  <p class="lf-eyebrow">PANDUAN STATUS</p>
  <h2>Kenali status transaksi</h2>
  <div class="lf-faq-status-grid">
   <article v-for="s in statuses" :key="s[0]" :class="'tone-'+s[3]"><span>{{s[0]}}</span><h3>{{s[1]}}</h3><p>{{s[2]}}</p></article>
  </div>
 </section>

 <section class="lf-container lf-faq-layout">
  <div>
   <p class="lf-eyebrow">PERTANYAAN UMUM</p><h2>Jawaban yang sering dicari</h2>
   <div class="lf-faq-list">
    <details v-for="faq in faqs" :key="faq.id" class="lf-faq">
     <summary><span>{{faq.question}}</span><b>+</b></summary><p>{{faq.answer}}</p>
    </details>
    <div v-if="!faqs?.length" class="lf-empty">FAQ sedang diperbarui.</div>
   </div>
  </div>
  <aside class="lf-faq-help">
   <div class="lf-faq-help-icon">?</div><p class="lf-eyebrow">MASIH BUTUH BANTUAN?</p><h3>Tim LFAMILIA siap membantu.</h3><p>Sertakan nomor invoice saat menghubungi tim agar pengecekan transaksi lebih cepat.</p>
   <Link href="/contact" class="lf-primary">Hubungi Kami</Link>
   <Link href="/orders/check" class="lf-secondary">Lacak Pesanan</Link>
  </aside>
 </section>
</main>
</CustomerShell>
</template>
