<script setup>

import { Card } from '../../Components/ui/card';
import { Table,TableHeader,TableBody,TableRow,TableHead,TableCell } from '../../Components/ui/table';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
defineProps({ order: Object, events: Array, customer:Object, payments:Array, attempts:Array });
const page=usePage();
</script>
<template>
<Head :title="'Pesanan ' + order.order_number"/>
<AdminShell>
 <div class="lf-admin-page-heading"><div><h1>{{order.order_number}}</h1><p>Detail pesanan dan riwayat status.</p></div><Link href="/admin/orders" class="lf-admin-button">Daftar Pesanan</Link></div>
 <section class="lf-admin-editor"><h2>Ringkasan</h2><dl class="grid gap-4 sm:grid-cols-2"><div><dt>Produk</dt><dd>{{order.product_name}}</dd></div><div><dt>Nominal</dt><dd>{{order.package_name}}</dd></div><div><dt>Status</dt><dd>{{order.status}}</dd></div><div><dt>Total</dt><dd>Rp{{Number(order.total_idr).toLocaleString('id-ID')}}</dd></div><div><dt>Dibuat</dt><dd>{{order.created_at}}</dd></div><div><dt>Pembayaran diterima</dt><dd>{{order.paid_at || 'Belum diterima'}}</dd></div></dl><h2 class="mt-6">Data Tujuan</h2><dl class="grid gap-3 sm:grid-cols-2"><div v-for="(value,key) in order.customer_input" :key="key"><dt>{{key}}</dt><dd>{{value}}</dd></div></dl></section>
 <Card class="mt-5 space-y-3 p-4"><h2>Kontak pelanggan</h2><Link v-if="customer" :href="'/admin/customers/'+customer.id" class="text-blue-600">{{customer.name}}</Link><p>{{customer?.email || order.guest_email || '-'}} · {{customer?.phone || '-'}}</p></Card>
 <Card class="mt-5 p-4"><h2>Pembayaran</h2><Table><TableHeader><TableRow><TableHead>Status</TableHead><TableHead>Nominal</TableHead><TableHead>Dibuat</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="payment in payments" :key="payment.id"><TableCell>{{payment.status}}</TableCell><TableCell>Rp{{Number(payment.amount_idr).toLocaleString('id-ID')}}</TableCell><TableCell>{{payment.created_at}}</TableCell></TableRow></TableBody></Table><Link v-if="page.props.adminPanel?.menu.some(m=>m.href==='/admin/payments')" href="/admin/payments" class="text-sm text-blue-600">Buka pengelolaan pembayaran</Link></Card>
 <Card class="mt-5 p-4"><h2>Proses pesanan</h2><Table><TableHeader><TableRow><TableHead>Percobaan</TableHead><TableHead>Provider</TableHead><TableHead>Status</TableHead><TableHead>Catatan</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="attempt in attempts" :key="attempt.id"><TableCell>#{{attempt.attempt_no}}</TableCell><TableCell>{{attempt.code}}</TableCell><TableCell>{{attempt.status}}</TableCell><TableCell>{{attempt.last_error||'-'}}</TableCell></TableRow></TableBody></Table><Link v-if="page.props.adminPanel?.menu.some(m=>m.href==='/admin/fulfillment')" href="/admin/fulfillment" class="text-sm text-blue-600">Buka penanganan pesanan</Link></Card>
 <section class="lf-admin-editor mt-5"><h2>Riwayat Status</h2><p v-if="!events.length">Belum ada perubahan status.</p><article v-for="event in events" :key="event.id" class="border-b border-slate-200 py-3"><strong>{{event.event_type}}</strong><p>{{event.from_status || 'Baru'}} → {{event.to_status || '—'}} · {{event.created_at}}</p></article></section>
</AdminShell>
</template>
