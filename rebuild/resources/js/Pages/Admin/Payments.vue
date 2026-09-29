<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { reactive, ref } from 'vue';

const props = defineProps({
    canConfigure: Boolean,
    gateways: Array,
    channels: Array,
    routes: Array,
    minimumTopupIdr: Number,
    manualQrisAsset: Object,
    manualPayments: Array,
});

const gateways = reactive(props.gateways.map((item) => ({ ...item })));
const channels = reactive(props.channels.map((item) => ({ ...item })));
const routes = reactive(props.routes.map((item) => ({
    ...item,
    configuration_text: item.configuration
        ? (typeof item.configuration === 'string' ? item.configuration : JSON.stringify(item.configuration, null, 2))
        : '',
})));
const minimumTopupIdr = ref(props.minimumTopupIdr);
const routeForm = reactive({
    payment_channel_id: '',
    payment_gateway_id: '',
    provider_channel: '',
    configuration: '',
    priority: 0,
    is_active: false,
});

function saveGateway(item) {
    router.put('/admin/payments/gateways/' + item.id, {
        is_active: item.is_active,
        is_maintenance: item.is_maintenance,
    }, { preserveScroll: true });
}

function saveChannel(item) {
    router.put('/admin/payments/channels/' + item.id, {
        name: item.name,
        fee_flat_idr: Number(item.fee_flat_idr || 0),
        fee_percent_bps: Number(item.fee_percent_bps || 0),
        supports_order: Boolean(item.supports_order),
        supports_wallet_topup: Boolean(item.supports_wallet_topup),
        sort_order: Number(item.sort_order || 0),
        is_active: Boolean(item.is_active),
    }, { preserveScroll: true });
}

function createRoute() {
    router.post('/admin/payments/routes', { ...routeForm }, { preserveScroll: true });
}

function saveRoute(item) {
    router.put('/admin/payments/routes/' + item.id, {
        provider_channel: item.provider_channel || null,
        configuration: item.configuration_text || null,
        priority: Number(item.priority || 0),
        is_active: Boolean(item.is_active),
    }, { preserveScroll: true });
}

function saveSettings() {
    router.put('/admin/payments/settings', {
        minimum_topup_idr: Number(minimumTopupIdr.value),
    }, { preserveScroll: true });
}

function confirmManual(id) {
    router.post('/admin/payments/manual/' + id + '/confirm', {}, { preserveScroll: true });
}

function uploadManualQris(event) {
    const file = event.target.files?.[0];
    if (!file || !props.manualQrisAsset) return;
    router.post('/admin/catalog/media/asset/' + props.manualQrisAsset.id, {
        image: file,
        collection: 'image',
    }, { forceFormData: true, preserveScroll: true });
}

function toggleManualAsset() {
    if (!props.manualQrisAsset) return;
    router.put('/admin/catalog/assets/' + props.manualQrisAsset.id, {
        is_active: !props.manualQrisAsset.is_active,
        target_url: null,
    }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Pembayaran" />
    <AdminShell>
        <div class="mx-auto max-w-7xl space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-semibold">Pembayaran</h1>
                    <p class="mt-1 text-sm text-slate-400">Customer hanya melihat channel. Routing gateway tetap internal.</p>
                </div>
                <Link href="/admin/panel" class="rounded-lg bg-slate-800 px-4 py-2 text-sm">Dashboard</Link>
            </div>

            <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">QRIS Manual</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <img v-if="manualQrisAsset?.image_url" :src="manualQrisAsset.image_url" alt="QRIS manual" class="max-h-64 rounded-lg bg-white p-2">
                        <p v-else class="text-sm text-amber-200">Gambar QRIS belum diunggah.</p>
                    </div>
                    <div v-if="canConfigure" class="space-y-3">
                        <input type="file" accept="image/png,image/jpeg,image/webp" @change="uploadManualQris">
                        <button type="button" class="rounded-lg bg-slate-700 px-4 py-2 text-sm" @click="toggleManualAsset">
                            {{ manualQrisAsset?.is_active ? 'Nonaktifkan QRIS' : 'Aktifkan QRIS' }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Menunggu konfirmasi manual</h2>
                <p v-if="!manualPayments.length" class="mt-3 text-sm text-slate-400">Tidak ada pembayaran QRIS manual yang menunggu.</p>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[650px] text-sm">
                        <thead class="text-left text-slate-400"><tr><th class="p-2">Order</th><th class="p-2">Nominal</th><th class="p-2">Status</th><th class="p-2">Dibuat</th><th class="p-2">Aksi</th></tr></thead>
                        <tbody>
                            <tr v-for="payment in manualPayments" :key="payment.id" class="border-t border-slate-800">
                                <td class="p-2">{{ payment.order_number }}</td>
                                <td class="p-2">Rp{{ Number(payment.amount_idr).toLocaleString('id-ID') }}</td>
                                <td class="p-2">{{ payment.status }}</td>
                                <td class="p-2">{{ payment.created_at }}</td>
                                <td class="p-2"><button class="rounded bg-emerald-300 px-3 py-2 font-semibold text-slate-950" @click="confirmManual(payment.id)">Konfirmasi dibayar</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <template v-if="canConfigure">
                <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-xl font-semibold">Gateway internal</h2>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div v-for="gateway in gateways" :key="gateway.id" class="rounded-lg border border-slate-700 p-4">
                            <div class="flex items-center justify-between"><strong>{{ gateway.internal_name }}</strong><span class="text-xs text-slate-500">{{ gateway.kind }}</span></div>
                            <p v-if="!gateway.credential_configured" class="mt-2 text-xs text-amber-200">Credential belum aktif di Integrasi.</p>
                            <div class="mt-3 flex gap-4 text-sm">
                                <label><input v-model="gateway.is_active" type="checkbox"> Aktif</label>
                                <label><input v-model="gateway.is_maintenance" type="checkbox"> Maintenance</label>
                            </div>
                            <button class="mt-3 rounded bg-slate-700 px-3 py-2 text-sm" @click="saveGateway(gateway)">Simpan</button>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-xl font-semibold">Channel & biaya</h2>
                    <div class="mt-4 space-y-3">
                        <div v-for="channel in channels" :key="channel.id" class="grid gap-2 rounded-lg border border-slate-700 p-3 md:grid-cols-7">
                            <input v-model="channel.name" class="rounded bg-slate-800 p-2 md:col-span-2">
                            <label class="text-xs">Fee tetap<input v-model.number="channel.fee_flat_idr" type="number" min="0" class="mt-1 w-full rounded bg-slate-800 p-2"></label>
                            <label class="text-xs">Fee bps<input v-model.number="channel.fee_percent_bps" type="number" min="0" max="10000" class="mt-1 w-full rounded bg-slate-800 p-2"></label>
                            <label class="text-xs"><input v-model="channel.supports_order" type="checkbox"> Order</label>
                            <label class="text-xs"><input v-model="channel.supports_wallet_topup" type="checkbox"> Top up</label>
                            <div class="flex items-center gap-2"><label class="text-xs"><input v-model="channel.is_active" type="checkbox"> Aktif</label><button class="rounded bg-slate-700 px-3 py-2 text-xs" @click="saveChannel(channel)">Simpan</button></div>
                        </div>
                    </div>
                </section>

                <section class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-xl font-semibold">Routing channel → gateway</h2>
                    <div class="grid gap-2 md:grid-cols-6">
                        <select v-model="routeForm.payment_channel_id" class="rounded bg-slate-800 p-2"><option value="">Channel</option><option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                        <select v-model="routeForm.payment_gateway_id" class="rounded bg-slate-800 p-2"><option value="">Gateway</option><option v-for="g in gateways" :key="g.id" :value="g.id">{{ g.internal_name }}</option></select>
                        <input v-model="routeForm.provider_channel" placeholder="Provider channel" class="rounded bg-slate-800 p-2">
                        <input v-model.number="routeForm.priority" type="number" min="0" placeholder="Prioritas" class="rounded bg-slate-800 p-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="routeForm.is_active" type="checkbox"> Aktif</label>
                        <button class="rounded bg-cyan-300 px-3 py-2 font-semibold text-slate-950" @click="createRoute">Tambah route</button>
                        <textarea v-model="routeForm.configuration" rows="3" placeholder='Configuration JSON tanpa credential' class="rounded bg-slate-800 p-2 md:col-span-6"></textarea>
                    </div>
                    <div v-for="route in routes" :key="route.id" class="rounded-lg border border-slate-700 p-3">
                        <div class="flex flex-wrap items-center gap-3 text-sm"><strong>{{ route.channel_code }}</strong><span>→</span><strong>{{ route.gateway_code }}</strong><input v-model="route.provider_channel" placeholder="provider channel" class="rounded bg-slate-800 p-2"><input v-model.number="route.priority" type="number" min="0" class="w-20 rounded bg-slate-800 p-2"><label><input v-model="route.is_active" type="checkbox"> Aktif</label><button class="rounded bg-slate-700 px-3 py-2" @click="saveRoute(route)">Simpan</button></div>
                        <textarea v-model="route.configuration_text" rows="4" class="mt-2 w-full rounded bg-slate-800 p-2 font-mono text-xs" placeholder="Configuration JSON"></textarea>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-xl font-semibold">Wallet</h2>
                    <label class="mt-3 block max-w-sm text-sm">Minimum top up
                        <input v-model.number="minimumTopupIdr" type="number" min="1" class="mt-1 block w-full rounded bg-slate-800 p-2">
                    </label>
                    <button class="mt-3 rounded bg-slate-700 px-4 py-2 text-sm" @click="saveSettings">Simpan</button>
                </section>
            </template>
        </div>
    </AdminShell>
</template>
