<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({
    kind: String,
    filters: Object,
    title: String,
    rows: { type: [Array, Object], default: () => [] },
    settings: { type: Object, default: () => ({}) },
    tiers: { type: Array, default: () => [] },
    checks: { type: Array, default: () => [] },
});
const page = usePage();
const base = computed(() => page.props.adminPanel?.base_path || '/admin');
const isSuper = computed(() => page.props.adminPanel?.admin?.role === 'SUPER_ADMIN');
const rows = reactive(Array.isArray(props.rows) ? props.rows.map((row) => ({ ...row })) : (props.rows?.data || []).map((row) => ({ ...row })));
const orderStatus=ref(props.filters?.status||'');
const searchQuery = ref(props.filters?.q || '');
watch(() => props.rows, value => { rows.splice(0, rows.length, ...(Array.isArray(value) ? value : value?.data || []).map(row => ({...row}))); });
const searchRows = () => router.get('/admin/orders', { q: searchQuery.value, status: orderStatus.value || undefined }, { preserveState: true });
const settingsForm = useForm({
    store_name: props.settings?.['store.name'] || '',
    tagline: props.settings?.['store.tagline'] || '',
    support_whatsapp: props.settings?.['store.support_whatsapp'] || '',
    instagram_url: props.settings?.['store.instagram_url'] || '',
    email: props.settings?.['store.email'] || '',
    discord_url: props.settings?.['store.discord_url'] || '',
    support_url: props.settings?.['store.support_url'] || '',
    business_hours: props.settings?.['store.business_hours'] || '',
});

function saveTier(tier) {
    router.put('/admin/settings/membership/' + encodeURIComponent(tier.code), {
        is_active: Boolean(tier.is_active),
        requirements: typeof tier.requirements === 'string' ? tier.requirements : JSON.stringify(tier.requirements || {}, null, 2),
        benefits: typeof tier.benefits === 'string' ? tier.benefits : JSON.stringify(tier.benefits || {}, null, 2),
    }, { preserveScroll: true });
}
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

            <section v-else-if="kind === 'settings'" class="space-y-5">
                <section v-if="isSuper" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <div>
                        <h2 class="font-semibold">Export Configuration JSON</h2>
                        <p class="mt-1 text-xs text-slate-400">Unduh konfigurasi aman tanpa credential, password, API key, token, private key, atau payment signature.</p>
                    </div>
                    <a href="/admin/configuration/export" class="rounded bg-slate-700 px-4 py-2 text-sm font-semibold text-white">Export JSON</a>
                </section>
                <form class="grid gap-3 rounded-xl border border-slate-800 bg-slate-900 p-5 md:grid-cols-2" @submit.prevent="settingsForm.put('/admin/settings')">
                    <label class="text-sm">Nama toko<Input v-model="settingsForm.store_name" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">Tagline<Input v-model="settingsForm.tagline" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">WhatsApp<Input v-model="settingsForm.support_whatsapp" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">Instagram URL<Input v-model="settingsForm.instagram_url" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">Email<Input v-model="settingsForm.email" type="email" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">Discord URL<Input v-model="settingsForm.discord_url" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">Support URL<Input v-model="settingsForm.support_url" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <label class="text-sm">Jam layanan<Input v-model="settingsForm.business_hours" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    <Button class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950">Simpan pengaturan</Button>
                </form>
                <section class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="font-semibold">Membership tiers</h2>
                    <div v-for="tier in tiers" :key="tier.code" class="grid gap-2 border-t border-slate-800 pt-3 md:grid-cols-2">
                        <div class="md:col-span-2 flex items-center justify-between"><strong>{{ tier.rank }}. {{ tier.code }}</strong><label class="flex gap-2 text-sm"><input v-model="tier.is_active" type="checkbox">Aktif</label></div>
                        <label class="text-xs">Requirements JSON<Textarea v-model="tier.requirements" rows="4" class="mt-1 block w-full rounded bg-slate-800 p-2 font-mono"></Textarea></label>
                        <label class="text-xs">Benefits JSON<Textarea v-model="tier.benefits" rows="4" class="mt-1 block w-full rounded bg-slate-800 p-2 font-mono"></Textarea></label>
                        <Button class="rounded bg-slate-700 px-3 py-2 text-sm md:col-span-2" @click="saveTier(tier)">Simpan tier</Button>
                    </div>
                </section>
            </section>

            <section v-else-if="kind === 'health'" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="check in checks" :key="check.name" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex justify-between gap-3"><strong>{{ check.name }}</strong><span class="text-xs" :class="check.status === 'HEALTHY' ? 'text-emerald-300' : 'text-amber-200'">{{ check.status }}</span></div>
                    <p class="mt-2 text-sm text-slate-400">{{ check.message }}</p>
                </div>
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
