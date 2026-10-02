<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Card } from '../../Components/ui/card';
import { Badge } from '../../Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
const props=defineProps({items:Object,filters:Object,brands:Array,lastSyncedAt:String,mappingCount:Number,providerActive:Boolean});
const filters=ref({q:props.filters?.q||'',brand:props.filters?.brand||'',status:props.filters?.status||''});
const syncForm=useForm({});
const sync=id=>syncForm.transform(()=>id?{item_id:id}:{}).post('/admin/digiflazz/sync',{preserveScroll:true});
const search=()=>router.get('/admin/providers',{provider:'DIGIFLAZZ',...filters.value},{preserveState:true});
const money=value=>'Rp'+Number(value||0).toLocaleString('id-ID');
</script>
<template>
<Head title="Digiflazz"/><AdminShell><div class="space-y-5">
<header class="flex flex-wrap items-center justify-between gap-3"><div><h1>Digiflazz</h1><p class="text-sm text-slate-500">Sinkron terakhir: {{lastSyncedAt||'Belum pernah'}} · {{mappingCount}} mapping · Provider {{providerActive?'aktif':'nonaktif'}}</p></div><Button :disabled="syncForm.processing" @click="sync()">{{syncForm.processing?'Menyinkronkan…':'Sinkron daftar harga'}}</Button></header>
<Card class="p-4"><form class="grid gap-3 md:grid-cols-4" @submit.prevent="search"><label class="text-sm">Cari produk / SKU / seller<Input v-model="filters.q" maxlength="100" class="mt-1"/></label><label class="text-sm">Brand<select v-model="filters.brand" class="mt-1 w-full rounded-md border p-2"><option value="">Semua brand</option><option v-for="brand in brands" :key="brand">{{brand}}</option></select></label><label class="text-sm">Status<select v-model="filters.status" class="mt-1 w-full rounded-md border p-2"><option value="">Semua</option><option value="active">Buyer & seller aktif</option><option value="attention">Perlu perhatian</option></select></label><Button class="self-end">Terapkan filter</Button></form></Card>
<p class="text-sm text-slate-500">Data berasal dari daftar harga buyer Digiflazz. Kenaikan dari baseline ditandai; stok dan jadwal cut-off diperiksa saat checkout. Impor nominal tersedia di Produk → Edit → Nominal & Harga.</p>
<Card class="overflow-hidden"><Table><TableHeader><TableRow><TableHead>Produk / SKU</TableHead><TableHead>Seller</TableHead><TableHead>Modal / baseline</TableHead><TableHead>Status</TableHead><TableHead>Stok / cut-off WIB</TableHead><TableHead>Aksi</TableHead></TableRow></TableHeader><TableBody>
<TableRow v-for="item in items.data" :key="item.id"><TableCell><strong>{{item.product_name}}</strong><p class="text-xs text-slate-500">{{item.buyer_sku_code}} · {{item.brand}}</p></TableCell><TableCell>{{item.seller_name}}</TableCell><TableCell>{{money(item.price_idr)}}<p class="text-xs text-slate-500">Baseline {{money(item.baseline_price_idr)}}</p><Badge v-if="item.price_idr>item.baseline_price_idr" variant="destructive">Harga naik {{money(item.price_idr-item.baseline_price_idr)}}</Badge></TableCell><TableCell><Badge :variant="item.available?'secondary':'destructive'">{{item.available?'Tersedia':'Tidak tersedia'}}</Badge><p class="text-xs">Buyer {{item.buyer_active?'aktif':'nonaktif'}} · Seller {{item.seller_active?'aktif':'nonaktif'}}</p></TableCell><TableCell>{{item.unlimited_stock?'Tidak terbatas':item.stock}}<p class="text-xs">{{item.start_cut_off===item.end_cut_off?'Tanpa cut-off':item.start_cut_off+'–'+item.end_cut_off}}</p></TableCell><TableCell><div class="flex flex-wrap gap-2"><Button variant="outline" :disabled="syncForm.processing" @click="sync(item.id)">Sinkron SKU</Button><Button variant="outline" @click="router.put('/admin/digiflazz/baseline/'+item.id,{}, {preserveScroll:true})">Set baseline</Button></div></TableCell></TableRow>
<TableRow v-if="!items.data.length"><TableCell colspan="6" class="py-8 text-center">Belum ada SKU sesuai filter. Sinkronkan daftar harga setelah melengkapi Integrasi.</TableCell></TableRow>
</TableBody></Table></Card>
<nav class="flex flex-wrap gap-2" aria-label="Halaman daftar SKU"><template v-for="link in items.links" :key="link.label"><Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-2 text-sm" :class="{'bg-slate-900 text-white':link.active}" v-html="link.label"/><span v-else class="px-3 py-2 text-sm text-slate-400" v-html="link.label"/></template></nav>
</div></AdminShell>
</template>
