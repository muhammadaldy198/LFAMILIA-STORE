<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import {Head,Link} from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';
defineProps({faqs:Array});
const statuses=[
 ['01','Menunggu Pembayaran','Selesaikan pembayaran sesuai total dan kanal yang dipilih.','amber'],
 ['02','Sedang Diproses','Pembayaran sudah diterima dan pesanan sedang diproses.','blue'],
 ['03','Berhasil','Produk atau layanan sudah berhasil diproses.','green'],
 ['05','Kedaluwarsa','Waktu pembayaran berakhir. Periksa invoice sebelum membuat pesanan baru.','amber'],
 ['06','Dibatalkan','Pesanan dibatalkan. Jika sudah membayar, hubungi bantuan dengan nomor invoice.','red'],
 ['04','Gagal / Refund','Jika transaksi gagal final, tim akan menangani sesuai kebijakan refund.','red'],
];
</script>
<template>
<Head :title="customerText('pages.content.faq.attribute.title.4c104caa', 'Pertanyaan Umum')"/>
<CustomerShell>
<main class="lf-faq-page">
 <section class="lf-faq-hero">
  <div class="lf-container">
   <span class="lf-faq-kicker">{{ customerText("pages.content.faq.5db3886", "PUSAT BANTUAN LFAMILIA") }}</span>
   <h1>{{ customerText("pages.content.faq.8e1b9d5c", "Ada yang bisa kami bantu?") }}</h1>
   <p>{{ customerText("pages.content.faq.d117983f", "Temukan jawaban untuk pembayaran, proses top up, status pesanan, promo, akun, dan layanan LFAMILIA.") }}</p>
   <div class="lf-faq-actions"><Link href="/orders/check" class="lf-primary">{{ customerText("pages.content.faq.196596ce", "Cek Pesanan") }}</Link><Link href="/contact" class="lf-secondary">{{ customerText("pages.content.faq.8e1cc50e", "Hubungi Bantuan") }}</Link></div>
  </div>
 </section>

 <section class="lf-container lf-faq-status-section">
  <p class="lf-eyebrow">{{ customerText("pages.content.faq.c6b71ed6", "PANDUAN STATUS") }}</p>
  <h2>{{ customerText("pages.content.faq.28854bad", "Kenali status transaksi") }}</h2>
  <div class="lf-faq-status-grid">
   <article v-for="s in statuses" :key="s[0]" :class="'tone-'+s[3]"><span>{{s[0]}}</span><h3>{{customerText('faq.status.' + s[0] + '.title',s[1])}}</h3><p>{{customerText('faq.status.' + s[0] + '.body',s[2])}}</p></article>
  </div>
 </section>

 <section class="lf-container lf-faq-layout">
  <div class="lf-faq-main">
   <header class="lf-faq-list-heading"><p class="lf-eyebrow">{{ customerText("pages.content.faq.473cf76a", "PERTANYAAN UMUM") }}</p><h2>{{ customerText("pages.content.faq.bd857c3e", "Jawaban yang sering dicari") }}</h2></header>
   <div class="lf-faq-list">
    <details v-for="faq in faqs" :key="faq.id" class="lf-faq">
     <summary><span>{{faq.question}}</span><b>+</b></summary><p>{{faq.answer}}</p>
    </details>
    <div v-if="!faqs?.length" class="lf-empty">{{ customerText("pages.content.faq.af43d368", "FAQ sedang diperbarui.") }}</div>
   </div>
  </div>
  <aside class="lf-faq-help">
   <div class="lf-faq-help-icon">?</div><p class="lf-eyebrow">{{ customerText("pages.content.faq.36453e67", "MASIH BUTUH BANTUAN?") }}</p><h3>{{ customerText("pages.content.faq.1bf22330", "Tim LFAMILIA siap membantu.") }}</h3><p>{{ customerText("pages.content.faq.6556ac47", "Sertakan nomor invoice saat menghubungi tim agar pengecekan transaksi lebih cepat.") }}</p>
   <Link href="/contact" class="lf-primary">{{ customerText("pages.content.faq.a8b3bfd3", "Hubungi Kami") }}</Link>
   <Link href="/orders/check" class="lf-secondary">{{ customerText("pages.content.faq.b64e6fbf", "Lacak Pesanan") }}</Link>
  </aside>
 </section>
</main>
</CustomerShell>
</template>
