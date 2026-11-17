<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { num, rupiah, wibDateTime } from '../../lib/adminFormat';
import { walletAdjustmentError } from '../../lib/adminWalletAdjustment';
import { withSensitive } from '../../lib/sensitivePrompt';

const props = defineProps({
    isSuperAdmin: Boolean,
    customers: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    cleanupSettings: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const tab = ref('list');
const rows = computed(() => (Array.isArray(props.customers?.data) ? props.customers.data : []));

const filter = reactive({
    q: props.filters?.q || '', verification: props.filters?.verification || '',
    activity: props.filters?.activity || '', orders: props.filters?.orders || '',
    per_page: Number(props.filters?.per_page || 25),
});
const advanced = ref(false);
const apply = () => {
    router.get('/admin/customers', Object.fromEntries(
        Object.entries(filter).filter(([, value]) => value !== '' && value !== null)
    ), { preserveState: true, preserveScroll: true });
};
const reset = () => {
    Object.assign(filter, { q: '', verification: '', activity: '', orders: '', per_page: 25 });
    apply();
};

// ---------- atur pelanggan ----------
const editor = ref(null);
const busy = ref(false);
const errors = ref({});
const openEditor = row => {
    editor.value = {
        row,
        amount_idr: '',
        reason: '',
        idempotency_key: crypto.randomUUID(),
    };
    errors.value = {};
};
const saveBalance = () => {
    if (!editor.value) return;
    const problem = walletAdjustmentError(editor.value.amount_idr, editor.value.reason);
    errors.value = problem ? { amount_idr: problem } : {};
    if (problem) return;
    const amount = Number(editor.value.amount_idr || 0);
    const direction = amount > 0 ? 'menambah' : 'mengurangi';
    // Route saldo dijaga konfirmasi sandi; POST harus dikirim setelah sandi terbukti.
    void withSensitive(`Konfirmasi ${direction} saldo ${editor.value.row.name} sebesar ${rupiah(Math.abs(amount))}? Alasan: ${editor.value.reason}`, () => {
        busy.value = true;
        router.post('/admin/customers/' + editor.value.row.id + '/wallet', {
            amount_idr: amount,
            reason: editor.value.reason,
            idempotency_key: editor.value.idempotency_key,
        }, {
            preserveScroll: true,
            onError: bag => { errors.value = bag; },
            onSuccess: () => { editor.value.amount_idr = ''; editor.value.reason = ''; editor.value.idempotency_key = crypto.randomUUID(); },
            onFinish: () => { busy.value = false; },
        });
    });
};

// ---------- hapus / nonaktifkan ----------
const pendingDelete = ref(null);
const removeAccount = () => {
    router.delete('/admin/customers/' + pendingDelete.value.id, {
        preserveScroll: true,
        onFinish: () => { pendingDelete.value = null; },
    });
};

// ---------- pembersihan akun kosong ----------
const cleanup = reactive({
    enabled: props.cleanupSettings?.enabled ?? true,
    inactivity_days: Number(props.cleanupSettings?.inactivity_days || 30),
});
watch(() => props.cleanupSettings, value => {
    cleanup.enabled = value?.enabled ?? true;
    cleanup.inactivity_days = Number(value?.inactivity_days || 30);
}, { deep: true });
const saveCleanup = () => {
    router.put('/admin/customers/cleanup/settings', {
        enabled: Boolean(cleanup.enabled), inactivity_days: Number(cleanup.inactivity_days),
    }, { preserveScroll: true });
};
const runCleanup = () => {
    router.post('/admin/customers/cleanup/run', {}, { preserveScroll: true });
};

</script>

<template>
    <Head title="Pelanggan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Pelanggan</h1>
                <p>Cari pelanggan, pantau saldo, dan atur akun pelanggan toko.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Ringkasan pelanggan">
            <div class="adm-stat"><span class="lb">Total pelanggan</span><strong class="vl">{{ num(summary.total) }}</strong></div>
            <div class="adm-stat"><span class="lb">Baru 30 hari</span><strong class="vl">{{ num(summary.new_30d) }}</strong></div>
            <div class="adm-stat"><span class="lb">Email terverifikasi</span><strong class="vl">{{ num(summary.verified) }}</strong></div>
            <div class="adm-stat"><span class="lb">Masuk lewat Google</span><strong class="vl">{{ num(summary.google_linked) }}</strong></div>
            <div class="adm-stat"><span class="lb">Saldo pelanggan</span><strong class="vl" style="font-size:16px">{{ rupiah(summary.wallet_total_idr) }}</strong></div>
            <div class="adm-stat"><span class="lb">Tiket terbuka</span><strong class="vl" :style="{ color: Number(summary.open_tickets) ? 'var(--a-warn)' : 'inherit' }">{{ num(summary.open_tickets) }}</strong></div>
        </section>

        <div class="adm-tabs" role="tablist" aria-label="Bagian pelanggan">
            <button type="button" role="tab" :aria-selected="tab === 'list'" @click="tab = 'list'">Daftar pelanggan</button>
            <button type="button" role="tab" :aria-selected="tab === 'cleanup'" @click="tab = 'cleanup'">Pembersihan akun</button>
        </div>

        <!-- Daftar pelanggan -->
        <template v-if="tab === 'list'">
            <section class="adm-card">
                <form class="adm-filters" @submit.prevent="apply">
                    <label class="adm-field search">
                        <span>Cari pelanggan</span>
                        <input v-model="filter.q" class="adm-input" maxlength="100" placeholder="Nama, email, atau nomor WhatsApp">
                    </label>
                    <label class="adm-field narrow">
                        <span>Status email</span>
                        <select v-model="filter.verification" class="adm-select">
                            <option value="">Semua</option>
                            <option value="verified">Terverifikasi</option>
                            <option value="unverified">Belum terverifikasi</option>
                        </select>
                    </label>
                    <div class="adm-filter-actions">
                        <button type="submit" class="adm-btn primary">Terapkan</button>
                        <button type="button" class="adm-btn" @click="reset">Hapus filter</button>
                    </div>
                    <div style="flex:1 0 100%">
                        <button type="button" class="adm-btn link" @click="advanced = !advanced">Filter lanjutan</button>
                    </div>
                    <template v-if="advanced">
                        <label class="adm-field narrow">
                            <span>Aktivitas</span>
                            <select v-model="filter.activity" class="adm-select">
                                <option value="">Semua</option>
                                <option value="active_30d">Aktif 30 hari</option>
                                <option value="inactive_30d">Tidak aktif 30 hari</option>
                            </select>
                        </label>
                        <label class="adm-field narrow">
                            <span>Riwayat pesanan</span>
                            <select v-model="filter.orders" class="adm-select">
                                <option value="">Semua</option>
                                <option value="with_orders">Sudah pernah pesan</option>
                                <option value="without_orders">Belum pernah pesan</option>
                            </select>
                        </label>
                        <label class="adm-field narrow">
                            <span>Pelanggan per halaman</span>
                            <select v-model.number="filter.per_page" class="adm-select">
                                <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} pelanggan</option>
                            </select>
                        </label>
                    </template>
                </form>

                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th>Pelanggan</th><th style="text-align:right">Saldo</th>
                                <th style="text-align:right">Pesanan</th><th>Terakhir aktif</th><th>Status</th><th>Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id">
                                <td>
                                    <Link :href="'/admin/customers/' + row.id" style="font-weight:600">{{ row.name }}</Link>
                                    <div class="adm-note">{{ row.email || 'Tanpa email' }}</div>
                                    <div class="adm-note">{{ row.phone || 'Tanpa nomor' }}</div>
                                </td>
                                <td style="text-align:right">{{ rupiah(row.balance_idr) }}</td>
                                <td style="text-align:right">
                                    {{ num(row.order_count) }}
                                    <div class="adm-note">{{ num(row.successful_order_count) }} berhasil</div>
                                </td>
                                <td class="adm-note">{{ wibDateTime(row.last_active_at) || 'Belum pernah' }}</td>
                                <td>
                                    <span class="adm-tag" :class="row.email_verified ? 'ok' : 'warn'">{{ row.email_verified ? 'Terverifikasi' : 'Belum verifikasi' }}</span>
                                    <div v-if="row.open_ticket_count" class="adm-note" style="color:var(--a-warn)">{{ row.open_ticket_count }} tiket terbuka</div>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                                        <Link :href="'/admin/customers/' + row.id" class="adm-btn sm">Buka</Link>
                                        <button v-if="isSuperAdmin" type="button" class="adm-btn sm" @click="openEditor(row)">Atur</button>
                                        <button v-if="isSuperAdmin && row.deletable" type="button" class="adm-btn sm danger" @click="pendingDelete = row">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!rows.length" class="adm-empty">Tidak ada pelanggan yang sesuai filter.</p>
                <div class="adm-pager">
                    <span>{{ customers.from || 0 }}–{{ customers.to || 0 }} dari {{ num(customers.total) }} pelanggan · Halaman {{ customers.current_page || 1 }} dari {{ customers.last_page || 1 }}</span>
                    <span class="sp" />
                    <button type="button" class="adm-btn sm" :disabled="!customers.prev_page_url" @click="router.get(customers.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                    <button type="button" class="adm-btn sm" :disabled="!customers.next_page_url" @click="router.get(customers.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
                </div>
            </section>
        </template>

        <!-- Pembersihan akun kosong -->
        <template v-else>
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Pembersihan akun kosong</h2>
                        <p>Akun tanpa saldo, tanpa pesanan, tanpa tiket, dan tanpa riwayat saldo akan dinonaktifkan lalu data pribadinya dihapus.</p>
                    </div>
                </div>
                <div class="adm-card-body">
                    <div class="adm-grid">
                        <label class="adm-switch-row" style="align-self:end">
                            <button type="button" class="adm-switch" role="switch" :aria-checked="cleanup.enabled" :disabled="!isSuperAdmin" @click="cleanup.enabled = !cleanup.enabled" />
                            Jalankan otomatis setiap hari
                        </label>
                        <label class="adm-field">
                            <span>Tidak aktif selama (hari)</span>
                            <input v-model.number="cleanup.inactivity_days" type="number" min="7" max="365" class="adm-input" :disabled="!isSuperAdmin">
                        </label>
                    </div>
                    <p class="adm-note" style="margin-top:8px">
                        Terakhir dijalankan: {{ wibDateTime(cleanupSettings?.last_run_at) || 'Belum pernah' }} ·
                        {{ num(cleanupSettings?.last_deleted_count) }} akun dibersihkan.
                    </p>
                    <div v-if="isSuperAdmin" style="display:flex;gap:6px;justify-content:flex-end;margin-top:10px">
                        <button type="button" class="adm-btn" @click="saveCleanup">Simpan pengaturan</button>
                        <button type="button" class="adm-btn danger" @click="runCleanup">Jalankan sekarang</button>
                    </div>
                    <p v-else class="adm-note">Pembersihan akun hanya dapat diubah Super Admin.</p>
                </div>
            </section>
        </template>

        <!-- pop-up atur pelanggan -->
        <AdminModal :open="!!editor" :title="'Atur ' + (editor?.row?.name || 'pelanggan')"
            description="Penyesuaian saldo dicatat di Catatan Audit."
            @update:open="value => { if (!value) editor = null; }">
            <div v-if="editor" class="adm-grid">
                <div class="adm-block full">
                    <strong>Penyesuaian saldo</strong>
                    <p class="adm-note">Saldo saat ini: {{ rupiah(editor.row.balance_idr) }}</p>
                    <div class="adm-grid">
                        <label class="adm-field">
                            <span>Jumlah (Rp)</span>
                            <input v-model="editor.amount_idr" type="number" class="adm-input" placeholder="Contoh: 10000 atau -5000">
                            <span class="hint">Angka negatif mengurangi saldo.</span>
                        </label>
                        <label class="adm-field">
                            <span>Alasan</span>
                            <input v-model="editor.reason" class="adm-input" maxlength="500" placeholder="Wajib diisi, minimal 3 huruf">
                        </label>
                    </div>
                    <span v-if="errors.amount_idr" class="err">{{ errors.amount_idr }}</span>
                    <span v-if="errors.reason" class="err">{{ errors.reason }}</span>
                    <div style="display:flex;justify-content:flex-end;margin-top:6px">
                        <button type="button" class="adm-btn primary" :disabled="busy || !editor.amount_idr" @click="saveBalance">Simpan saldo</button>
                    </div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="editor = null">Tutup</button>
            </template>
        </AdminModal>

        <!-- pop-up hapus akun -->
        <AdminModal :open="!!pendingDelete" title="Hapus akun pelanggan"
            :description="pendingDelete ? 'Akun ' + pendingDelete.name + ' akan dihapus permanen.' : ''"
            @update:open="value => { if (!value) pendingDelete = null; }">
            <p class="adm-note">Hanya akun tanpa saldo, tanpa pesanan, tanpa tiket, dan tanpa riwayat saldo yang bisa dihapus. Selain itu gunakan Nonaktifkan.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="pendingDelete = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="removeAccount">Hapus akun</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
