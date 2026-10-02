<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({
    kind: String,
    filters: Object,
    title: String,
    rows: { type: [Array, Object], default: () => [] },
});
const rows = reactive(Array.isArray(props.rows) ? props.rows.map((row) => ({ ...row })) : (props.rows?.data || []).map((row) => ({ ...row })));
const orderStatus=ref(props.filters?.status||'');
const searchQuery = ref(props.filters?.q || '');
watch(() => props.rows, value => { rows.splice(0, rows.length, ...(Array.isArray(value) ? value : value?.data || []).map(row => ({...row}))); });
const searchRows = () => router.get('/admin/orders', { q: searchQuery.value, status: orderStatus.value || undefined }, { preserveState: true });
</script>

<template>
    <Head :title="title" />
    <AdminShell>
        <div class="space-y-6">
            <div><h1 class="text-3xl font-semibold">{{ title }}</h1></div>

            <form v-if="kind === 'orders'" class="lf-admin-filter-row" @submit.prevent="searchRows"><label>Cari nomor invoice<Input v-model="searchQuery" maxlength="100" /></label><label>Status<select v-model="orderStatus" class="mt-1 rounded border p-2"><option value="">Semua status</option><option v-for="status in ['PENDING_PAYMENT','PAID','PROCESSING','SUCCESS','FAILED','EXPIRED','CANCELLED']" :key="status">{{status}}</option></select></label><div class="self-end"><Button class="lf-admin-primary">Cari</Button></div></form>
            <section v-if="kind === 'orders'" class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900 p-4">
                <Table class="w-full min-w-[850px] text-sm">
                    <TableHeader class="text-left text-slate-400"><TableRow><TableHead class="p-2">Order</TableHead><TableHead class="p-2">Produk</TableHead><TableHead class="p-2">Nominal</TableHead><TableHead class="p-2">Status</TableHead><TableHead class="p-2">Total</TableHead><TableHead class="p-2">Dibuat</TableHead></TableRow></TableHeader>
                    <TableBody><TableRow v-for="row in rows" :key="row.id" class="border-t border-slate-800"><TableCell class="p-2"><Link :href="'/admin/orders/' + row.id" class="text-blue-600 font-semibold">{{ row.order_number }}</Link></TableCell><TableCell class="p-2">{{ row.product_name }}</TableCell><TableCell class="p-2">{{ row.package_name }}</TableCell><TableCell class="p-2">{{ row.status }}</TableCell><TableCell class="p-2">Rp{{ Number(row.total_idr).toLocaleString('id-ID') }}</TableCell><TableCell class="p-2">{{ row.created_at }}</TableCell></TableRow></TableBody>
                </Table>
            </section>

            <section v-else-if="kind === 'audit'" class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900 p-4">
                <Table class="w-full min-w-[1100px] text-xs">
                    <TableHeader class="text-left text-slate-400"><TableRow><TableHead class="p-2">Waktu</TableHead><TableHead class="p-2">Actor</TableHead><TableHead class="p-2">Aksi</TableHead><TableHead class="p-2">Target</TableHead><TableHead class="p-2">Correlation</TableHead><TableHead class="p-2">IP</TableHead></TableRow></TableHeader>
                    <TableBody><TableRow v-for="row in rows" :key="row.id" class="border-t border-slate-800"><TableCell class="p-2">{{ row.created_at }}</TableCell><TableCell class="p-2">{{ row.actor_role }} #{{ row.actor_id }}</TableCell><TableCell class="p-2">{{ row.action }}</TableCell><TableCell class="p-2">{{ row.target_type }} #{{ row.target_id }}</TableCell><TableCell class="p-2">{{ row.correlation_id }}</TableCell><TableCell class="p-2">{{ row.ip_address }}</TableCell></TableRow></TableBody>
                </Table>
            </section>
            <p v-if="['orders','support'].includes(kind)&&!rows.length" class="text-sm text-slate-500">Tidak ada data sesuai pencarian.</p>
            <nav v-if="props.rows?.links" class="flex flex-wrap gap-2" aria-label="Halaman data"><template v-for="link in props.rows.links" :key="link.label"><Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-2 text-sm" :class="{'bg-slate-900 text-white':link.active}" v-html="link.label"/><span v-else class="px-3 py-2 text-sm text-slate-400" v-html="link.label"/></template></nav>
        </div>
    </AdminShell>
</template>
