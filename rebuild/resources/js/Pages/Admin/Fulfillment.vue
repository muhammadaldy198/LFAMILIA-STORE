<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { label, num, rupiah, tone, wibDateTime, wibTime, copyText } from '../../lib/adminFormat';

const props = defineProps({
    attempts: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    metrics: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    updatedAt: String,
    manualOverdueMinutes: { type: Number, default: 60 },
});

const rows = computed(() => (Array.isArray(props.attempts?.data) ? props.attempts.data : []));
const meta = computed(() => props.attempts || {});
const current = reactive({ q: '', scope: 'action', status: '', per_page: 25, ...(props.filters || {}) });
const busy = ref(false);
const auto = ref(true);
const lastUpdated = ref(props.updatedAt || new Date().toISOString());

const apply = () => {
    busy.value = true;
    router.get('/admin/fulfillment', { ...current }, {
        preserveState: true, preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
const reset = () => { Object.assign(current, { q: '', scope: 'action', status: '', per_page: 25 }); apply(); };
const reload = () => router.reload({ only: ['attempts', 'metrics', 'updatedAt'], preserveScroll: true, onSuccess: () => { lastUpdated.value = props.updatedAt || new Date().toISOString(); } });

let timer;
onMounted(() => { timer = setInterval(() => { if (auto.value && !busy.value && !modal.value) reload(); }, 30000); });
onUnmounted(() => clearInterval(timer));

const copied = ref('');
const copy = async (text) => {
    if (!await copyText(text)) return;
    copied.value = text;
    setTimeout(() => { if (copied.value === text) copied.value = ''; }, 2000);
};

const modal = ref('');
const selected = ref(null);
const form = reactive({
    delivery_code: '', note: '', reason: '', provider_ref_id: '', provider_status: 'Gagal',
    provider_rc: '02', evidence_reference: '', confirmed_customer_no: '', acknowledge: false,
});
const openModal = (kind, row) => {
    selected.value = row;
    modal.value = kind;
    form.delivery_code = '';
    form.note = '';
    form.reason = '';
    form.provider_ref_id = row.process_reference || '';
    form.provider_status = 'Gagal';
    form.provider_rc = '02';
    form.evidence_reference = '';
    form.confirmed_customer_no = '';
    form.acknowledge = false;
};
const run = (action) => {
    const row = selected.value;
    if (!row) return;
    const url = `/admin/fulfillment/${row.id}/${action}`;
    const body = action === 'complete'
        ? { delivery_code: form.delivery_code, note: form.note }
        : action === 'fail' ? { reason: form.reason }
            : action === 'resolve-quarantine' ? {
                provider_ref_id: form.provider_ref_id,
                provider_status: form.provider_status,
                provider_rc: form.provider_rc,
                evidence_reference: form.evidence_reference,
                note: form.note,
                confirmed_customer_no: form.confirmed_customer_no,
            } : {};
    busy.value = true;
    router.post(url, body, {
        preserveScroll: true,
        onSuccess: () => { modal.value = ''; },
        onFinish: () => { busy.value = false; },
    });
};

const rowStatus = row => row.status_label || label(row.status);

// Tindakan antrean tanpa isian (periksa status & proses ulang) langsung dikirim.
const runOn = (action, row) => {
    busy.value = true;
    router.post(`/admin/fulfillment/${row.id}/${action}`, {}, {
        preserveScroll: true, onFinish: () => { busy.value = false; },
    });
};
</script>

<template>
    <Head title="Antrean Pesanan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Antrean Pesanan</h1>
                <p>Setiap baris adalah proses pengiriman terakhir untuk satu pesanan.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/orders" class="adm-btn">Buka Pesanan</Link>
            </div>
        </header>

        <section class="adm-stats" aria-label="Angka antrean">
            <div v-for="metric in metrics" :key="metric.label" class="adm-stat">
                <span class="lb">{{ metric.label }}</span>
                <strong class="vl">{{ num(metric.value) }}</strong>
            </div>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari antrean</span>
                    <input v-model="current.q" class="adm-input" placeholder="Nomor pesanan, pelanggan, produk, atau tujuan" maxlength="150">
                </label>
                <label class="adm-field narrow">
                    <span>Cakupan antrean</span>
                    <select v-model="current.scope" class="adm-select">
                        <option value="action">Hanya yang perlu ditangani</option>
                        <option value="manual">Hanya penanganan manual</option>
                        <option value="all">Semua proses terbaru</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="current.status" class="adm-select">
                        <option value="">Semua status</option>
                        <option v-for="(text, key) in statuses" :key="key" :value="key">{{ text }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Baris per halaman</span>
                    <select v-model="current.per_page" class="adm-select">
                        <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} proses</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary" :disabled="busy">Terapkan filter</button> <button type="button" class="adm-btn" :disabled="busy" @click="reset">Hapus filter</button>
                </div>

            </form>

            <div class="adm-toolbar">
                <span>Terakhir diperbarui: {{ wibTime(lastUpdated) }}</span>
                <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="auto" aria-label="Perbarui otomatis" @click="auto = !auto" />Perbarui otomatis setiap 30 detik</label>
                <button type="button" class="adm-btn sm sp" :disabled="busy" @click="reload">Muat ulang</button>
            </div>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr><th>Pesanan</th><th>Pelanggan</th><th>Produk &amp; tujuan</th><th>Sumber</th><th>Status</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>
                                <Link :href="`/admin/orders/${row.order_id}`" class="mono">{{ row.order_number }}</Link>
                                <p class="dim">{{ wibDateTime(row.created_at) }}</p>
                                <span class="adm-tag plain">Ke-{{ row.attempt_no }}</span>
                                <button type="button" class="adm-btn link" @click="copy(row.order_number)">{{ copied === row.order_number ? 'Tersalin' : 'Salin' }}</button>
                            </td>
                            <td>{{ row.buyer_name }}<p class="dim">{{ row.buyer_phone || row.buyer_email || 'Tanpa kontak' }}</p></td>
                            <td>
                                {{ row.product_name }}
                                <p class="dim">{{ row.package_name }}</p>
                                <p v-for="item in row.destinations" :key="item.label" class="dim">{{ item.label }}: {{ item.value }}</p>
                            </td>
                            <td>
                                {{ row.source }}
                                <p v-if="row.price_idr != null" class="dim">Biaya {{ rupiah(row.price_idr) }}</p>
                            </td>
                            <td>
                                <span class="adm-tag" :class="tone(row.status)">{{ rowStatus(row) }}</span>
                                <span v-if="row.is_overdue" class="adm-tag danger">Lewat {{ manualOverdueMinutes }} menit</span>
                                <p class="dim">Pesanan: {{ row.order_status_label }}</p>
                                <p v-if="row.manual_age_minutes" class="dim">Menunggu {{ row.manual_age_minutes }} menit jam layanan</p>
                                <p v-if="row.note" class="dim">{{ row.note }}</p>
                            </td>
                            <td>
                                <button v-if="row.can_complete" type="button" class="adm-btn sm" @click="openModal('complete', row)">Tangani</button>
                                <button v-if="row.can_reconcile" type="button" class="adm-btn sm" :disabled="busy" @click="runOn('reconcile', row)">Periksa status</button>
                                <button v-if="row.can_retry" type="button" class="adm-btn sm" :disabled="busy" @click="runOn('retry', row)">Proses ulang</button>
                                <button v-if="row.can_resolve_quarantine" type="button" class="adm-btn sm" :disabled="busy" @click="openModal('resolve-quarantine', row)">Resolusi karantina</button>
                                <button v-if="row.can_fail" type="button" class="adm-btn sm danger" @click="openModal('fail', row)">Gagalkan</button>
                                <Link :href="`/admin/orders/${row.order_id}`" class="adm-btn sm">Lihat</Link>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="6" class="adm-empty">Tidak ada proses yang cocok dengan filter.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="adm-pager">
                <span>{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} dari {{ num(meta.total ?? 0) }} proses · Halaman {{ meta.current_page ?? 1 }} dari {{ meta.last_page ?? 1 }}</span>
                <span class="sp" />
                <Link v-if="meta.prev_page_url" :href="meta.prev_page_url" class="adm-btn sm" preserve-scroll>Sebelumnya</Link>
                <button v-else type="button" class="adm-btn sm" disabled>Sebelumnya</button>
                <Link v-if="meta.next_page_url" :href="meta.next_page_url" class="adm-btn sm" preserve-scroll>Berikutnya</Link>
                <button v-else type="button" class="adm-btn sm" disabled>Berikutnya</button>
            </div>
        </section>

        <AdminModal v-model:open="modal" :title="modal === 'resolve-quarantine' ? 'Resolusi karantina Digiflazz' : modal === 'fail' ? 'Nyatakan proses gagal' : 'Selesaikan penanganan manual'" :description="selected ? `${selected.order_number} · penanganan ke-${selected.attempt_no}` : ''">
            <div v-if="modal === 'complete'" class="adm-grid">
                <label class="adm-field"><span>Hasil / kode pengiriman</span><textarea v-model="form.delivery_code" class="adm-textarea" maxlength="2000" /></label>
                <label class="adm-field"><span>Catatan</span><textarea v-model="form.note" class="adm-textarea" maxlength="4000" /></label>
                <p v-if="selected?.manual_instructions" class="adm-note full">{{ selected.manual_instructions }}</p>
            </div>
            <div v-else-if="modal === 'resolve-quarantine'" class="adm-grid">
                <p class="adm-note full">Hanya untuk transaksi lama yang terbukti GAGAL di riwayat Digiflazz. Attempt karantina tetap BLOCKED. Pengiriman baru menggunakan referensi baru dan SKU yang sama. Jangan lanjutkan jika hasil masih Pending, Sukses, atau tidak pasti.</p>
                <label class="adm-field"><span>Ref ID transaksi lama (harus persis)</span><input v-model="form.provider_ref_id" class="adm-input mono" maxlength="120" readonly></label>
                <label class="adm-field"><span>Status yang terverifikasi</span><input v-model="form.provider_status" class="adm-input" readonly></label>
                <label class="adm-field"><span>Response code Digiflazz <b class="req">*</b></span><input v-model="form.provider_rc" class="adm-input mono" maxlength="2" placeholder="02"></label>
                <label class="adm-field full"><span>Referensi bukti (ID riwayat atau lokasi tangkapan layar) <b class="req">*</b></span><input v-model="form.evidence_reference" class="adm-input" maxlength="500" placeholder="Catat bukti status gagal dari riwayat Digiflazz"></label>
                <p class="adm-note full">Nomor tujuan dari snapshot pesanan: <strong class="mono">{{ selected?.resolution_destination || 'Format tujuan tidak tersedia' }}</strong>. Periksa kecocokannya dengan akun pembeli sebelum membuat attempt baru.</p>
                <label class="adm-field full"><span>Ketik ulang nomor tujuan persis untuk konfirmasi <b class="req">*</b></span><input v-model="form.confirmed_customer_no" class="adm-input mono" maxlength="100" autocomplete="off"></label>
                <label class="adm-field full"><span>Catatan verifikasi admin <b class="req">*</b></span><textarea v-model="form.note" class="adm-textarea" maxlength="2000" placeholder="Jelaskan sumber pemeriksaan dan mengapa transaksi lama sudah pasti gagal" /></label>
                <label class="adm-field full"><input v-model="form.acknowledge" type="checkbox"> Saya memastikan bukti ini untuk ref ID yang sama, status final Gagal, pembayaran sudah diterima, dan memahami transaksi baru berpotensi mengirim saldo/game item.</label>
            </div>
            <div v-else class="adm-grid">
                <label class="adm-field"><span>Alasan <b class="req">*</b></span><textarea v-model="form.reason" class="adm-textarea" maxlength="4000" /></label>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="modal = ''">Batal</button>
                <button v-if="modal === 'fail'" type="button" class="adm-btn danger" :disabled="busy || !form.reason.trim()" @click="run('fail')">Nyatakan gagal</button>
                <button v-else-if="modal === 'resolve-quarantine'" type="button" class="adm-btn primary" :disabled="busy || !form.acknowledge || !form.evidence_reference.trim() || !form.note.trim() || !form.provider_rc.trim() || !form.confirmed_customer_no.trim() || form.confirmed_customer_no !== selected?.resolution_destination" @click="run('resolve-quarantine')">Buat attempt Production baru</button>
                <button v-else type="button" class="adm-btn primary" :disabled="busy || (!form.delivery_code.trim() && !form.note.trim())" @click="run('complete')">Tandai berhasil</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
