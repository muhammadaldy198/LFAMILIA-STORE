<script setup>
import {Head,Link} from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import {Card} from '../../Components/ui/card';
import {Table,TableHeader,TableBody,TableRow,TableHead,TableCell} from '../../Components/ui/table';
defineProps({customer:Object,balanceIdr:Number,orders:Object,ledger:Array});
const money=v=>'Rp'+Number(v||0).toLocaleString('id-ID');
</script>
<template><Head :title="'Pelanggan · '+customer.name"/><AdminShell><div class="space-y-5">
<Link href="/admin/customers" class="text-sm text-blue-600">← Daftar pelanggan</Link>
<Card class="space-y-2 p-4"><h1>{{customer.name}}</h1><p>{{customer.email||'-'}} · {{customer.phone||'-'}}</p><p>Membership {{customer.membership_tier_code}} · Saldo {{money(balanceIdr)}}</p></Card>
<Card class="p-4"><h2 class="font-semibold">Riwayat pesanan</h2><Table><TableHeader><TableRow><TableHead>Invoice</TableHead><TableHead>Status</TableHead><TableHead>Total</TableHead><TableHead>Tanggal</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="order in orders.data" :key="order.id"><TableCell><Link :href="'/admin/orders/'+order.id" class="text-blue-600">{{order.order_number}}</Link></TableCell><TableCell>{{order.status}}</TableCell><TableCell>{{money(order.total_idr)}}</TableCell><TableCell>{{order.created_at}}</TableCell></TableRow></TableBody></Table><p v-if="!orders.data.length" class="py-4 text-sm">Belum ada pesanan.</p><nav class="flex flex-wrap gap-2"><Link v-for="link in orders.links.filter(l=>l.url)" :key="link.label" :href="link.url" class="rounded border px-3 py-2 text-sm" v-html="link.label"/></nav></Card>
<Card class="p-4"><h2 class="font-semibold">30 aktivitas saldo terakhir</h2><Table><TableHeader><TableRow><TableHead>Tanggal</TableHead><TableHead>Sumber</TableHead><TableHead>Perubahan</TableHead><TableHead>Saldo setelahnya</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="entry in ledger" :key="entry.id"><TableCell>{{entry.created_at}}</TableCell><TableCell>{{entry.source}}</TableCell><TableCell>{{money(entry.amount_idr)}}</TableCell><TableCell>{{money(entry.balance_after_idr)}}</TableCell></TableRow></TableBody></Table></Card>
</div></AdminShell></template>
