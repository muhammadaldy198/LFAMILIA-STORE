<script setup>
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { walletAdjustmentError } from '../../lib/adminWalletAdjustment';
import { withSensitive } from '../../lib/sensitivePrompt';
import { orderStatusInfo, paymentStatusLabel } from '../../lib/orderStatus';

const props = defineProps({
    isSuperAdmin: Boolean,
    customer: Object,
    membershipProfile: { type: Object, default: null },
    membershipTiers: { type: Array, default: () => [] },
    balanceIdr: Number,
    orders: { type: Object, default: () => ({ data: [], links: [] }) },
    ledger: { type: Array, default: () => [] },
    topups: { type: Array, default: () => [] },
    tickets: { type: Array, default: () => [] },
    savedAccounts: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    deletable: Boolean,
});

const membership = ref(props.customer?.membership_assignment || 'AUTO');
const wallet = reactive({ amount_idr: '', reason: '' });
const saving = ref('');
const adjustError = ref('');
const adjusting = ref(false);
const pendingAction = ref(null);
const ask = (title, message, confirmLabel, run, destructive = false) => {
    pendingAction.value = { title, message, confirmLabel, run, destructive };
};
const runPending = () => {
    const action = pendingAction.value;
    pendingAction.value = null;
    action?.run();
};

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const percent = (value) => (Number(value || 0) / 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Belum ada';

const ticketStatus = (value) => ({
    OPEN: 'Terbuka',
    IN_PROGRESS: 'Ditangani',
    RESOLVED: 'Selesai',
    CLOSED: 'Ditutup',
}[String(value || '').toUpperCase()] || 'Status sedang diperbarui');

const ledgerLabel = (value) => ({
    CHECKOUT: 'Bayar pesanan dengan saldo',
    REFUND: 'Pengembalian ke saldo',
    TOPUP: 'Top up saldo',
    ADMIN_ADJUSTMENT: 'Penyesuaian oleh admin',
}[String(value || '').toUpperCase()] || 'Perubahan saldo');

function saveMembership() {
    if (saving.value) return;
    saving.value = 'membership';
    router.put('/admin/customers/' + props.customer.id + '/membership', {
        membership_tier_code: membership.value,
    }, {
        preserveScroll: true,
        onFinish: () => { saving.value = ''; },
    });
}

function adjustWallet() {
    if (saving.value) return;
    const amount = Number(wallet.amount_idr || 0);
    const reason = String(wallet.reason || '').trim();
    adjustError.value = walletAdjustmentError(wallet.amount_idr, reason);
    if (adjustError.value) return;
    const direction = amount > 0 ? 'menambah' : 'mengurangi';
    void withSensitive(`Konfirmasi ${direction} saldo pelanggan sebesar ${money(Math.abs(amount))}? Alasan: ${reason}`, () => saveWallet(amount, reason));
}

function saveWallet(amount, reason) {
    saving.value = 'wallet';
    router.post('/admin/customers/' + props.customer.id + '/wallet', {
        amount_idr: amount,
        reason,
        idempotency_key: globalThis.crypto?.randomUUID?.()
            || ('customer-wallet-' + Date.now() + '-' + props.customer.id),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            wallet.amount_idr = '';
            wallet.reason = '';
            adjustError.value = '';
            adjusting.value = false;
        },
        onError: (errors) => { adjustError.value = Object.values(errors || {})[0] || 'Penyesuaian saldo gagal. Periksa input kembali.'; },
        onFinish: () => { saving.value = ''; },
    });
}

function deleteEmpty() {
    if (!props.deletable) return;
    ask('Hapus akun kosong', 'Akun ini tidak memiliki saldo atau riwayat. Data akun dianonimkan sesuai aturan perlindungan riwayat.', 'Hapus akun', () => {
        router.delete('/admin/customers/' + props.customer.id);
    }, true);
}
</script>

<template>
<Head :title="'Pelanggan · ' + customer.name" />
<AdminShell>
    <div class="space-y-6">
        <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <Link href="/admin/customers" class="text-sm text-muted-foreground underline-offset-4 hover:underline">← Daftar pelanggan</Link>
                <h1 class="mt-2 break-words text-2xl font-semibold tracking-tight">{{ customer.name }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">Pelanggan #{{ customer.id }} · terdaftar {{ date(customer.created_at) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Badge :variant="customer.email_verified_at ? 'secondary' : 'outline'">{{ customer.email_verified_at ? 'Email terverifikasi' : 'Email belum terverifikasi' }}</Badge>
                <Badge v-if="customer.google_linked" variant="outline">Google terhubung</Badge>
                <Button v-if="isSuperAdmin" variant="outline" @click="adjusting = true">Atur pelanggan</Button>
            </div>
        </header>

        <section class="lf-admin-summary" aria-label="Ringkasan pelanggan">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Saldo saat ini</p><p class="mt-2 text-2xl font-semibold">{{ money(balanceIdr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total belanja</p><p class="mt-2 text-2xl font-semibold">{{ money(summary.lifetime_spend_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Pesanan</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.orders || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Pesanan berhasil</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.successful_orders || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Top up</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.topups || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Tiket</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.tickets || 0).toLocaleString('id-ID') }}</p></Card>
        </section>

        <section aria-labelledby="customer-profile-heading" class="space-y-3">
            <h2 id="customer-profile-heading" class="text-lg font-semibold">Profil</h2>
            <dl class="grid gap-4 rounded-md border border-border p-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-xs text-muted-foreground">Nama</dt><dd class="mt-1 font-medium">{{ customer.name }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Email</dt><dd class="mt-1 break-all">{{ customer.email || 'Belum diisi' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Nomor telepon</dt><dd class="mt-1">{{ customer.phone || 'Belum diisi' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Tingkat member</dt><dd class="mt-1">{{ customer.membership_tier_code }} · {{ customer.membership_mode === 'MANUAL' ? 'ditetapkan admin' : 'otomatis dari transaksi' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Papan peringkat</dt><dd class="mt-1">{{ customer.leaderboard_opt_in ? 'Ikut ditampilkan' : 'Tidak ikut' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Aktivitas terakhir</dt><dd class="mt-1">{{ date(customer.last_active_at || customer.created_at) }}</dd></div>
            </dl>
        </section>

        <section aria-labelledby="customer-tier-heading" class="space-y-3">
            <h2 id="customer-tier-heading" class="text-lg font-semibold">Tingkat member</h2>
            <div v-if="membershipProfile" class="rounded-md border border-border">
                <dl class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-xs text-muted-foreground">Tingkat aktif</dt><dd class="mt-1 font-medium">{{ membershipProfile.code }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Diskon member</dt><dd class="mt-1 font-medium">{{ percent(membershipProfile.discount_bps) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Belanja tercatat</dt><dd class="mt-1 font-medium">{{ money(membershipProfile.lifetime_spend_idr) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Progress</dt><dd class="mt-1 font-medium">{{ money(membershipProfile.progress_idr) }}</dd></div>
                </dl>
                <p class="border-t border-border px-4 py-2 text-sm text-muted-foreground">
                    <template v-if="membershipProfile.next_code">Menuju {{ membershipProfile.next_code }}, kurang {{ money(membershipProfile.remaining_to_next_idr) }}.</template>
                    <template v-else>Tidak ada tingkat berikutnya.</template>
                </p>
            </div>
            <p v-else class="rounded-md border border-border px-4 py-3 text-sm text-muted-foreground">Data tingkat member belum tersedia.</p>
        </section>

        <section aria-labelledby="customer-orders-heading" class="space-y-3">
            <h2 id="customer-orders-heading" class="text-lg font-semibold">Riwayat pesanan</h2>
            <ul v-if="orders.data?.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="order in orders.data" :key="order.id">
                    <Link :href="'/admin/orders/' + order.id" class="flex flex-col gap-1 px-4 py-3 hover:bg-muted/40 sm:flex-row sm:items-center sm:gap-4">
                        <span class="min-w-0 flex-1">
                            <strong class="block">{{ order.order_number }}</strong>
                            <small class="block text-muted-foreground">{{ order.product_name }} · {{ order.package_name }}</small>
                        </span>
                        <span class="text-xs text-muted-foreground sm:w-56">{{ date(order.created_at) }}</span>
                        <Badge variant="outline">{{ orderStatusInfo(order.status).label }}</Badge>
                        <strong class="tabular-nums sm:w-32 sm:text-right">{{ money(order.total_idr) }}</strong>
                    </Link>
                </li>
            </ul>
            <p v-else class="rounded-md border border-border px-4 py-8 text-center text-sm text-muted-foreground">Belum ada pesanan.</p>
            <div v-if="orders.links?.length > 3" class="flex flex-wrap gap-1">
                <Link v-for="link in orders.links" :key="link.label" :href="link.url || '#'" preserve-scroll :class="['rounded-md border px-3 py-1.5 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-background', !link.url ? 'pointer-events-none opacity-40' : '']">{{ link.label }}</Link>
            </div>
        </section>

        <section aria-labelledby="customer-wallet-heading" class="space-y-3">
            <h2 id="customer-wallet-heading" class="text-lg font-semibold">Saldo dan top up</h2>
            <div class="rounded-md border border-border">
                <h3 class="border-b border-border px-4 py-2 text-sm font-semibold">Perubahan saldo terakhir</h3>
                <ul v-if="ledger.length" class="divide-y divide-border">
                    <li v-for="entry in ledger" :key="entry.id" class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                        <span class="w-44 text-xs text-muted-foreground">{{ date(entry.created_at) }}</span>
                        <span class="min-w-0 flex-1">{{ ledgerLabel(entry.source) }}</span>
                        <strong class="tabular-nums" :class="Number(entry.amount_idr) < 0 ? 'text-destructive' : ''">{{ money(entry.amount_idr) }}</strong>
                        <span class="w-32 text-right text-xs text-muted-foreground">Saldo {{ money(entry.balance_after_idr) }}</span>
                    </li>
                </ul>
                <p v-else class="px-4 py-6 text-center text-sm text-muted-foreground">Belum ada perubahan saldo.</p>
            </div>
            <div class="rounded-md border border-border">
                <h3 class="border-b border-border px-4 py-2 text-sm font-semibold">Riwayat top up</h3>
                <ul v-if="topups.length" class="divide-y divide-border">
                    <li v-for="topup in topups" :key="topup.id" class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                        <span class="w-44 text-xs text-muted-foreground">{{ date(topup.created_at) }}</span>
                        <span class="min-w-0 flex-1">{{ topup.payment_channel_name || 'Metode pembayaran lama' }}</span>
                        <Badge variant="outline">{{ paymentStatusLabel(topup.status) }}</Badge>
                        <strong class="tabular-nums">{{ money(topup.amount_idr) }}</strong>
                        <span class="w-32 text-right text-xs text-muted-foreground">Bayar {{ money(topup.total_idr) }}</span>
                    </li>
                </ul>
                <p v-else class="px-4 py-6 text-center text-sm text-muted-foreground">Belum ada top up saldo.</p>
            </div>
        </section>

        <section aria-labelledby="customer-support-heading" class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="customer-support-heading" class="text-lg font-semibold">Tiket bantuan</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Balasan dan perubahan status dilakukan dari menu Bantuan.</p>
                </div>
                <Button variant="outline" size="sm" as-child><Link href="/admin/support">Buka Bantuan</Link></Button>
            </div>
            <ul v-if="tickets.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="ticket in tickets" :key="ticket.id" class="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                    <span class="w-16 text-xs text-muted-foreground">#{{ ticket.id }}</span>
                    <span class="min-w-0 flex-1">{{ ticket.subject }}</span>
                    <Badge variant="outline">{{ ticketStatus(ticket.status) }}</Badge>
                    <span class="w-44 text-right text-xs text-muted-foreground">{{ date(ticket.created_at) }}</span>
                </li>
            </ul>
            <p v-else class="rounded-md border border-border px-4 py-8 text-center text-sm text-muted-foreground">Belum ada tiket.</p>
        </section>

        <section aria-labelledby="customer-accounts-heading" class="space-y-3">
            <div>
                <h2 id="customer-accounts-heading" class="text-lg font-semibold">Akun game tersimpan</h2>
                <p class="mt-1 text-sm text-muted-foreground">Hanya label, produk, dan nama panggilan yang ditampilkan. Isi data akun tidak dibuka di halaman ini.</p>
            </div>
            <ul v-if="savedAccounts.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="account in savedAccounts" :key="account.id" class="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                    <strong class="min-w-0 flex-1">{{ account.label }}</strong>
                    <span class="text-muted-foreground">{{ account.product_name }}</span>
                    <span class="w-32 text-right">{{ account.nickname || 'Tanpa nama panggilan' }}</span>
                    <span class="w-44 text-right text-xs text-muted-foreground">{{ date(account.created_at) }}</span>
                </li>
            </ul>
            <p v-else class="rounded-md border border-border px-4 py-8 text-center text-sm text-muted-foreground">Belum ada akun game tersimpan.</p>
        </section>

        <Sheet :open="adjusting" @update:open="(value) => { if (!value) adjusting = false; }">
            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader><SheetTitle>Atur {{ customer.name }}</SheetTitle><SheetDescription>Tingkat member dan perubahan saldo hanya dapat dilakukan Super Admin. Setiap perubahan dicatat untuk pemeriksaan.</SheetDescription></SheetHeader>
                <div class="mt-4 space-y-5">
                    <div class="space-y-2">
                        <h3 class="font-medium">Tingkat member</h3>
                        <p class="text-xs text-muted-foreground">Otomatis mengikuti transaksi pelanggan. Pilih tingkat untuk menetapkannya secara manual.</p>
                        <select v-model="membership" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="AUTO">Otomatis berdasarkan transaksi</option>
                            <option v-for="tier in membershipTiers" :key="tier.code" :value="tier.code">{{ tier.code }} · ditetapkan manual</option>
                        </select>
                        <Button size="sm" :disabled="Boolean(saving)" @click="saveMembership">{{ saving === 'membership' ? 'Menyimpan…' : 'Simpan tingkat' }}</Button>
                    </div>

                    <div class="space-y-2 border-t border-border pt-4">
                        <h3 class="font-medium">Penyesuaian saldo</h3>
                        <p class="text-xs text-muted-foreground">Saldo saat ini {{ money(balanceIdr) }}. Isi angka positif untuk menambah, negatif untuk mengurangi. Saldo tidak dapat menjadi negatif.</p>
                        <div class="grid gap-3 sm:grid-cols-[150px_minmax(0,1fr)]">
                            <label class="space-y-1"><span class="text-xs font-medium">Nominal (+/-)</span><Input v-model.number="wallet.amount_idr" type="number" /></label>
                            <label class="space-y-1"><span class="text-xs font-medium">Alasan</span><Input v-model="wallet.reason" maxlength="500" placeholder="Alasan penyesuaian" /></label>
                        </div>
                        <p v-if="adjustError" role="alert" class="text-sm text-destructive">{{ adjustError }}</p>
                        <Button size="sm" :disabled="Boolean(saving)" @click="adjustWallet">{{ saving === 'wallet' ? 'Memproses…' : 'Terapkan penyesuaian' }}</Button>
                    </div>

                    <div v-if="deletable" class="space-y-2 border-t border-border pt-4">
                        <h3 class="font-medium">Akun kosong</h3>
                        <p class="text-xs text-muted-foreground">Akun ini tidak memiliki saldo, catatan saldo, pesanan, top up, atau tiket yang harus dipertahankan.</p>
                        <Button size="sm" variant="destructive" @click="deleteEmpty">Hapus akun kosong</Button>
                    </div>

                    <div class="border-t border-border pt-4"><Button variant="outline" @click="adjusting = false">Tutup</Button></div>
                </div>
            </SheetContent>
        </Sheet>

        <AdminConfirmDialog
            :open="Boolean(pendingAction)"
            :title="pendingAction?.title || ''"
            :message="pendingAction?.message || ''"
            :confirm-label="pendingAction?.confirmLabel || 'Lanjutkan'"
            :destructive="Boolean(pendingAction?.destructive)"
            @update:open="(value) => { if (!value) pendingAction = null; }"
            @confirm="runPending"
        />
    </div>
</AdminShell>
</template>
