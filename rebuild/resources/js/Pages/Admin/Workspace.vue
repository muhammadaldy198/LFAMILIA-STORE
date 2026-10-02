<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({
    quickReplies:{type:Array,default:()=>[]},
    dailyReport:{type:Array,default:()=>[]}, providerReport:{type:Array,default:()=>[]},
    kind: String,
    filters: Object,
    title: String,
    rows: { type: [Array, Object], default: () => [] },
    report: { type: Object, default: () => ({}) },
    topProducts: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    tiers: { type: Array, default: () => [] },
    checks: { type: Array, default: () => [] },
});
const page = usePage();
const base = computed(() => page.props.adminPanel?.base_path || '/admin');
const isSuper = computed(() => page.props.adminPanel?.admin?.role === 'SUPER_ADMIN');
const rows = reactive(Array.isArray(props.rows) ? props.rows.map((row) => ({ ...row })) : (props.rows?.data || []).map((row) => ({ ...row })));
const orderStatus=ref(props.filters?.status||'');
const reportRange=reactive({from:props.filters?.from||'',to:props.filters?.to||''});
const quickRepliesForm=useForm({replies:[...props.quickReplies]});
const updateSupport=row=>router.put('/admin/support/'+row.id,{status:row.status,reply:row.quick_reply||null},{preserveScroll:true,onSuccess:()=>{row.quick_reply='';}});
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

            <section v-else-if="kind === 'support'" class="space-y-3">
                <form class="space-y-3 rounded-md border p-4" @submit.prevent="quickRepliesForm.put('/admin/support/quick-replies',{preserveScroll:true})">
                    <h2 class="font-semibold">Balasan cepat</h2>
                    <div v-for="(reply,index) in quickRepliesForm.replies" :key="index" class="flex flex-wrap gap-2"><Textarea v-model="quickRepliesForm.replies[index]" maxlength="1000" rows="2" class="min-w-0 flex-1"/><Button type="button" variant="outline" @click="quickRepliesForm.replies.splice(index,1)">Hapus</Button></div>
                    <div class="flex flex-wrap gap-2"><Button type="button" variant="outline" :disabled="quickRepliesForm.replies.length>=30" @click="quickRepliesForm.replies.push('')">Tambah balasan cepat</Button><Button :disabled="quickRepliesForm.processing">Simpan balasan cepat</Button></div>
                </form>
                <div v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex flex-wrap justify-between gap-2"><strong>Tiket #{{row.id}} · {{ row.subject }}</strong><span class="text-xs text-slate-500">{{ row.customer_name }} · {{ row.order_number || 'tanpa order' }}</span></div>
                    <div class="mt-3 rounded-lg bg-slate-950 p-3">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Pesan awal pelanggan</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-slate-300">{{ row.message }}</p>
                    </div>
                    <div v-if="row.messages?.length" class="mt-3 space-y-2">
                        <article v-for="message in row.messages" :key="message.id" class="rounded-lg border p-3" :class="message.sender_type==='ADMIN' ? 'border-cyan-900/60 bg-cyan-950/20' : 'border-slate-800 bg-slate-950/50'">
                            <div class="flex justify-between gap-3 text-[10px] text-slate-500">
                                <strong>{{message.sender_type==='ADMIN' ? (message.admin_name || 'Admin LFAMILIA') : (message.customer_name || row.customer_name)}}</strong>
                                <span>{{message.created_at}}</span>
                            </div>
                            <p class="mt-2 whitespace-pre-wrap text-sm text-slate-300">{{message.message}}</p>
                        </article>
                    </div>
                    <div class="mt-3 grid gap-2 md:grid-cols-[180px_minmax(0,1fr)_auto]">
                        <select v-model="row.status" class="rounded bg-slate-800 p-2 text-sm"><option>OPEN</option><option>IN_PROGRESS</option><option>RESOLVED</option><option>CLOSED</option></select>
                        <div class="space-y-2"><select aria-label="Pilih balasan cepat" class="w-full rounded border p-2 text-sm" @change="row.quick_reply=$event.target.value"><option value="">Pilih balasan cepat</option><option v-for="(reply,index) in quickRepliesForm.replies.filter(r=>r.trim())" :key="index" :value="reply">{{reply.slice(0,80)}}</option></select><Textarea v-model="row.quick_reply" rows="2" class="rounded bg-slate-800 p-2 text-sm" placeholder="Balasan ke pelanggan (opsional)"></Textarea></div>
                        <Button class="rounded bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950" @click="updateSupport(row)">Simpan / Balas</Button>
                    </div>
                </div>
            </section>

            <template v-else-if="kind === 'reports'">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="router.get('/admin/reports',reportRange)"><label class="text-sm">Dari<Input v-model="reportRange.from" type="date" class="mt-1"/></label><label class="text-sm">Sampai<Input v-model="reportRange.to" type="date" class="mt-1"/></label><Button>Tampilkan laporan</Button></form>
                <section class="rounded-md border p-4"><h2 class="font-semibold">Transaksi per hari</h2><Table><TableHeader><TableRow><TableHead>Tanggal</TableHead><TableHead>Pesanan</TableHead><TableHead>Omzet</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="row in dailyReport" :key="row.day"><TableCell>{{row.day}}</TableCell><TableCell>{{row.orders_count}}</TableCell><TableCell>Rp{{Number(row.revenue_idr).toLocaleString('id-ID')}}</TableCell></TableRow></TableBody></Table></section>
                <section class="rounded-md border p-4"><h2 class="font-semibold">Performa provider</h2><Table><TableHeader><TableRow><TableHead>Provider</TableHead><TableHead>Percobaan</TableHead><TableHead>Bermasalah</TableHead><TableHead>Error rate</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="row in providerReport" :key="row.code"><TableCell>{{row.code}}</TableCell><TableCell>{{row.attempts_count}}</TableCell><TableCell>{{row.errors_count}}</TableCell><TableCell>{{(100*Number(row.errors_count)/Math.max(1,Number(row.attempts_count))).toFixed(1)}}%</TableCell></TableRow></TableBody></Table></section>
                <section class="grid gap-3 md:grid-cols-3"><div v-for="(value, key) in report" :key="key" class="rounded-xl border border-slate-800 bg-slate-900 p-4"><div class="text-xs uppercase text-slate-500">{{ key.replaceAll('_', ' ') }}</div><div class="mt-2 text-2xl font-semibold">{{ key.includes('revenue') || key.includes('wallet') ? 'Rp' + Number(value).toLocaleString('id-ID') : value }}</div></div></section>
                <section class="rounded-xl border border-slate-800 bg-slate-900 p-5"><h2 class="font-semibold">Produk berhasil teratas</h2><div v-for="row in topProducts" :key="row.name" class="mt-3 flex justify-between border-t border-slate-800 pt-3 text-sm"><span>{{ row.name }}</span><span>{{ row.orders_count }} order · Rp{{ Number(row.revenue_idr).toLocaleString('id-ID') }}</span></div></section>
            </template>

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
