<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    isSuperAdmin: Boolean,
    customers: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    membershipTiers: { type: Array, default: () => [] },
    cleanupSettings: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const filters = reactive({
    q: props.filters?.q || '',
    tier: props.filters?.tier || '',
    membership_mode: props.filters?.membership_mode || '',
    verification: props.filters?.verification || '',
    activity: props.filters?.activity || '',
    orders: props.filters?.orders || '',
    per_page: Number(props.filters?.per_page || 25),
});

const cleanup = reactive({
    enabled: props.cleanupSettings?.enabled ?? true,
    inactivity_days: Number(props.cleanupSettings?.inactivity_days || 30),
});

const editor = ref(null);
const saving = ref('');

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Belum ada';

function search() {
    router.get('/admin/customers', {
        q: filters.q || undefined,
        tier: filters.tier || undefined,
        membership_mode: filters.membership_mode || undefined,
        verification: filters.verification || undefined,
        activity: filters.activity || undefined,
        orders: filters.orders || undefined,
        per_page: filters.per_page,
    }, { preserveState: true, preserveScroll: true });
}

function reset() {
    Object.assign(filters, {
        q: '',
        tier: '',
        membership_mode: '',
        verification: '',
        activity: '',
        orders: '',
        per_page: 25,
    });
    search();
}

function openEditor(row) {
    editor.value = {
        ...row,
        membership_assignment: row.membership_assignment || 'AUTO',
        adjust_amount: '',
        adjust_reason: '',
    };
}

function closeEditor() {
    editor.value = null;
}

function saveMembership() {
    if (!editor.value || saving.value) return;
    saving.value = 'membership';
    router.put('/admin/customers/' + editor.value.id + '/membership', {
        membership_tier_code: editor.value.membership_assignment || 'AUTO',
    }, {
        preserveScroll: true,
        onSuccess: closeEditor,
        onFinish: () => { saving.value = ''; },
    });
}

function adjustWallet() {
    if (!editor.value || saving.value) return;
    const amount = Number(editor.value.adjust_amount || 0);
    const reason = String(editor.value.adjust_reason || '').trim();
    if (!amount || reason.length < 3) return;

    saving.value = 'wallet';
    router.post('/admin/customers/' + editor.value.id + '/wallet', {
        amount_idr: amount,
        reason,
        idempotency_key: globalThis.crypto?.randomUUID?.()
            || ('customer-wallet-' + Date.now() + '-' + editor.value.id),
    }, {
        preserveScroll: true,
        onSuccess: closeEditor,
        onFinish: () => { saving.value = ''; },
    });
}

function deleteEmpty(row) {
    if (!row?.deletable) return;
    if (!window.confirm('Hapus akun kosong ' + row.name + '? Data akun akan dianonimkan/dihapus sesuai aturan perlindungan riwayat.')) return;
    router.delete('/admin/customers/' + row.id, { preserveScroll: true });
}

function saveCleanup() {
    router.put('/admin/customers/cleanup/settings', {
        enabled: Boolean(cleanup.enabled),
        inactivity_days: Number(cleanup.inactivity_days),
    }, { preserveScroll: true });
}

function runCleanup() {
    if (!window.confirm('Jalankan pembersihan akun kosong sekarang? Akun dengan saldo atau riwayat transaksi/tiket tetap dilindungi.')) return;
    router.post('/admin/customers/cleanup/run', {}, { preserveScroll: true });
}
</script>

<template>
<Head title="Pelanggan" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pelanggan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Cari pelanggan, lihat saldo dan aktivitas, kelola membership, serta buka riwayat pesanan, top up, tiket, dan akun game tersimpan.
                </p>
            </div>
            <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total pelanggan</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.total || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Baru 30 hari</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.new_30d || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Email terverifikasi</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.verified || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Terhubung Google</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.google_linked || 0).toLocaleString('id-ID') }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total saldo</p><p class="mt-2 text-xl font-semibold">{{ money(summary.wallet_total_idr) }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Tiket aktif</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.open_tickets || 0).toLocaleString('id-ID') }}</p></Card>
        </div>

        <Card class="p-4">
            <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-7" @submit.prevent="search">
                <label class="space-y-1 md:col-span-2">
                    <span class="text-sm font-medium">Cari pelanggan</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Nama, email, atau nomor telepon" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Membership</span>
                    <select v-model="filters.tier" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua tier</option>
                        <option v-for="tier in membershipTiers" :key="tier.code" :value="tier.code">{{ tier.code }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Mode membership</span>
                    <select v-model="filters.membership_mode" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua mode</option>
                        <option value="AUTO">Otomatis</option>
                        <option value="MANUAL">Manual</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Verifikasi email</span>
                    <select v-model="filters.verification" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua</option>
                        <option value="verified">Terverifikasi</option>
                        <option value="unverified">Belum terverifikasi</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Aktivitas</span>
                    <select v-model="filters.activity" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua</option>
                        <option value="active_30d">Aktif 30 hari</option>
                        <option value="inactive_30d">Tidak aktif 30 hari</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Pesanan</span>
                    <select v-model="filters.orders" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua</option>
                        <option value="with_orders">Pernah memesan</option>
                        <option value="without_orders">Belum pernah memesan</option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-7">
                    <Button type="submit" size="sm">Terapkan</Button>
                    <Button type="button" size="sm" variant="outline" @click="reset">Reset</Button>
                </div>
            </form>
        </Card>

        <Card class="min-w-0 p-4">
            <div class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Pelanggan</TableHead>
                            <TableHead>Membership</TableHead>
                            <TableHead class="text-right">Saldo</TableHead>
                            <TableHead class="text-right">Belanja</TableHead>
                            <TableHead class="text-right">Pesanan</TableHead>
                            <TableHead>Aktivitas terakhir</TableHead>
                            <TableHead>Status akun</TableHead>
                            <TableHead class="text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in customers.data" :key="row.id">
                            <TableCell>
                                <div class="min-w-[210px]">
                                    <Link :href="'/admin/customers/' + row.id" class="font-medium underline-offset-4 hover:underline">{{ row.name }}</Link>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ row.email || 'Email belum diisi' }}</p>
                                    <p class="text-xs text-muted-foreground">{{ row.phone || 'Nomor telepon belum diisi' }}</p>
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge variant="secondary">{{ row.membership_tier_code }}</Badge>
                                <p class="mt-1 text-xs text-muted-foreground">{{ row.membership_mode === 'MANUAL' ? 'Manual' : 'Otomatis' }}</p>
                            </TableCell>
                            <TableCell class="text-right font-medium">{{ money(row.balance_idr) }}</TableCell>
                            <TableCell class="text-right">{{ money(row.lifetime_spend_idr) }}</TableCell>
                            <TableCell class="text-right">
                                <strong>{{ row.order_count }}</strong>
                                <p class="text-xs text-muted-foreground">{{ row.successful_order_count }} berhasil</p>
                            </TableCell>
                            <TableCell>
                                <span class="text-sm">{{ date(row.last_active_at || row.created_at) }}</span>
                                <p class="mt-0.5 text-xs text-muted-foreground">Daftar {{ date(row.created_at) }}</p>
                            </TableCell>
                            <TableCell>
                                <div class="flex min-w-[150px] flex-wrap gap-1">
                                    <Badge :variant="row.email_verified ? 'secondary' : 'outline'">{{ row.email_verified ? 'Email valid' : 'Email belum valid' }}</Badge>
                                    <Badge v-if="row.google_linked" variant="outline">Google</Badge>
                                    <Badge v-if="row.open_ticket_count" variant="outline">{{ row.open_ticket_count }} tiket aktif</Badge>
                                </div>
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-2">
                                    <Button v-if="isSuperAdmin" size="sm" variant="outline" @click="openEditor(row)">Atur</Button>
                                    <Button size="sm" variant="outline" as-child><Link :href="'/admin/customers/' + row.id">Detail</Link></Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!customers.data?.length">
                            <TableCell colspan="8" class="py-10 text-center text-muted-foreground">Tidak ada pelanggan sesuai filter.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="customers.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                <Link
                    v-for="link in customers.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    preserve-scroll
                    :class="[
                        'rounded-md border px-3 py-1.5 text-sm',
                        link.active ? 'bg-primary text-primary-foreground' : 'bg-background',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    v-html="link.label"
                />
            </div>
        </Card>

        <Card v-if="editor && isSuperAdmin" class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Atur pelanggan · {{ editor.name }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Membership dan penyesuaian saldo hanya dapat dilakukan Super Admin dan selalu dicatat di Audit Log.</p>
                </div>
                <Button size="sm" variant="ghost" @click="closeEditor">Tutup</Button>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-md border p-4">
                    <h3 class="font-medium">Membership pelanggan</h3>
                    <p class="mt-1 text-xs text-muted-foreground">Mode otomatis mengikuti total transaksi. Mode manual mengunci tier pilihan.</p>
                    <select v-model="editor.membership_assignment" class="mt-3 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="AUTO">Otomatis berdasarkan transaksi</option>
                        <option v-for="tier in membershipTiers" :key="tier.code" :value="tier.code">{{ tier.code }} · manual</option>
                    </select>
                    <Button class="mt-3" size="sm" :disabled="Boolean(saving)" @click="saveMembership">{{ saving === 'membership' ? 'Menyimpan…' : 'Simpan membership' }}</Button>
                </div>

                <div class="rounded-md border p-4">
                    <h3 class="font-medium">Penyesuaian saldo</h3>
                    <p class="mt-1 text-xs text-muted-foreground">Saldo saat ini {{ money(editor.balance_idr) }}. Gunakan nilai positif untuk menambah dan negatif untuk mengurangi.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[160px_minmax(0,1fr)]">
                        <label class="space-y-1"><span class="text-xs font-medium">Nominal (+/-)</span><Input v-model.number="editor.adjust_amount" type="number" /></label>
                        <label class="space-y-1"><span class="text-xs font-medium">Alasan</span><Input v-model="editor.adjust_reason" maxlength="500" placeholder="Alasan penyesuaian saldo" /></label>
                    </div>
                    <Button class="mt-3" size="sm" :disabled="Boolean(saving)" @click="adjustWallet">{{ saving === 'wallet' ? 'Memproses…' : 'Terapkan penyesuaian' }}</Button>
                </div>
            </div>

            <div v-if="editor.deletable" class="mt-4 rounded-md border border-destructive/40 p-4">
                <h3 class="font-medium">Akun kosong</h3>
                <p class="mt-1 text-xs text-muted-foreground">Akun ini tidak memiliki saldo, ledger, pesanan, top up, atau tiket yang harus dipertahankan.</p>
                <Button class="mt-3" size="sm" variant="destructive" @click="deleteEmpty(editor)">Hapus akun kosong</Button>
            </div>
        </Card>

        <Card v-if="isSuperAdmin" class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Pembersihan Akun Kosong</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Hanya akun lama yang benar-benar kosong yang dapat dibersihkan. Saldo, ledger, pesanan, top up, dan tiket selalu dilindungi.</p>
                </div>
                <Badge variant="outline">Super Admin</Badge>
            </div>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <label class="flex items-center gap-2 text-sm"><input v-model="cleanup.enabled" type="checkbox" class="size-4">Jalankan otomatis setiap hari</label>
                <label class="space-y-1"><span class="text-sm font-medium">Tidak aktif selama</span><div class="flex items-center gap-2"><Input v-model.number="cleanup.inactivity_days" type="number" min="7" max="365" class="w-28" /><span class="text-sm">hari</span></div></label>
                <Button size="sm" @click="saveCleanup">Simpan pengaturan</Button>
                <Button size="sm" variant="outline" @click="runCleanup">Jalankan sekarang</Button>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">Terakhir: {{ cleanupSettings?.last_run_at ? date(cleanupSettings.last_run_at) : 'Belum pernah' }} · {{ Number(cleanupSettings?.last_deleted_count || 0).toLocaleString('id-ID') }} akun dibersihkan.</p>
        </Card>
    </div>
</AdminShell>
</template>
