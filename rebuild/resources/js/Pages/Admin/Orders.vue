<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { label, num, rupiah, tone, wibDateTime, wibTime, copyText } from '../../lib/adminFormat';

const props = defineProps({
    orders: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    metrics: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    providers: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
    canCreateManual: Boolean,
    updatedAt: String,
});

const rows = computed(() => (Array.isArray(props.orders?.data) ? props.orders.data : []));
const meta = computed(() => props.orders || {});
const current = ref({ q: '', status: '', provider: '', payment: '', from: '', to: '', per_page: 25, ...(props.filters || {}) });
const advanced = ref(false);
const busy = ref(false);
const auto = ref(true);
const selected = ref([]);
const lastUpdated = ref(props.updatedAt || new Date().toISOString());

const statusOptions = computed(() => Object.entries(props.statuses || {}).map(([value, text]) => ({ value, text })));

const apply = () => {
    busy.value = true;
    const query = Object.fromEntries(Object.entries(current.value).filter(([, value]) => value !== '' && value !== null));
    router.get('/admin/orders', query, {
        preserveState: true, preserveScroll: true,
        onFinish: () => { busy.value = false; selected.value = []; },
    });
};

const reset = () => {
    current.value = { q: '', status: '', provider: '', payment: '', from: '', to: '', per_page: 25 };
    apply();
};

const reload = () => {
    router.reload({
        only: ['orders', 'metrics', 'activities', 'updatedAt'],
        preserveScroll: true,
        onSuccess: () => { lastUpdated.value = props.updatedAt || new Date().toISOString(); },
    });
};

const exportUrl = computed(() => {
    const params = new URLSearchParams(Object.entries(current.value).filter(([, value]) => value !== '' && value !== null));
    selected.value.forEach(id => params.append('selected[]', id));
    const query = params.toString();
    return `/admin/orders/export${query ? '?' + query : ''}`;
});

const hasFilters = computed(() => Object.entries(current.value).some(([key, value]) => key !== 'per_page' && value !== '' && value !== null));
const markAll = () => { selected.value = allSelected.value ? [] : rows.value.map(row => row.id); };

let timer;
onMounted(() => { timer = setInterval(() => { if (auto.value && !busy.value) reload(); }, 30000); });
onUnmounted(() => clearInterval(timer));

const allSelected = computed(() => rows.value.length > 0 && selected.value.length === rows.value.length);

const copied = ref('');
const copy = async (text) => {
    if (!await copyText(text)) return;
    copied.value = text;
    setTimeout(() => { if (copied.value === text) copied.value = ''; }, 2000);
};

const manualOpen = ref(false);
const manual = useForm({
    customer_name: '', phone: '', email: '', product_name: '', package_name: '',
    destination: '', total_idr: '', note: '', payment_received: false, idempotency_key: '',
});
const openManual = () => {
    manual.reset();
    manual.idempotency_key = crypto.randomUUID();
    manualOpen.value = true;
};
const submitManual = () => {
    manual.post('/admin/orders/manual', {
        preserveScroll: true,
        onSuccess: () => { manualOpen.value = false; manual.reset(); },
    });
};
</script>

<template>
    <Head title="Pesanan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Pesanan</h1>
                <p>Pesanan yang perlu perhatian tampil paling atas, lalu pesanan terbaru. Tanggal memakai WIB.</p>
            </div>
            <div class="adm-head-actions">
                <button v-if="canCreateManual" type="button" class="adm-btn primary" @click="openManual">Catat pesanan manual</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Angka pesanan">
            <div v-for="metric in metrics" :key="metric.label" class="adm-stat">
                <span class="lb">{{ metric.label }}</span>
                <strong class="vl">{{ num(metric.value) }}</strong>
            </div>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari</span>
                    <input v-model="current.q" class="adm-input" placeholder="Nomor pesanan, pelanggan, produk, atau tujuan" maxlength="150">
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="current.status" class="adm-select">
                        <option value="">Semua status</option>
                        <option value="attention">Perlu perhatian</option>
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.text }}</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn" :disabled="busy">Terapkan</button> <button type="button" class="adm-btn" :disabled="busy || !hasFilters" @click="reset">Hapus filter</button>
                </div>

            </form>

            <div v-if="advanced" class="adm-filters">
                <label class="adm-field narrow">
                    <span>Penyedia</span>
                    <select v-model="current.provider" class="adm-select">
                        <option value="">Semua penyedia</option>
                        <option v-for="provider in providers" :key="provider" :value="provider">{{ provider === 'MANUAL' ? 'Penanganan manual' : provider }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Metode pembayaran</span>
                    <select v-model="current.payment" class="adm-select">
                        <option value="">Semua metode</option>
                        <option v-for="channel in channels" :key="channel.code" :value="channel.code">{{ channel.name }}</option>
                        <option value="WALLET">Saldo akun</option>
                        <option value="ADMIN_MANUAL">Pembayaran dicatat admin</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Dari tanggal (WIB)</span>
                    <input v-model="current.from" type="date" class="adm-input">
                </label>
                <label class="adm-field narrow">
                    <span>Sampai tanggal (WIB)</span>
                    <input v-model="current.to" type="date" class="adm-input" :min="current.from || undefined">
                </label>
                <label class="adm-field narrow">
                    <span>Pesanan per halaman</span>
                    <select v-model="current.per_page" class="adm-select">
                        <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} pesanan</option>
                    </select>
                </label>
            </div>

            <div class="adm-toolbar">
                <span>Terakhir diperbarui {{ wibTime(lastUpdated) }}</span>
                <label class="adm-switch-row"><button type="button" class="adm-switch" role="switch" :aria-checked="auto" aria-label="Perbarui otomatis" @click="auto = !auto" /><span>Otomatis 30 detik</span></label>
                <button type="button" class="adm-btn sm" :disabled="busy" @click="reload">Muat ulang</button>
                <button type="button" class="adm-btn sm" @click="advanced = !advanced">{{ advanced ? 'Sembunyikan filter lanjutan' : 'Filter lanjutan' }}</button>
                <span class="sp" />
                <span v-if="selected.length" class="adm-tag ok">{{ selected.length }} dipilih</span>
                <a class="adm-btn sm" :href="exportUrl">{{ selected.length ? 'Unduh yang dipilih' : 'Unduh hasil filter' }}</a>
            </div>

            <p class="adm-hint-sm">Geser tabel ke samping untuk melihat semua kolom.</p>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th style="width:34px"><input type="checkbox" :checked="allSelected" :disabled="!rows.length" aria-label="Pilih semua pesanan di halaman ini" @change="markAll"></th>
                            <th>Pesanan</th>
                            <th>Pelanggan</th>
                            <th>Produk &amp; tujuan</th>
                            <th>Pembayaran</th>
                            <th>Status Pembayaran</th>
                            <th>Status Proses</th>
                            <th>Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" :class="{ attn: row.needs_attention }">
                            <td><input v-model="selected" type="checkbox" :value="row.id" :aria-label="'Pilih ' + row.order_number"></td>
                            <td>
                                <Link :href="`/admin/orders/${row.id}`" class="mono">{{ row.order_number }}</Link>
                                <p class="dim">{{ wibDateTime(row.created_at) }}</p>
                                <button type="button" class="adm-btn link" @click="copy(row.order_number)">{{ copied === row.order_number ? 'Tersalin' : 'Salin nomor' }}</button>
                            </td>
                            <td>
                                {{ row.buyer_name || 'Pelanggan tamu' }}
                                <p class="dim">{{ row.buyer_phone || row.buyer_email || 'Tanpa kontak' }}</p>
                            </td>
                            <td>
                                {{ row.product_name }}
                                <p class="dim">{{ row.package_name }}</p>
                                <p v-for="item in row.destinations" :key="item.label" class="dim">{{ item.label }}: {{ item.value }}</p>
                            </td>
                            <td>
                                {{ rupiah(row.total_idr) }}
                                <p class="dim">{{ row.payment_method }}</p>
                            </td>
                            <td><span class="adm-tag" :class="tone(row.status)">{{ row.status_label || label(row.status) }}</span></td>
                            <td>
                                <span class="adm-tag" :class="tone(row.fulfillment_status || '')">{{ row.fulfillment_label || 'Belum dikirim' }}</span>
                                <p v-if="row.needs_attention" class="dim" style="color:var(--a-danger)">Perlu perhatian</p>
                                <p class="dim">{{ row.provider }}</p>
                            </td>
                            <td>
                                <Link :href="`/admin/orders/${row.id}`" class="adm-btn sm">Lihat</Link>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="adm-empty">Tidak ada pesanan yang cocok dengan filter.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="adm-pager">
                <span>{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} dari {{ num(meta.total ?? 0) }} pesanan · Halaman {{ meta.current_page ?? 1 }} dari {{ meta.last_page ?? 1 }}</span>
                <span class="sp" />
                <Link v-if="meta.prev_page_url" :href="meta.prev_page_url" class="adm-btn sm" preserve-scroll>Sebelumnya</Link>
                <button v-else type="button" class="adm-btn sm" disabled>Sebelumnya</button>
                <Link v-if="meta.next_page_url" :href="meta.next_page_url" class="adm-btn sm" preserve-scroll>Berikutnya</Link>
                <button v-else type="button" class="adm-btn sm" disabled>Berikutnya</button>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><h2>Aktivitas pesanan terbaru</h2></div>
            <div class="adm-card-body">
                <div v-if="activities.length" class="adm-list">
                    <div v-for="row in activities" :key="row.id" class="adm-list-row">
                        <strong>{{ row.label || row.event_type }}</strong>
                        <span>{{ row.order_number }} · {{ wibDateTime(row.created_at) }}</span>
                    </div>
                </div>
                <p v-else class="adm-empty">Belum ada aktivitas pesanan.</p>
            </div>
        </section>

        <AdminModal v-model:open="manualOpen" title="Catat pesanan manual" description="Pesanan dicatat setelah pembayaran benar-benar diterima.">
            <form id="manual-order" class="adm-grid two" @submit.prevent="submitManual">
                <label class="adm-field"><span>Nama pelanggan <b class="req">*</b></span><input v-model="manual.customer_name" class="adm-input" maxlength="120" required><span v-if="manual.errors.customer_name" class="err">{{ manual.errors.customer_name }}</span></label>
                <label class="adm-field"><span>Nomor telepon/WhatsApp <b class="req">*</b></span><input v-model="manual.phone" class="adm-input" maxlength="32" required><span v-if="manual.errors.phone" class="err">{{ manual.errors.phone }}</span></label>
                <label class="adm-field"><span>Email</span><input v-model="manual.email" type="email" class="adm-input" maxlength="255"><span v-if="manual.errors.email" class="err">{{ manual.errors.email }}</span></label>
                <label class="adm-field"><span>Produk <b class="req">*</b></span><input v-model="manual.product_name" class="adm-input" maxlength="120" required><span v-if="manual.errors.product_name" class="err">{{ manual.errors.product_name }}</span></label>
                <label class="adm-field"><span>Paket / nominal <b class="req">*</b></span><input v-model="manual.package_name" class="adm-input" maxlength="120" required><span v-if="manual.errors.package_name" class="err">{{ manual.errors.package_name }}</span></label>
                <label class="adm-field"><span>Tujuan <b class="req">*</b></span><input v-model="manual.destination" class="adm-input" maxlength="300" required><span v-if="manual.errors.destination" class="err">{{ manual.errors.destination }}</span></label>
                <label class="adm-field"><span>Total pembayaran <b class="req">*</b></span><input v-model="manual.total_idr" type="number" min="1" class="adm-input" required><span v-if="manual.errors.total_idr" class="err">{{ manual.errors.total_idr }}</span></label>
                <label class="adm-field full"><span>Catatan</span><textarea v-model="manual.note" class="adm-textarea" maxlength="1000" /><span v-if="manual.errors.note" class="err">{{ manual.errors.note }}</span></label>
                <label class="adm-switch-row full">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="manual.payment_received" @click="manual.payment_received = !manual.payment_received" />
                    Pembayaran sudah diterima
                </label>
                <span v-if="manual.errors.payment_received" class="err full">{{ manual.errors.payment_received }}</span>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="manualOpen = false">Batal</button>
                <button type="submit" form="manual-order" class="adm-btn primary" :disabled="manual.processing || !manual.payment_received">Simpan pesanan</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
