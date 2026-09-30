<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({
    kind: String,
    title: String,
    rows: { type: [Array, Object], default: () => [] },
    membershipTiers: { type: [Array, Object], default: () => [] },
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

const voucherForm = useForm({
    code: '', discount_type: 'FIXED', discount_value: 1000, minimum_total_idr: 0,
    total_quota: null, per_customer_limit: null, starts_at: null, ends_at: null, is_active: false,
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
function saveVoucher(row) {
    router.put('/admin/vouchers/' + row.id, {
        code: row.code, discount_type: row.discount_type, discount_value: Number(row.discount_value),
        minimum_total_idr: Number(row.minimum_total_idr || 0),
        total_quota: row.total_quota || null, per_customer_limit: row.per_customer_limit || null,
        starts_at: row.starts_at || null, ends_at: row.ends_at || null, is_active: Boolean(row.is_active),
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

            <section v-if="kind === 'orders'" class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900 p-4">
                <table class="w-full min-w-[850px] text-sm">
                    <thead class="text-left text-slate-400"><tr><th class="p-2">Order</th><th class="p-2">Produk</th><th class="p-2">Nominal</th><th class="p-2">Status</th><th class="p-2">Total</th><th class="p-2">Dibuat</th></tr></thead>
                    <tbody><tr v-for="row in rows" :key="row.id" class="border-t border-slate-800"><td class="p-2">{{ row.order_number }}</td><td class="p-2">{{ row.product_name }}</td><td class="p-2">{{ row.package_name }}</td><td class="p-2">{{ row.status }}</td><td class="p-2">Rp{{ Number(row.total_idr).toLocaleString('id-ID') }}</td><td class="p-2">{{ row.created_at }}</td></tr></tbody>
                </table>
            </section>

            <section v-else-if="kind === 'providers'" class="grid gap-4 lg:grid-cols-2">
                <div v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <div class="flex items-center justify-between"><strong>{{ row.code }}</strong><span class="text-xs text-slate-500">{{ row.fulfillment_mode }}</span></div>
                    <p class="mt-2 text-sm text-slate-400">Mapping {{ row.active_mapping_count }}/{{ row.mapping_count }} aktif.</p>
                    <div class="mt-4 flex items-center gap-3"><label class="flex gap-2 text-sm"><input v-model="row.is_active" type="checkbox">Provider aktif</label><button class="rounded bg-slate-700 px-3 py-2 text-xs" @click="updateProvider(row)">Simpan</button></div>
                </div>
            </section>

            <section v-else-if="kind === 'customers'" class="space-y-3">
                <div v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                    <div class="grid gap-3 md:grid-cols-5">
                        <div class="md:col-span-2"><strong>{{ row.name }}</strong><p class="text-xs text-slate-400">{{ row.email || '-' }} · {{ row.phone || '-' }}</p></div>
                        <div><span class="text-xs text-slate-500">Saldo</span><div>Rp{{ Number(row.balance_idr || 0).toLocaleString('id-ID') }}</div></div>
                        <label class="text-xs">Membership<select v-model="row.membership_assignment" :disabled="!isSuper" class="mt-1 block w-full rounded bg-slate-800 p-2"><option value="AUTO">AUTO (berdasarkan transaksi)</option><option v-for="tier in membershipTiers" :key="tier" :value="tier">{{ tier }} (manual)</option></select><small class="mt-1 block text-[10px] text-slate-500">Aktif: {{row.membership_tier_code}} · Belanja Rp{{Number(row.lifetime_spend_idr||0).toLocaleString('id-ID')}}</small></label>
                        <button v-if="isSuper" class="self-end rounded bg-slate-700 px-3 py-2 text-xs" @click="updateMembership(row)">Simpan tier</button>
                    </div>
                    <div v-if="isSuper" class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3">
                        <label class="text-xs">Penyesuaian saldo (+/-)<input v-model="row.adjust_amount" type="number" class="mt-1 block rounded bg-slate-800 p-2"></label>
                        <label class="min-w-60 flex-1 text-xs">Alasan<input v-model="row.adjust_reason" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                        <button class="rounded bg-cyan-300 px-3 py-2 text-xs font-semibold text-slate-950" @click="adjustWallet(row)">Terapkan</button>
                    </div>
                </div>
            </section>

            <section v-else-if="kind === 'vouchers'" class="space-y-5">
                <form class="grid gap-2 rounded-xl border border-slate-800 bg-slate-900 p-5 md:grid-cols-4" @submit.prevent="voucherForm.post('/admin/vouchers', { onSuccess: () => voucherForm.reset() })">
                    <input v-model="voucherForm.code" required placeholder="Kode voucher" class="rounded bg-slate-800 p-2">
                    <select v-model="voucherForm.discount_type" class="rounded bg-slate-800 p-2"><option>FIXED</option><option>PERCENT</option></select>
                    <input v-model.number="voucherForm.discount_value" required type="number" min="1" placeholder="Nilai" class="rounded bg-slate-800 p-2">
                    <input v-model.number="voucherForm.minimum_total_idr" type="number" min="0" placeholder="Minimum transaksi" class="rounded bg-slate-800 p-2">
                    <input v-model.number="voucherForm.total_quota" type="number" min="1" placeholder="Total kuota" class="rounded bg-slate-800 p-2">
                    <input v-model.number="voucherForm.per_customer_limit" type="number" min="1" placeholder="Limit/customer" class="rounded bg-slate-800 p-2">
                    <input v-model="voucherForm.starts_at" type="datetime-local" class="rounded bg-slate-800 p-2">
                    <input v-model="voucherForm.ends_at" type="datetime-local" class="rounded bg-slate-800 p-2">
                    <label class="flex gap-2 text-sm"><input v-model="voucherForm.is_active" type="checkbox">Aktif</label>
                    <button class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950">Tambah voucher</button>
                </form>
                <div v-for="row in rows" :key="row.id" class="grid gap-2 rounded-xl border border-slate-800 bg-slate-900 p-4 md:grid-cols-5">
                    <input v-model="row.code" class="rounded bg-slate-800 p-2">
                    <select v-model="row.discount_type" class="rounded bg-slate-800 p-2"><option>FIXED</option><option>PERCENT</option></select>
                    <input v-model.number="row.discount_value" type="number" min="1" class="rounded bg-slate-800 p-2">
                    <input v-model.number="row.minimum_total_idr" type="number" min="0" class="rounded bg-slate-800 p-2">
                    <label class="flex items-center gap-2 text-sm"><input v-model="row.is_active" type="checkbox">Aktif</label>
                    <button class="rounded bg-slate-700 px-3 py-2 text-sm" @click="saveVoucher(row)">Simpan</button>
                </div>
            </section>

            <section v-else-if="kind === 'support'" class="space-y-3">
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
                        <textarea v-model="row.quick_reply" rows="2" class="rounded bg-slate-800 p-2 text-sm" placeholder="Quick reply ke pelanggan (opsional)"></textarea>
                        <button class="rounded bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950" @click="updateSupport(row)">Simpan / Balas</button>
                    </div>
                </div>
            </section>

            <template v-else-if="kind === 'reports'">
                <section class="grid gap-3 md:grid-cols-3"><div v-for="(value, key) in report" :key="key" class="rounded-xl border border-slate-800 bg-slate-900 p-4"><div class="text-xs uppercase text-slate-500">{{ key.replaceAll('_', ' ') }}</div><div class="mt-2 text-2xl font-semibold">{{ key.includes('revenue') || key.includes('wallet') ? 'Rp' + Number(value).toLocaleString('id-ID') : value }}</div></div></section>
                <section class="rounded-xl border border-slate-800 bg-slate-900 p-5"><h2 class="font-semibold">Produk berhasil teratas</h2><div v-for="row in topProducts" :key="row.name" class="mt-3 flex justify-between border-t border-slate-800 pt-3 text-sm"><span>{{ row.name }}</span><span>{{ row.orders_count }} order · Rp{{ Number(row.revenue_idr).toLocaleString('id-ID') }}</span></div></section>
            </template>

            <section v-else-if="kind === 'settings'" class="space-y-5">
                <form class="grid gap-3 rounded-xl border border-slate-800 bg-slate-900 p-5 md:grid-cols-2" @submit.prevent="settingsForm.put('/admin/settings')">
                    <label class="text-sm">Nama toko<input v-model="settingsForm.store_name" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">Tagline<input v-model="settingsForm.tagline" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">WhatsApp<input v-model="settingsForm.support_whatsapp" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">Instagram URL<input v-model="settingsForm.instagram_url" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">Email<input v-model="settingsForm.email" type="email" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">Discord URL<input v-model="settingsForm.discord_url" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">Support URL<input v-model="settingsForm.support_url" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <label class="text-sm">Jam layanan<input v-model="settingsForm.business_hours" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                    <button class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950">Simpan pengaturan</button>
                </form>
                <section class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="font-semibold">Membership tiers</h2>
                    <div v-for="tier in tiers" :key="tier.code" class="grid gap-2 border-t border-slate-800 pt-3 md:grid-cols-2">
                        <div class="md:col-span-2 flex items-center justify-between"><strong>{{ tier.rank }}. {{ tier.code }}</strong><label class="flex gap-2 text-sm"><input v-model="tier.is_active" type="checkbox">Aktif</label></div>
                        <label class="text-xs">Requirements JSON<textarea v-model="tier.requirements" rows="4" class="mt-1 block w-full rounded bg-slate-800 p-2 font-mono"></textarea></label>
                        <label class="text-xs">Benefits JSON<textarea v-model="tier.benefits" rows="4" class="mt-1 block w-full rounded bg-slate-800 p-2 font-mono"></textarea></label>
                        <button class="rounded bg-slate-700 px-3 py-2 text-sm md:col-span-2" @click="saveTier(tier)">Simpan tier</button>
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
                <table class="w-full min-w-[1100px] text-xs">
                    <thead class="text-left text-slate-400"><tr><th class="p-2">Waktu</th><th class="p-2">Actor</th><th class="p-2">Aksi</th><th class="p-2">Target</th><th class="p-2">Correlation</th><th class="p-2">IP</th></tr></thead>
                    <tbody><tr v-for="row in rows" :key="row.id" class="border-t border-slate-800"><td class="p-2">{{ row.created_at }}</td><td class="p-2">{{ row.actor_role }} #{{ row.actor_id }}</td><td class="p-2">{{ row.action }}</td><td class="p-2">{{ row.target_type }} #{{ row.target_id }}</td><td class="p-2">{{ row.correlation_id }}</td><td class="p-2">{{ row.ip_address }}</td></tr></tbody>
                </table>
            </section>
        </div>
    </AdminShell>
</template>
