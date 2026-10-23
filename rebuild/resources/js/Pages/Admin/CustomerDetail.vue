<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { num, rupiah, wibDateTime, tone, label } from '../../lib/adminFormat';
import { walletAdjustmentError } from '../../lib/adminWalletAdjustment';
import { withSensitive } from '../../lib/sensitivePrompt';

const props = defineProps({
    isSuperAdmin: Boolean,
    customer: { type: Object, required: true },
    membershipProfile: { type: Object, default: null },
    membershipTiers: { type: Array, default: () => [] },
    balanceIdr: { type: Number, default: 0 },
    orders: { type: Object, default: () => ({ data: [], links: [] }) },
    ledger: { type: Array, default: () => [] },
    topups: { type: Array, default: () => [] },
    tickets: { type: Array, default: () => [] },
    savedAccounts: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    deletable: Boolean,
});

const managing = ref(false);
const busy = ref('');
const errors = ref({});
const wallet = reactive({ amount_idr: '', reason: '', idempotency_key: crypto.randomUUID() });
const membership = ref(props.customer?.membership_assignment || 'AUTO');

const openManage = () => {
    membership.value = props.customer?.membership_assignment || 'AUTO';
    wallet.amount_idr = '';
    wallet.reason = '';
    wallet.idempotency_key = crypto.randomUUID();
    errors.value = {};
    managing.value = true;
};

const saveMembership = () => {
    busy.value = 'membership';
    router.put('/admin/customers/' + props.customer.id + '/membership', {
        membership_tier_code: membership.value,
    }, {
        preserveScroll: true,
        onError: bag => { errors.value = bag; },
        onFinish: () => { busy.value = ''; },
    });
};

const adjustWallet = () => {
    const problem = walletAdjustmentError(wallet.amount_idr, wallet.reason);
    errors.value = problem ? { amount_idr: problem } : {};
    if (problem) return;
    const amount = Number(wallet.amount_idr || 0);
    const direction = amount > 0 ? 'menambah' : 'mengurangi';
    void withSensitive(`Konfirmasi ${direction} saldo pelanggan sebesar ${rupiah(Math.abs(amount))}? Alasan: ${wallet.reason}`, () => {
        busy.value = 'wallet';
        router.post('/admin/customers/' + props.customer.id + '/wallet', {
            amount_idr: amount, reason: wallet.reason, idempotency_key: wallet.idempotency_key,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                wallet.amount_idr = '';
                wallet.reason = '';
                wallet.idempotency_key = crypto.randomUUID();
            },
            onError: bag => { errors.value = bag; },
            onFinish: () => { busy.value = ''; },
        });
    });
};

// Akun tanpa saldo & riwayat boleh dibersihkan; server tetap memeriksa ulang.
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteAccount = () => {
    deleteBusy.value = true;
    router.delete(`/admin/customers/${props.customer.id}`, {
        preserveScroll: true,
        onFinish: () => { deleteBusy.value = false; },
    });
};

const ledgerLabel = value => ({
    CHECKOUT: 'Bayar pesanan dengan saldo', REFUND: 'Pengembalian ke saldo',
    TOPUP: 'Top up saldo', ADMIN_ADJUSTMENT: 'Penyesuaian oleh admin',
}[String(value || '').toUpperCase()] || 'Perubahan saldo');

const ticketLabel = value => ({
    OPEN: 'Terbuka', IN_PROGRESS: 'Ditangani', RESOLVED: 'Selesai', CLOSED: 'Ditutup',
}[String(value || '').toUpperCase()] || 'Status sedang diperbarui');
</script>

<template>
    <Head :title="'Pelanggan · ' + customer.name" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <Link href="/admin/customers" class="adm-btn link">← Daftar pelanggan</Link>
                <h1 style="margin-top:6px">{{ customer.name }}</h1>
                <p>Pelanggan #{{ customer.id }} · terdaftar {{ wibDateTime(customer.created_at) }}</p>
            </div>
            <div class="adm-head-actions">
                <span class="adm-tag" :class="customer.email_verified_at ? 'ok' : 'warn'">{{ customer.email_verified_at ? 'Email terverifikasi' : 'Email belum terverifikasi' }}</span>
                <span v-if="customer.google_linked" class="adm-tag info">Google terhubung</span>
                <button v-if="deletable && isSuperAdmin" type="button" class="adm-btn danger" @click="deleteOpen = true">Hapus akun kosong</button>
                <button v-if="isSuperAdmin" type="button" class="adm-btn primary" @click="openManage">Atur pelanggan</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Ringkasan pelanggan">
            <div class="adm-stat"><span class="lb">Saldo saat ini</span><strong class="vl" style="font-size:16px">{{ rupiah(balanceIdr) }}</strong></div>
            <div class="adm-stat"><span class="lb">Total belanja</span><strong class="vl" style="font-size:16px">{{ rupiah(summary.lifetime_spend_idr) }}</strong></div>
            <div class="adm-stat"><span class="lb">Pesanan</span><strong class="vl">{{ num(summary.orders) }}</strong></div>
            <div class="adm-stat"><span class="lb">Pesanan berhasil</span><strong class="vl">{{ num(summary.successful_orders) }}</strong></div>
            <div class="adm-stat"><span class="lb">Top up</span><strong class="vl">{{ num(summary.topups) }}</strong></div>
            <div class="adm-stat"><span class="lb">Tiket</span><strong class="vl">{{ num(summary.tickets) }}</strong></div>
        </section>

        <section class="adm-split">
            <div class="adm-card">
                <div class="adm-card-head"><div><h2>Profil</h2></div></div>
                <div class="adm-card-body">
                    <dl class="adm-kv">
                        <dt>Nama</dt><dd>{{ customer.name }}</dd>
                        <dt>Email</dt><dd>{{ customer.email || 'Belum diisi' }}</dd>
                        <dt>Nomor telepon</dt><dd>{{ customer.phone || 'Belum diisi' }}</dd>
                        <dt>Tingkat member</dt><dd>{{ customer.membership_tier_code }} · {{ customer.membership_mode === 'MANUAL' ? 'ditetapkan admin' : 'otomatis dari transaksi' }}</dd>
                        <dt>Papan peringkat</dt><dd>{{ customer.leaderboard_opt_in ? 'Ikut ditampilkan' : 'Tidak ikut' }}</dd>
                        <dt>Aktivitas terakhir</dt><dd>{{ wibDateTime(customer.last_active_at || customer.created_at) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="adm-card">
                <div class="adm-card-head"><div><h2>Tingkat member</h2></div></div>
                <div class="adm-card-body">
                    <template v-if="membershipProfile">
                        <dl class="adm-kv">
                            <dt>Tingkat aktif</dt><dd><span class="adm-tag" :class="membershipProfile.code === 'MAFIA' ? 'info' : 'ok'">{{ membershipProfile.code }}</span></dd>
                            <dt>Diskon member</dt><dd>{{ (Number(membershipProfile.discount_bps || 0) / 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) }}%</dd>
                            <dt>Belanja tercatat</dt><dd>{{ rupiah(membershipProfile.lifetime_spend_idr) }}</dd>
                            <dt>Progress</dt><dd>{{ rupiah(membershipProfile.progress_idr) }}</dd>
                        </dl>
                        <p class="adm-note" style="margin-top:8px">
                            <template v-if="membershipProfile.next_code">Menuju {{ membershipProfile.next_code }}, kurang {{ rupiah(membershipProfile.remaining_to_next_idr) }}.</template>
                            <template v-else>Tidak ada tingkat berikutnya.</template>
                        </p>
                    </template>
                    <p v-else class="adm-note">Data tingkat member belum tersedia.</p>
                </div>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Riwayat pesanan</h2></div></div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Pesanan</th><th>Produk</th><th>Waktu</th><th>Status</th><th style="text-align:right">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="order in orders.data" :key="order.id">
                            <td><Link :href="'/admin/orders/' + order.id" style="font-weight:600">{{ order.order_number }}</Link></td>
                            <td>{{ order.product_name }}<div class="adm-note">{{ order.package_name }}</div></td>
                            <td class="adm-note">{{ wibDateTime(order.created_at) }}</td>
                            <td><span class="adm-tag" :class="tone(order.status)">{{ label(order.status) }}</span></td>
                            <td style="text-align:right">{{ rupiah(order.total_idr) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!orders.data?.length" class="adm-empty">Belum ada pesanan.</p>
            <div class="adm-pager">
                <span>{{ orders.from || 0 }}–{{ orders.to || 0 }} dari {{ num(orders.total) }} pesanan</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!orders.prev_page_url" @click="router.get(orders.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!orders.next_page_url" @click="router.get(orders.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>

        <section class="adm-split">
            <div class="adm-card">
                <div class="adm-card-head"><div><h2>Perubahan saldo</h2></div></div>
                <div class="adm-card-body" style="display:grid;gap:0">
                    <div v-for="entry in ledger" :key="entry.id" class="adm-list-row">
                        <div>
                            <strong>{{ ledgerLabel(entry.source) }}</strong>
                            <div class="adm-note">{{ wibDateTime(entry.created_at) }} · saldo {{ rupiah(entry.balance_after_idr) }}</div>
                        </div>
                        <strong :style="{ color: Number(entry.amount_idr) < 0 ? 'var(--a-danger)' : 'var(--a-ok)' }">{{ rupiah(entry.amount_idr) }}</strong>
                    </div>
                    <p v-if="!ledger.length" class="adm-note">Belum ada perubahan saldo.</p>
                </div>
            </div>

            <div class="adm-card">
                <div class="adm-card-head"><div><h2>Riwayat top up</h2></div></div>
                <div class="adm-card-body" style="display:grid;gap:0">
                    <div v-for="topup in topups" :key="topup.id" class="adm-list-row">
                        <div>
                            <strong>{{ topup.payment_channel_name || 'Metode pembayaran lama' }}</strong>
                            <div class="adm-note">{{ wibDateTime(topup.created_at) }} · bayar {{ rupiah(topup.total_idr) }}</div>
                        </div>
                        <div style="text-align:right">
                            <span class="adm-tag" :class="tone(topup.status)">{{ label(topup.status) }}</span>
                            <div class="adm-note">{{ rupiah(topup.amount_idr) }}</div>
                        </div>
                    </div>
                    <p v-if="!topups.length" class="adm-note">Belum ada top up saldo.</p>
                </div>
            </div>
        </section>

        <section class="adm-split">
            <div class="adm-card">
                <div class="adm-card-head">
                    <div><h2>Tiket bantuan</h2></div>
                    <Link href="/admin/support" class="adm-btn sm">Buka Layanan Pelanggan</Link>
                </div>
                <div class="adm-card-body" style="display:grid;gap:0">
                    <div v-for="ticket in tickets" :key="ticket.id" class="adm-list-row">
                        <div>
                            <strong>#{{ ticket.id }} · {{ ticket.subject }}</strong>
                            <div class="adm-note">{{ wibDateTime(ticket.created_at) }}</div>
                        </div>
                        <span class="adm-tag" :class="tone(ticket.status)">{{ ticketLabel(ticket.status) }}</span>
                    </div>
                    <p v-if="!tickets.length" class="adm-note">Belum ada tiket.</p>
                </div>
            </div>

            <div class="adm-card">
                <div class="adm-card-head"><div><h2>Akun game tersimpan</h2><p>Hanya label, produk, dan nama panggilan. Isi data akun tidak dibuka di halaman ini.</p></div></div>
                <div class="adm-card-body" style="display:grid;gap:0">
                    <div v-for="account in savedAccounts" :key="account.id" class="adm-list-row">
                        <div>
                            <strong>{{ account.label }}</strong>
                            <div class="adm-note">{{ account.product_name }} · {{ account.nickname || 'Tanpa nama panggilan' }}</div>
                        </div>
                        <span class="adm-note">{{ wibDateTime(account.created_at) }}</span>
                    </div>
                    <p v-if="!savedAccounts.length" class="adm-note">Belum ada akun game tersimpan.</p>
                </div>
            </div>
        </section>

        <AdminModal v-model:open="managing" :title="'Atur ' + customer.name"
            description="Tingkat member dan penyesuaian saldo. Keduanya dicatat di Catatan Audit.">
            <div class="adm-grid">
                <div class="adm-block full">
                    <strong>Tingkat member</strong>
                    <div class="adm-grid">
                        <label class="adm-field">
                            <span>Tingkat</span>
                            <select v-model="membership" class="adm-select">
                                <option value="AUTO">Otomatis dari transaksi</option>
                                <option v-for="tier in membershipTiers" :key="tier.code" :value="tier.code">{{ tier.code }}</option>
                            </select>
                            <span class="hint">MAFIA hanya bisa ditetapkan di sini, tidak pernah muncul ke pelanggan.</span>
                        </label>
                        <div class="adm-field" style="align-self:end">
                            <button type="button" class="adm-btn primary" :disabled="busy === 'membership'" @click="saveMembership">Simpan tingkat</button>
                        </div>
                    </div>
                </div>

                <div class="adm-block full">
                    <strong>Penyesuaian saldo</strong>
                    <p class="adm-note">Saldo saat ini: {{ rupiah(balanceIdr) }}</p>
                    <div class="adm-grid">
                        <label class="adm-field">
                            <span>Jumlah (Rp)</span>
                            <input v-model="wallet.amount_idr" type="number" class="adm-input" placeholder="Contoh: 10000 atau -5000">
                            <span class="hint">Angka negatif mengurangi saldo.</span>
                        </label>
                        <label class="adm-field">
                            <span>Alasan</span>
                            <input v-model="wallet.reason" class="adm-input" maxlength="500" placeholder="Wajib diisi, minimal 3 huruf">
                        </label>
                    </div>
                    <span v-if="errors.amount_idr" class="err">{{ errors.amount_idr }}</span>
                    <span v-if="errors.reason" class="err">{{ errors.reason }}</span>
                    <div style="display:flex;justify-content:flex-end;margin-top:6px">
                        <button type="button" class="adm-btn primary" :disabled="busy === 'wallet' || !wallet.amount_idr" @click="adjustWallet">Simpan saldo</button>
                    </div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="managing = false">Tutup</button>
            </template>
        </AdminModal>
        <AdminModal v-model:open="deleteOpen" title="Hapus akun kosong" :description="customer.name || customer.email || ''">
            <p class="adm-note">Akun ini tidak punya saldo dan riwayat transaksi. Penghapusan tidak bisa dibatalkan. Server menolak akun yang ternyata masih memiliki saldo atau riwayat.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="deleteOpen = false">Batal</button>
                <button type="button" class="adm-btn danger" :disabled="deleteBusy" @click="deleteAccount">{{ deleteBusy ? 'Menghapus…' : 'Hapus akun' }}</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
