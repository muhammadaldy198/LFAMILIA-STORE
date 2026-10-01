<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
defineProps({ order: Object, events: Array });
</script>
<template>
<Head :title="'Pesanan ' + order.order_number"/>
<AdminShell>
 <div class="lf-admin-page-heading"><div><h1>{{order.order_number}}</h1><p>Detail pesanan dan riwayat status.</p></div><Link href="/admin/orders" class="lf-admin-button">Daftar Pesanan</Link></div>
 <section class="lf-admin-editor"><h2>Ringkasan</h2><dl class="grid gap-4 sm:grid-cols-2"><div><dt>Produk</dt><dd>{{order.product_name}}</dd></div><div><dt>Nominal</dt><dd>{{order.package_name}}</dd></div><div><dt>Status</dt><dd>{{order.status}}</dd></div><div><dt>Total</dt><dd>Rp{{Number(order.total_idr).toLocaleString('id-ID')}}</dd></div><div><dt>Dibuat</dt><dd>{{order.created_at}}</dd></div><div><dt>Pembayaran diterima</dt><dd>{{order.paid_at || 'Belum diterima'}}</dd></div></dl><h2 class="mt-6">Data Tujuan</h2><dl class="grid gap-3 sm:grid-cols-2"><div v-for="(value,key) in order.customer_input" :key="key"><dt>{{key}}</dt><dd>{{value}}</dd></div></dl></section>
 <section class="lf-admin-editor mt-5"><h2>Riwayat Status</h2><p v-if="!events.length">Belum ada perubahan status.</p><article v-for="event in events" :key="event.id" class="border-b border-slate-200 py-3"><strong>{{event.event_type}}</strong><p>{{event.from_status || 'Baru'}} → {{event.to_status || '—'}} · {{event.created_at}}</p></article></section>
</AdminShell>
</template>
