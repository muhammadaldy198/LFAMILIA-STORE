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
    membershipTiers: { type: [Array, Object], default: () => [] },
    report: { type: Object, default: () => ({}) },
    topProducts: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    tiers: { type: Array, default: () => [] },
    checks: { type: Array, default: () => [] },
    voucherCategories: { type: Array, default: () => [] },
    voucherProducts: { type: Array, default: () => [] },
    popularProducts: { type: Array, default: () => [] },
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
const searchRows = () => router.get('/admin/' + (props.kind === 'orders' ? 'orders' : 'customers'), {q:searchQuery.value,status:props.kind==='orders'?orderStatus.value:undefined}, {preserveState:true});
const popularProducts = reactive((props.popularProducts || []).map((row) => ({ ...row })));

const voucherForm = useForm({
    code: '', discount_type: 'FIXED', discount_value: 1000, minimum_total_idr: 0,
    total_quota: null, per_customer_limit: null, starts_at: null, ends_at: null,
    product_ids: [], category_ids: [], is_active: false,
});

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

function updateProvider(row) {
    router.put('/admin/providers/' + row.id, { is_active: row.is_active }, { preserveScroll: true });
}
function savePopularProduct(row) {
    router.put('/admin/vouchers/popular/' + row.id, {
        popular: Boolean(row.popular),
    }, { preserveScroll: true });
}
function saveVoucher(row) {
    router.put('/admin/vouchers/' + row.id, {
        code: row.code,
        discount_type: row.discount_type,
        discount_value: Number(row.discount_value),
        minimum_total_idr: Number(row.minimum_total_idr || 0),
        total_quota: row.total_quota ? Number(row.total_quota) : null,
        per_customer_limit: row.per_customer_limit ? Number(row.per_customer_limit) : null,
        starts_at: row.starts_at || null,
        ends_at: row.ends_at || null,
        product_ids: (row.product_ids || []).map(Number),
        category_ids: (row.category_ids || []).map(Number),
        is_active: Boolean(row.is_active),
    }, { preserveScroll: true });
}
function updateMembership(row) {
    router.put('/admin/customers/' + row.id + '/membership', {
        membership_tier_code: row.membership_assignment || 'AUTO',
    }, { preserveScroll: true });
}
function saveTier(tier) {
    router.put('/admin/settings/membership/' + encodeURIComponent(tier.code), {
        is_active: Boolean(tier.is_active),
        requirements: typeof tier.requirements === 'string' ? tier.requirements : JSON.stringify(tier.requirements || {}, null, 2),
        benefits: typeof tier.benefits === 'string' ? tier.benefits : JSON.stringify(tier.benefits || {}, null, 2),
    }, { preserveScroll: true });
}
function adjustWallet(row) {
    const amount = Number(row.adjust_amount || 0);
    const reason = String(row.adjust_reason || '').trim();
    if (!amount || !reason) return;
    router.post('/admin/customers/' + row.id + '/wallet', {
        amount_idr: amount,
        reason,
        idempotency_key: globalThis.crypto?.randomUUID?.() || ('admin-wallet-' + Date.now() + '-' + row.id),
    }, { preserveScroll: true, onSuccess: () => { row.adjust_amount = ''; row.adjust_reason = ''; } });
}
</script>

<template>
    <Head :title="title" />
    <AdminShell>
        <div class="space-y-6">
            <div><h1 class="text-3xl font-semibold">{{ title }}</h1></div>

            <form v-if="kind === 'orders' || kind === 'customers'" class="lf-admin-filter-row" @submit.prevent="searchRows"><label>Cari {{kind === 'orders' ? 'nomor invoice' : 'nama/email pelanggan'}}<Input v-model="searchQuery" maxlength="100" /></label><label v-if="kind==='orders'">Status<select v-model="orderStatus" class="mt-1 rounded border p-2"><option value="">Semua status</option><option v-for="status in ['PENDING_PAYMENT','PAID','PROCESSING','SUCCESS','FAILED','EXPIRED','CANCELLED']" :key="status">{{status}}</option></select></label><div class="self-end"><Button class="lf-admin-primary">Cari</Button></div></form>
            <section v-if="kind === 'orders'" class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900 p-4">
                <Table class="w-full min-w-[850px] text-sm">
                    <TableHeader class="text-left text-slate-400"><TableRow><TableHead class="p-2">Order</TableHead><TableHead class="p-2">Produk</TableHead><TableHead class="p-2">Nominal</TableHead><TableHead class="p-2">Status</TableHead><TableHead class="p-2">Total</TableHead><TableHead class="p-2">Dibuat</TableHead></TableRow></TableHeader>
                    <TableBody><TableRow v-for="row in rows" :key="row.id" class="border-t border-slate-800"><TableCell class="p-2"><Link :href="'/admin/orders/' + row.id" class="text-blue-600 font-semibold">{{ row.order_number }}</Link></TableCell><TableCell class="p-2">{{ row.product_name }}</TableCell><TableCell class="p-2">{{ row.package_name }}</TableCell><TableCell class="p-2">{{ row.status }}</TableCell><TableCell class="p-2">Rp{{ Number(row.total_idr).toLocaleString('id-ID') }}</TableCell><TableCell class="p-2">{{ row.created_at }}</TableCell></TableRow></TableBody>
                </Table>
            </section>

            <section v-else-if="kind === 'providers'" class="grid gap-4 lg:grid-cols-2">
                <div v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <div class="flex items-center justify-between"><strong>{{ row.code }}</strong><span class="text-xs text-slate-500">{{ row.fulfillment_mode }}</span></div>
                    <p class="mt-2 text-sm text-slate-400">Mapping {{ row.active_mapping_count }}/{{ row.mapping_count }} aktif.</p>
                    <div class="mt-4 flex items-center gap-3"><label class="flex gap-2 text-sm"><input v-model="row.is_active" type="checkbox">Provider aktif</label><Button class="rounded bg-slate-700 px-3 py-2 text-xs" @click="updateProvider(row)">Simpan</Button></div>
                </div>
            </section>

            <section v-else-if="kind === 'customers'" class="space-y-3">
                <div v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div class="grid gap-3 md:grid-cols-5">
                        <div class="md:col-span-2"><Link :href="'/admin/customers/'+row.id" class="font-semibold text-blue-600">{{ row.name }}</Link><p class="text-xs text-slate-400">{{ row.email || '-' }} · {{ row.phone || '-' }}</p></div>
                        <div><span class="text-xs text-slate-500">Saldo</span><div>Rp{{ Number(row.balance_idr || 0).toLocaleString('id-ID') }}</div></div>
                        <label class="text-xs">Membership<select v-model="row.membership_assignment" :disabled="!isSuper" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="AUTO">AUTO (berdasarkan transaksi)</option><option v-for="tier in membershipTiers" :key="tier" :value="tier">{{ tier }} (manual)</option></select><small class="mt-1 block text-[10px] text-slate-500">Aktif: {{row.membership_tier_code}} · Belanja Rp{{Number(row.lifetime_spend_idr||0).toLocaleString('id-ID')}}</small></label>
                        <Button v-if="isSuper" class="self-end rounded bg-slate-700 px-3 py-2 text-xs" @click="updateMembership(row)">Simpan tier</Button>
                    </div>
                    <div v-if="isSuper" class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3">
                        <label class="text-xs">Penyesuaian saldo (+/-)<Input v-model="row.adjust_amount" type="number" class="mt-1 block rounded bg-slate-800 p-2" /></label>
                        <label class="min-w-60 flex-1 text-xs">Alasan<Input v-model="row.adjust_reason" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                        <Button class="rounded bg-cyan-300 px-3 py-2 text-xs font-semibold text-slate-950" @click="adjustWallet(row)">Terapkan</Button>
                    </div>
                </div>
            </section>

            <section v-else-if="kind === 'vouchers'" class="space-y-5">
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold">Populer Sekarang</h2>
                            <p class="mt-1 text-xs text-slate-500">Produk bertanda populer diprioritaskan di homepage. Jika slot belum penuh, homepage melanjutkan dengan produk aktif berulasan terbanyak seperti repo LFAMILIA sebelumnya.</p>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="product in popularProducts" :key="product.id" class="flex items-center justify-between gap-3 rounded-lg border border-slate-800 bg-slate-950 p-3">
                            <div class="min-w-0">
                                <strong class="block truncate text-sm">{{product.name}}</strong>
                                <span class="text-[10px] text-slate-500">{{product.category_name}} · {{product.is_active ? 'Aktif' : 'Nonaktif'}}</span>
                            </div>
                            <label class="flex shrink-0 items-center gap-2 text-xs">
                                <input v-model="product.popular" type="checkbox" @change="savePopularProduct(product)">
                                Populer
                            </label>
                        </article>
                    </div>
                </div>
                <form class="grid gap-3 rounded-xl border border-slate-800 bg-slate-900 p-5 md:grid-cols-4" @submit.prevent="voucherForm.post('/admin/vouchers', { preserveScroll: true, onSuccess: () => voucherForm.reset() })">
                    <Input v-model="voucherForm.code" required placeholder="Kode voucher" class="rounded bg-slate-800 p-2" />
                    <select v-model="voucherForm.discount_type" class="rounded bg-slate-800 p-2"><option>FIXED</option><option>PERCENT</option></select>
                    <Input v-model.number="voucherForm.discount_value" required type="number" min="1" placeholder="Nilai" class="rounded bg-slate-800 p-2" />
                    <Input v-model.number="voucherForm.minimum_total_idr" type="number" min="0" placeholder="Minimum transaksi" class="rounded bg-slate-800 p-2" />
                    <Input v-model.number="voucherForm.total_quota" type="number" min="1" placeholder="Total kuota" class="rounded bg-slate-800 p-2" />
                    <Input v-model.number="voucherForm.per_customer_limit" type="number" min="1" placeholder="Limit/customer" class="rounded bg-slate-800 p-2" />
                    <Input v-model="voucherForm.starts_at" type="datetime-local" class="rounded bg-slate-800 p-2" />
                    <Input v-model="voucherForm.ends_at" type="datetime-local" class="rounded bg-slate-800 p-2" />
                    <label class="text-xs md:col-span-2">Scope kategori <span class="text-slate-500">(kosong = semua)</span>
                        <select v-model="voucherForm.category_ids" multiple class="mt-1 block min-h-28 w-full rounded bg-slate-800 p-2">
                            <option v-for="category in voucherCategories" :key="category.id" :value="category.id">{{category.name}}</option>
                        </select>
                    </label>
                    <label class="text-xs md:col-span-2">Scope produk <span class="text-slate-500">(kosong = semua)</span>
                        <select v-model="voucherForm.product_ids" multiple class="mt-1 block min-h-28 w-full rounded bg-slate-800 p-2">
                            <option v-for="product in voucherProducts" :key="product.id" :value="product.id">{{product.name}}</option>
                        </select>
                    </label>
                    <label class="flex gap-2 text-sm"><input v-model="voucherForm.is_active" type="checkbox">Aktif</label>
                    <Button class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950">Tambah voucher</Button>
                    <p class="text-xs text-slate-500 md:col-span-4">Scope kategori/produk memakai logika OR: voucher berlaku bila produk atau kategorinya termasuk scope. Jika keduanya kosong, voucher berlaku global.</p>
                </form>

                <article v-for="row in rows" :key="row.id" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div class="grid gap-2 md:grid-cols-4">
                        <Input v-model="row.code" class="rounded bg-slate-800 p-2" placeholder="Kode" />
                        <select v-model="row.discount_type" class="rounded bg-slate-800 p-2"><option>FIXED</option><option>PERCENT</option></select>
                        <Input v-model.number="row.discount_value" type="number" min="1" class="rounded bg-slate-800 p-2" placeholder="Nilai" />
                        <Input v-model.number="row.minimum_total_idr" type="number" min="0" class="rounded bg-slate-800 p-2" placeholder="Minimum transaksi" />
                        <Input v-model.number="row.total_quota" type="number" min="1" class="rounded bg-slate-800 p-2" placeholder="Total kuota" />
                        <Input v-model.number="row.per_customer_limit" type="number" min="1" class="rounded bg-slate-800 p-2" placeholder="Limit/customer" />
                        <Input v-model="row.starts_at" type="datetime-local" class="rounded bg-slate-800 p-2" />
                        <Input v-model="row.ends_at" type="datetime-local" class="rounded bg-slate-800 p-2" />
                        <label class="text-xs md:col-span-2">Scope kategori
                            <select v-model="row.category_ids" multiple class="mt-1 block min-h-24 w-full rounded bg-slate-800 p-2">
                                <option v-for="category in voucherCategories" :key="category.id" :value="category.id">{{category.name}}</option>
                            </select>
                        </label>
                        <label class="text-xs md:col-span-2">Scope produk
                            <select v-model="row.product_ids" multiple class="mt-1 block min-h-24 w-full rounded bg-slate-800 p-2">
                                <option v-for="product in voucherProducts" :key="product.id" :value="product.id">{{product.name}}</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="flex items-center gap-2 text-sm"><input v-model="row.is_active" type="checkbox">Aktif</label>
                        <Button class="rounded bg-slate-700 px-4 py-2 text-sm" @click="saveVoucher(row)">Simpan voucher</Button>
                    </div>
                </article>
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
                        <div class="space-y-2"><select aria-label="Pilih balasan cepat" class="w-full rounded border p-2 text-sm" @change="row.quick_reply=$event.target.value"><option value="">Pilih balasan cepat</option><option v-for="reply in quickRepliesForm.replies.filter(r=>r.trim())" :key="reply" :value="reply">{{reply.slice(0,80)}}</option></select><Textarea v-model="row.quick_reply" rows="2" class="rounded bg-slate-800 p-2 text-sm" placeholder="Balasan ke pelanggan (opsional)"></Textarea></div>
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
            <p v-if="['orders','customers','support'].includes(kind)&&!rows.length" class="text-sm text-slate-500">Tidak ada data sesuai pencarian.</p>
            <nav v-if="props.rows?.links" class="flex flex-wrap gap-2" aria-label="Halaman data"><template v-for="link in props.rows.links" :key="link.label"><Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-2 text-sm" :class="{'bg-slate-900 text-white':link.active}" v-html="link.label"/><span v-else class="px-3 py-2 text-sm text-slate-400" v-html="link.label"/></template></nav>
        </div>
    </AdminShell>
</template>
