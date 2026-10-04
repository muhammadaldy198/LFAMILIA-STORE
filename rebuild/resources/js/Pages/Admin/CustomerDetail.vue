<script setup>
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    isSuperAdmin: Boolean,
    customer: Object,
    membershipProfile: Object,
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

const tab = ref('overview');
const membership = ref(props.customer?.membership_assignment || 'AUTO');
const wallet = reactive({ amount_idr: '', reason: '' });
const saving = ref('');

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Belum ada';

const statusLabel = (value) => ({
    PENDING_PAYMENT: 'Menunggu pembayaran',
    PAID: 'Dibayar',
    PROCESSING: 'Diproses',
    SUCCESS: 'Selesai',
    FAILED: 'Gagal',
    EXPIRED: 'Kedaluwarsa',
    CANCELLED: 'Dibatalkan',
    OPEN: 'Terbuka',
    IN_PROGRESS: 'Ditangani',
    RESOLVED: 'Selesai',
    CLOSED: 'Ditutup',
}[value] || value);

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
    if (!amount || reason.length < 3) return;

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
        },
        onFinish: () => { saving.value = ''; },
    });
}

function deleteEmpty() {
    if (!props.deletable) return;
    if (!window.confirm('Hapus akun kosong ini? Akun yang memiliki saldo atau riwayat tidak dapat dihapus.')) return;
    router.delete('/admin/customers/' + props.customer.id);
}
</script>

<template>
<Head :title="'Pelanggan · ' + customer.name" />
<AdminShell>
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/admin/customers" class="text-sm text-muted-foreground underline-offset-4 hover:underline">← Daftar pelanggan</Link>
                <h1 class="mt-2 text-2xl font-semibold">{{ customer.name }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">ID pelanggan #{{ customer.id }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Badge :variant="customer.email_verified_at ? 'secondary' : 'outline'">{{ customer.email_verified_at ? 'Email terverifikasi' : 'Email belum terverifikasi' }}</Badge>
                <Badge v-if="customer.google_linked" variant="outline">Google terhubung</Badge>
                <Badge v-if="customer.has_password" variant="outline">Memiliki kata sandi</Badge>
            </div>
        </div>

        <div class="lf-admin-summary ">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Saldo</p><p class="mt-2 text-xl font-semibold">{{ money(balanceIdr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total belanja</p><p class="mt-2 text-xl font-semibold">{{ money(summary.lifetime_spend_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Pesanan</p><p class="mt-2 text-2xl font-semibold">{{ summary.orders || 0 }}</p><p class="mt-1 text-xs text-muted-foreground">{{ summary.successful_orders || 0 }} selesai</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Top up saldo</p><p class="mt-2 text-2xl font-semibold">{{ summary.topups || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Tiket</p><p class="mt-2 text-2xl font-semibold">{{ summary.tickets || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Akun game</p><p class="mt-2 text-2xl font-semibold">{{ summary.saved_accounts || 0 }}</p></Card>
        </div>

        <nav class="lf-admin-tabs">
            <Button type="button" :variant="tab === 'overview' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'overview'">Profil</Button>
            <Button type="button" :variant="tab === 'tier' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'tier'">Tier</Button>
            <Button type="button" :variant="tab === 'orders' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'orders'">Pesanan</Button>
            <Button type="button" :variant="tab === 'wallet' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'wallet'">Saldo & Top Up</Button>
            <Button type="button" :variant="tab === 'support' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'support'">Tiket</Button>
            <Button type="button" :variant="tab === 'accounts' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'accounts'">Akun Game</Button>
        </nav>

        <template v-if="tab === 'overview'"><Card class="p-4">
                    <h2 class="text-lg font-semibold">Profil Pelanggan</h2>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div><dt class="text-xs text-muted-foreground">Nama</dt><dd class="mt-1 font-medium">{{ customer.name }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Email</dt><dd class="mt-1 break-all">{{ customer.email || 'Belum diisi' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Nomor telepon</dt><dd class="mt-1">{{ customer.phone || 'Belum diisi' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Membership aktif</dt><dd class="mt-1"><Badge variant="secondary">{{ customer.membership_tier_code }}</Badge></dd></div>
                        <div><dt class="text-xs text-muted-foreground">Mode membership</dt><dd class="mt-1">{{ customer.membership_mode === 'MANUAL' ? 'Manual' : 'Otomatis' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Leaderboard</dt><dd class="mt-1">{{ customer.leaderboard_opt_in ? 'Ikut ditampilkan' : 'Tidak ikut' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Terdaftar</dt><dd class="mt-1">{{ date(customer.created_at) }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Aktivitas terakhir</dt><dd class="mt-1">{{ date(customer.last_active_at || customer.created_at) }}</dd></div>
                    </dl>
                </Card><Card v-if="isSuperAdmin && deletable" class="border-destructive/40 p-4">
                <h2 class="font-semibold">Akun Kosong</h2>
                <p class="mt-1 text-sm text-muted-foreground">Akun tidak memiliki saldo, ledger, pesanan, top up, atau tiket yang harus dipertahankan.</p>
                <Button class="mt-3" size="sm" variant="destructive" @click="deleteEmpty">Hapus akun kosong</Button>
            </Card></template>
        <template v-else-if="tab === 'tier'"><Card class="p-4">
                    <h2 class="text-lg font-semibold">Progress Membership</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Tier aktif</p><p class="mt-1 text-xl font-semibold">{{ membershipProfile.code }}</p></div>
                        <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Diskon membership</p><p class="mt-1 text-xl font-semibold">{{ (Number(membershipProfile.discount_bps || 0) / 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) }}%</p></div>
                        <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Belanja tercatat</p><p class="mt-1 font-semibold">{{ money(membershipProfile.lifetime_spend_idr) }}</p></div>
                        <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Progress efektif</p><p class="mt-1 font-semibold">{{ money(membershipProfile.progress_idr) }}</p></div>
                    </div>
                    <p v-if="membershipProfile.next_code" class="mt-3 text-sm text-muted-foreground">Menuju {{ membershipProfile.next_code }}: kurang {{ money(membershipProfile.remaining_to_next_idr) }}.</p>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Tidak ada tier aktif berikutnya.</p>
                </Card><Card v-if="isSuperAdmin" class="p-4">
                    <h2 class="font-semibold">Atur Membership</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Mode otomatis mengikuti transaksi pelanggan. Pilih tier untuk menetapkannya secara manual.</p>
                    <select v-model="membership" class="mt-3 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="AUTO">Otomatis berdasarkan transaksi</option>
                        <option v-for="tier in membershipTiers" :key="tier.code" :value="tier.code">{{ tier.code }} · manual</option>
                    </select>
                    <Button class="mt-3" size="sm" :disabled="Boolean(saving)" @click="saveMembership">{{ saving === 'membership' ? 'Menyimpan…' : 'Simpan membership' }}</Button>
                </Card></template>

        <template v-else-if="tab === 'orders'">
            <Card class="p-4">
                <h2 class="text-lg font-semibold">Riwayat Pesanan</h2>
                <div class="mt-4 overflow-x-auto">
                    <AdminResponsiveTable :mobile-columns="[0,1,3,4]">
                        <TableHeader><TableRow><TableHead>Invoice</TableHead><TableHead>Produk</TableHead><TableHead>Nominal</TableHead><TableHead>Status</TableHead><TableHead class="text-right">Total</TableHead><TableHead>Tanggal</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="order in orders.data" :key="order.id">
                                <TableCell><Link :href="'/admin/orders/' + order.id" class="font-medium underline-offset-4 hover:underline">{{ order.order_number }}</Link></TableCell>
                                <TableCell>{{ order.product_name }}</TableCell>
                                <TableCell>{{ order.package_name }}</TableCell>
                                <TableCell><Badge variant="outline">{{ statusLabel(order.status) }}</Badge></TableCell>
                                <TableCell class="text-right">{{ money(order.total_idr) }}</TableCell>
                                <TableCell>{{ date(order.created_at) }}</TableCell>
                            </TableRow>
                            <TableRow v-if="!orders.data?.length"><TableCell colspan="6" class="py-10 text-center text-muted-foreground">Belum ada pesanan.</TableCell></TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>
                <div v-if="orders.links?.length > 3" class="mt-4 flex flex-wrap gap-1"><Link v-for="link in orders.links" :key="link.label" :href="link.url || '#'" preserve-scroll :class="['rounded-md border px-3 py-1.5 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-background', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" /></div>
            </Card>
        </template>

        <template v-else-if="tab === 'wallet'"><Card v-if="isSuperAdmin" class="p-4">
                    <h2 class="font-semibold">Penyesuaian Saldo</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Setiap perubahan saldo menggunakan ledger dan Audit Log. Saldo tidak dapat menjadi negatif.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[160px_minmax(0,1fr)]">
                        <label class="space-y-1"><span class="text-xs font-medium">Nominal (+/-)</span><Input v-model.number="wallet.amount_idr" type="number" /></label>
                        <label class="space-y-1"><span class="text-xs font-medium">Alasan</span><Input v-model="wallet.reason" maxlength="500" placeholder="Alasan penyesuaian" /></label>
                    </div>
                    <Button class="mt-3" size="sm" :disabled="Boolean(saving)" @click="adjustWallet">{{ saving === 'wallet' ? 'Memproses…' : 'Terapkan' }}</Button>
                </Card>
            <div class="lf-admin-summary ">
                <Card class="p-4">
                    <h2 class="text-lg font-semibold">Aktivitas Saldo Terakhir</h2>
                    <div class="mt-4 overflow-x-auto">
                        <AdminResponsiveTable :mobile-columns="[1,2,3]">
                            <TableHeader><TableRow><TableHead>Tanggal</TableHead><TableHead>Sumber</TableHead><TableHead class="text-right">Perubahan</TableHead><TableHead class="text-right">Saldo akhir</TableHead></TableRow></TableHeader>
                            <TableBody>
                                <TableRow v-for="entry in ledger" :key="entry.id"><TableCell>{{ date(entry.created_at) }}</TableCell><TableCell>{{ entry.source }}</TableCell><TableCell class="text-right" :class="Number(entry.amount_idr) < 0 ? 'text-destructive' : ''">{{ money(entry.amount_idr) }}</TableCell><TableCell class="text-right">{{ money(entry.balance_after_idr) }}</TableCell></TableRow>
                                <TableRow v-if="!ledger.length"><TableCell colspan="4" class="py-8 text-center text-muted-foreground">Belum ada aktivitas saldo.</TableCell></TableRow>
                            </TableBody>
                        </AdminResponsiveTable>
                    </div>
                </Card>

                <Card class="p-4">
                    <h2 class="text-lg font-semibold">Riwayat Top Up</h2>
                    <div class="mt-4 overflow-x-auto">
                        <AdminResponsiveTable :mobile-columns="[0,2,3]">
                            <TableHeader><TableRow><TableHead>Tanggal</TableHead><TableHead>Metode</TableHead><TableHead>Status</TableHead><TableHead class="text-right">Saldo masuk</TableHead><TableHead class="text-right">Total bayar</TableHead></TableRow></TableHeader>
                            <TableBody>
                                <TableRow v-for="topup in topups" :key="topup.id"><TableCell>{{ date(topup.created_at) }}</TableCell><TableCell>{{ topup.payment_channel_name || 'Metode lama' }}</TableCell><TableCell><Badge variant="outline">{{ statusLabel(topup.status) }}</Badge></TableCell><TableCell class="text-right">{{ money(topup.amount_idr) }}</TableCell><TableCell class="text-right">{{ money(topup.total_idr) }}</TableCell></TableRow>
                                <TableRow v-if="!topups.length"><TableCell colspan="5" class="py-8 text-center text-muted-foreground">Belum ada top up saldo.</TableCell></TableRow>
                            </TableBody>
                        </AdminResponsiveTable>
                    </div>
                </Card>
            </div>
        </template>

        <template v-else-if="tab === 'support'">
            <Card class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">Tiket Pelanggan</h2><p class="mt-1 text-sm text-muted-foreground">Balasan dan perubahan status dilakukan dari menu Layanan Pelanggan agar tidak ada fitur ganda.</p></div><Button variant="outline" size="sm" as-child><Link href="/admin/support">Buka Layanan Pelanggan</Link></Button></div>
                <div class="mt-4 overflow-x-auto">
                    <AdminResponsiveTable :mobile-columns="[1,2,0]"><TableHeader><TableRow><TableHead>Tiket</TableHead><TableHead>Subjek</TableHead><TableHead>Status</TableHead><TableHead>Tanggal</TableHead></TableRow></TableHeader><TableBody>
                        <TableRow v-for="ticket in tickets" :key="ticket.id"><TableCell>#{{ ticket.id }}</TableCell><TableCell>{{ ticket.subject }}</TableCell><TableCell><Badge variant="outline">{{ statusLabel(ticket.status) }}</Badge></TableCell><TableCell>{{ date(ticket.created_at) }}</TableCell></TableRow>
                        <TableRow v-if="!tickets.length"><TableCell colspan="4" class="py-8 text-center text-muted-foreground">Belum ada tiket.</TableCell></TableRow>
                    </TableBody></AdminResponsiveTable>
                </div>
            </Card>
        </template>

        <template v-else>
            <Card class="p-4">
                <h2 class="text-lg font-semibold">Akun Game Tersimpan</h2>
                <p class="mt-1 text-sm text-muted-foreground">Panel hanya menampilkan label, produk, dan nickname. Nilai input akun tersimpan tidak dibuka di halaman ini.</p>
                <div class="mt-4 overflow-x-auto">
                    <AdminResponsiveTable :mobile-columns="[0,1,2]"><TableHeader><TableRow><TableHead>Label</TableHead><TableHead>Produk</TableHead><TableHead>Nickname</TableHead><TableHead>Disimpan</TableHead></TableRow></TableHeader><TableBody>
                        <TableRow v-for="account in savedAccounts" :key="account.id"><TableCell>{{ account.label }}</TableCell><TableCell>{{ account.product_name }}</TableCell><TableCell>{{ account.nickname || '—' }}</TableCell><TableCell>{{ date(account.created_at) }}</TableCell></TableRow>
                        <TableRow v-if="!savedAccounts.length"><TableCell colspan="4" class="py-8 text-center text-muted-foreground">Belum ada akun game tersimpan.</TableCell></TableRow>
                    </TableBody></AdminResponsiveTable>
                </div>
            </Card>
        </template>
    </div>
</AdminShell>
</template>
