<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({ attempts: Array });
const forms = reactive({});

function form(id) {
    if (!forms[id]) forms[id] = { delivery_code: '', note: '', reason: '' };
    return forms[id];
}

function completeManual(id) {
    router.post('/admin/fulfillment/' + id + '/complete', {
        delivery_code: form(id).delivery_code || null,
        note: form(id).note || null,
    }, { preserveScroll: true });
}

function failManual(id) {
    router.post('/admin/fulfillment/' + id + '/fail', {
        reason: form(id).reason,
    }, { preserveScroll: true });
}

function retry(id) {
    router.post('/admin/fulfillment/' + id + '/retry', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Fulfillment" />
    <main class="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 md:px-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <div>
                <Link href="/admin/panel" class="text-sm text-cyan-300">← Panel Admin</Link>
                <h1 class="mt-2 text-3xl font-semibold">Fulfillment</h1>
                <p class="mt-1 text-sm text-slate-400">Pending/unknown tidak boleh dipindah ke provider lain sebelum reconciliation memastikan transaksi sebelumnya gagal.</p>
            </div>

            <p v-if="!attempts.length" class="rounded-xl border border-slate-800 bg-slate-900 p-5 text-slate-400">Belum ada fulfillment attempt.</p>

            <section v-for="attempt in attempts" :key="attempt.id" class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <strong>{{ attempt.order_number }} · {{ attempt.product_name }} · {{ attempt.package_name }}</strong>
                        <p class="text-sm text-slate-400">Attempt #{{ attempt.attempt_no }} · {{ attempt.provider_code }} · {{ attempt.status }}</p>
                    </div>
                    <span class="rounded bg-slate-800 px-2 py-1 text-xs">{{ attempt.order_status }}</span>
                </div>

                <div class="grid gap-3 text-sm md:grid-cols-3">
                    <div><span class="text-slate-500">Reference</span><div class="break-all">{{ attempt.external_reference }}</div></div>
                    <div><span class="text-slate-500">Provider status</span><div>{{ attempt.provider_status || '-' }} <span v-if="attempt.provider_rc">({{ attempt.provider_rc }})</span></div></div>
                    <div><span class="text-slate-500">Harga provider</span><div>{{ attempt.price_idr ? 'Rp' + Number(attempt.price_idr).toLocaleString('id-ID') : '-' }}</div></div>
                </div>

                <div class="rounded-lg bg-slate-950 p-3 text-sm">
                    <strong>Input customer</strong>
                    <div v-for="(value, key) in attempt.customer_input" :key="key" class="mt-1"><span class="text-slate-500">{{ key }}:</span> {{ value }}</div>
                </div>

                <p v-if="attempt.last_error" class="text-sm text-amber-200">{{ attempt.last_error }}</p>

                <div v-if="attempt.status === 'MANUAL_PENDING'" class="space-y-3 border-t border-slate-800 pt-4">
                    <p v-if="attempt.manual_instructions" class="whitespace-pre-wrap text-sm text-slate-300">{{ attempt.manual_instructions }}</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="text-sm">Kode/hasil untuk customer<textarea v-model="form(attempt.id).delivery_code" rows="2" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                        <label class="text-sm">Catatan customer<textarea v-model="form(attempt.id).note" rows="2" class="mt-1 block w-full rounded bg-slate-800 p-2" /></label>
                    </div>
                    <button class="rounded bg-emerald-300 px-4 py-2 font-semibold text-slate-950" @click="completeManual(attempt.id)">Tandai berhasil</button>
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="min-w-64 flex-1 text-sm">Alasan gagal<input v-model="form(attempt.id).reason" class="mt-1 block w-full rounded bg-slate-800 p-2"></label>
                        <button :disabled="!form(attempt.id).reason" class="rounded bg-red-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50" @click="failManual(attempt.id)">Tandai gagal</button>
                    </div>
                </div>

                <button
                    v-if="attempt.status === 'BLOCKED' || (attempt.status === 'FAILED_CONFIRMED' && attempt.safe_to_failover)"
                    class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950"
                    @click="retry(attempt.id)"
                >
                    {{ attempt.status === 'BLOCKED' ? 'Coba lagi dengan aman' : 'Cari mapping failover aman' }}
                </button>

                <p v-if="['PENDING', 'UNKNOWN', 'SENDING'].includes(attempt.status)" class="text-sm text-amber-200">
                    Terkunci untuk reconciliation dengan reference yang sama. Failover tidak diizinkan.
                </p>
            </section>
        </div>
    </main>
</template>
