<script setup>
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';

const props = defineProps({
    isSuperAdmin: Boolean,
    canManageMembershipRules: Boolean,
    customers: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    membershipTiers: { type: Array, default: () => [] },
    membershipRules: { type: Array, default: () => [] },
    cleanupSettings: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const tab = ref('pelanggan');
const pendingAction = ref(null);
const ask = (title, message, confirmLabel, run, destructive = false) => {
    pendingAction.value = { title, message, confirmLabel, run, destructive };
};
const runPending = () => {
    const action = pendingAction.value;
    pendingAction.value = null;
    action?.run();
};

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

const membershipRules = reactive((props.membershipRules || []).map(tier => ({ ...tier })));
watch(() => props.membershipRules, value => membershipRules.splice(0, membershipRules.length, ...(value || []).map(tier => ({ ...tier }))));
const editingTier = ref(null);
const saving = ref('');

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const percent = (value) => Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Belum ada';

const activeFilterCount = computed(() => ['verification', 'membership_mode', 'activity', 'orders']
    .filter((key) => filters[key]).length);

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

function saveTier(tier) {
    if (saving.value) return;
    saving.value = 'tier';
    router.put('/admin/settings/membership/' + encodeURIComponent(tier.code), {
        is_active: Boolean(tier.is_active),
        minimum_spend_idr: Number(tier.minimum_spend_idr || 0),
        discount_percent: Number(tier.discount_percent || 0),
        benefit_notes: tier.benefit_notes || '',
    }, {
        preserveScroll: true,
        onSuccess: () => { editingTier.value = null; },
        onFinish: () => { saving.value = ''; },
    });
}

function saveCleanup() {
    router.put('/admin/customers/cleanup/settings', {
        enabled: Boolean(cleanup.enabled),
        inactivity_days: Number(cleanup.inactivity_days),
    }, { preserveScroll: true });
}

function runCleanup() {
    ask('Jalankan pembersihan', 'Akun dengan saldo atau riwayat transaksi dan tiket tetap dilindungi. Lanjutkan pembersihan sekarang?', 'Jalankan', () => {
        router.post('/admin/customers/cleanup/run', {}, { preserveScroll: true });
    });
}
</script>

<template>
<Head title="Pelanggan" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">Pelanggan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Cari pelanggan dan lihat ringkasan saldo serta aktivitasnya. Pilih satu baris untuk membuka profil lengkap berisi pesanan, saldo, tiket, dan akun game.
                </p>
            </div>
            <Button class="w-full sm:w-auto" variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <nav class="lf-admin-tabs" aria-label="Bagian pelanggan">
            <Button type="button" variant="ghost" :class="{ active: tab === 'pelanggan' }" :aria-current="tab === 'pelanggan' ? 'page' : undefined" @click="tab = 'pelanggan'">Daftar pelanggan</Button>
            <Button type="button" variant="ghost" :class="{ active: tab === 'membership' }" :aria-current="tab === 'membership' ? 'page' : undefined" @click="tab = 'membership'">Tingkat member</Button>
        </nav>

        <div v-show="tab === 'pelanggan'" class="space-y-5">
            <section class="lf-admin-summary" aria-label="Ringkasan pelanggan">
                <Card class="p-4"><p class="text-xs text-muted-foreground">Total pelanggan</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.total || 0).toLocaleString('id-ID') }}</p></Card>
                <Card class="p-4"><p class="text-xs text-muted-foreground">Baru 30 hari</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.new_30d || 0).toLocaleString('id-ID') }}</p></Card>
                <Card class="p-4"><p class="text-xs text-muted-foreground">Email terverifikasi</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.verified || 0).toLocaleString('id-ID') }}</p></Card>
                <Card class="p-4"><p class="text-xs text-muted-foreground">Terhubung Google</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.google_linked || 0).toLocaleString('id-ID') }}</p></Card>
                <Card class="p-4"><p class="text-xs text-muted-foreground">Total saldo</p><p class="mt-2 text-2xl font-semibold">{{ money(summary.wallet_total_idr) }}</p></Card>
                <Card class="p-4"><p class="text-xs text-muted-foreground">Tiket aktif</p><p class="mt-2 text-2xl font-semibold">{{ Number(summary.open_tickets || 0).toLocaleString('id-ID') }}</p></Card>
            </section>

            <form class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_200px_auto]" @submit.prevent="search">
                <label class="space-y-1">
                    <span class="text-sm font-medium">Cari pelanggan</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Nama, email, atau nomor telepon" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Tingkat member</span>
                    <select v-model="filters.tier" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua tingkat</option>
                        <option v-for="tier in membershipTiers" :key="tier.code" :value="tier.code">{{ tier.code }}</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <Button type="submit">Cari</Button>
                    <Button type="button" variant="outline" @click="reset">Atur ulang</Button>
                </div>
            </form>

            <details class="lf-admin-advanced rounded-md border border-border">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Filter lanjutan<template v-if="activeFilterCount"> · {{ activeFilterCount }} aktif</template></summary>
                <form class="grid gap-3 border-t border-border p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="search">
                    <label class="space-y-1"><span class="text-sm font-medium">Verifikasi email</span>
                        <select v-model="filters.verification" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua</option>
                            <option value="verified">Terverifikasi</option>
                            <option value="unverified">Belum terverifikasi</option>
                        </select>
                    </label>
                    <label class="space-y-1"><span class="text-sm font-medium">Cara penetapan tingkat</span>
                        <select v-model="filters.membership_mode" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua</option>
                            <option value="AUTO">Otomatis dari transaksi</option>
                            <option value="MANUAL">Ditetapkan admin</option>
                        </select>
                    </label>
                    <label class="space-y-1"><span class="text-sm font-medium">Aktivitas</span>
                        <select v-model="filters.activity" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua</option>
                            <option value="active_30d">Aktif 30 hari terakhir</option>
                            <option value="inactive_30d">Tidak aktif 30 hari</option>
                        </select>
                    </label>
                    <label class="space-y-1"><span class="text-sm font-medium">Riwayat pesanan</span>
                        <select v-model="filters.orders" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua</option>
                            <option value="with_orders">Pernah memesan</option>
                            <option value="without_orders">Belum pernah memesan</option>
                        </select>
                    </label>
                    <label class="space-y-1"><span class="text-sm font-medium">Baris per halaman</span>
                        <select v-model.number="filters.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option :value="10">10 baris</option>
                            <option :value="25">25 baris</option>
                            <option :value="50">50 baris</option>
                            <option :value="100">100 baris</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4"><Button type="submit">Terapkan filter</Button><Button type="button" variant="outline" @click="reset">Hapus filter</Button></div>
                </form>
            </details>

            <ul v-if="customers.data?.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="row in customers.data" :key="row.id">
                    <Link :href="'/admin/customers/' + row.id" class="flex flex-col gap-2 px-4 py-3 hover:bg-muted/40 sm:flex-row sm:items-center sm:gap-4">
                        <span class="min-w-0 flex-1">
                            <strong class="block truncate">{{ row.name }}</strong>
                            <small class="mt-0.5 block truncate text-muted-foreground">{{ row.email || 'Email belum diisi' }} · {{ row.phone || 'Nomor telepon belum diisi' }}</small>
                            <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <Badge variant="secondary">{{ row.membership_tier_code }}</Badge>
                                <span>{{ row.membership_mode === 'MANUAL' ? 'Ditetapkan admin' : 'Otomatis dari transaksi' }}</span>
                                <Badge v-if="!row.email_verified" variant="outline">Email belum terverifikasi</Badge>
                                <Badge v-if="row.open_ticket_count" variant="outline">{{ row.open_ticket_count }} tiket belum selesai</Badge>
                            </span>
                        </span>
                        <span class="grid grid-cols-3 gap-3 text-xs sm:w-72 sm:text-right">
                            <span><small class="block text-muted-foreground">Saldo</small><strong class="tabular-nums">{{ money(row.balance_idr) }}</strong></span>
                            <span><small class="block text-muted-foreground">Pesanan</small><strong class="tabular-nums">{{ row.order_count }}</strong></span>
                            <span><small class="block text-muted-foreground">Terakhir aktif</small><strong>{{ date(row.last_active_at || row.created_at) }}</strong></span>
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">Buka</span>
                    </Link>
                </li>
            </ul>
            <p v-else class="rounded-md border border-border px-4 py-10 text-center text-sm text-muted-foreground">Tidak ada pelanggan yang cocok dengan pencarian ini.</p>

            <div v-if="customers.links?.length > 3" class="flex flex-wrap gap-1">
                <Link
                    v-for="link in customers.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    preserve-scroll
                    :aria-current="link.active ? 'page' : undefined"
                    :class="[
                        'rounded-md border px-3 py-1.5 text-sm',
                        link.active ? 'bg-primary text-primary-foreground' : 'bg-background',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    >{{ link.label }}</Link>
            </div>

            <details v-if="isSuperAdmin" class="lf-admin-advanced rounded-md border border-border">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Pembersihan akun kosong</summary>
                <div class="space-y-3 border-t border-border p-4">
                    <p class="text-sm text-muted-foreground">Hanya akun lama yang benar-benar kosong yang dibersihkan. Saldo, catatan perubahan saldo, pesanan, top up, dan tiket selalu dilindungi.</p>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="cleanup.enabled" />Jalankan otomatis setiap hari</label>
                        <label class="space-y-1"><span class="text-sm font-medium">Tidak aktif selama</span><div class="flex items-center gap-2"><Input v-model.number="cleanup.inactivity_days" type="number" min="7" max="365" class="w-28" /><span class="text-sm">hari</span></div></label>
                        <Button size="sm" @click="saveCleanup">Simpan pengaturan</Button>
                        <Button size="sm" variant="outline" @click="runCleanup">Jalankan sekarang</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">Terakhir dijalankan: {{ cleanupSettings?.last_run_at ? date(cleanupSettings.last_run_at) : 'Belum pernah' }} · {{ Number(cleanupSettings?.last_deleted_count || 0).toLocaleString('id-ID') }} akun dibersihkan.</p>
                </div>
            </details>
        </div>

        <div v-show="tab === 'membership'" class="space-y-4">
            <p class="max-w-3xl text-sm text-muted-foreground">
                Atur syarat dan diskon tiap tingkat member. Diskon tetap dihitung sistem saat pelanggan membayar. Tingkat milik satu pelanggan diubah dari halaman detail pelanggan.
            </p>
            <p v-if="!canManageMembershipRules" role="status" class="rounded-md border border-border px-4 py-3 text-sm text-muted-foreground">Hanya admin dengan izin pengaturan yang dapat mengubah tingkat member.</p>
            <ul v-if="membershipRules.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="tier in membershipRules" :key="tier.code">
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40" @click="canManageMembershipRules ? editingTier = tier : null">
                        <span class="min-w-0 flex-1">
                            <strong class="block">{{ tier.rank }}. {{ tier.code }}</strong>
                            <small class="mt-0.5 block text-muted-foreground">Minimum {{ money(tier.minimum_spend_idr) }} · diskon {{ percent(tier.discount_percent) }}<template v-if="tier.benefit_notes"> · {{ tier.benefit_notes }}</template></small>
                        </span>
                        <span :class="['shrink-0 rounded border px-2 py-0.5 text-xs', tier.is_active ? 'border-emerald-500/40 text-emerald-400' : 'border-border text-muted-foreground']">{{ tier.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        <span v-if="canManageMembershipRules" class="shrink-0 text-xs text-muted-foreground">Ubah</span>
                    </button>
                    <Sheet :open="editingTier === tier" @update:open="(value) => { if (!value) editingTier = null; }">
                        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                            <SheetHeader><SheetTitle>Ubah tingkat {{ tier.code }}</SheetTitle><SheetDescription>Syarat, diskon, dan manfaat berlaku untuk semua pelanggan di tingkat ini.</SheetDescription></SheetHeader>
                            <div v-if="editingTier === tier" class="mt-4 space-y-4">
                                <label class="lf-admin-field"><span>Minimum total transaksi berhasil (Rp)</span><Input v-model.number="tier.minimum_spend_idr" type="number" min="0" max="1000000000000" step="1" /><span class="text-xs font-normal text-muted-foreground">Isi 0 bila tidak ada minimum.</span></label>
                                <label class="lf-admin-field"><span>Diskon untuk pelanggan tingkat ini (%)</span><Input v-model.number="tier.discount_percent" type="number" min="0" max="100" step="0.01" /><span class="text-xs font-normal text-muted-foreground">Maksimum 100%.</span></label>
                                <label class="lf-admin-field"><span>Manfaat tambahan</span><Input v-model="tier.benefit_notes" maxlength="1000" placeholder="Contoh: prioritas layanan" /></label>
                                <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="tier.is_active" />Tingkat ini dipakai</label>
                                <p v-if="tier.has_legacy_requirements || tier.has_legacy_benefits" class="rounded-md border border-border p-2 text-xs text-muted-foreground">Informasi tambahan dari versi sebelumnya tetap disimpan saat kamu menyimpan perubahan.</p>
                                <div class="flex gap-2 border-t border-border pt-4">
                                    <Button type="button" variant="outline" @click="editingTier = null">Tutup</Button>
                                    <Button type="button" class="ml-auto" :disabled="Boolean(saving)" @click="saveTier(tier)">{{ saving === 'tier' ? 'Menyimpan…' : 'Simpan' }}</Button>
                                </div>
                            </div>
                        </SheetContent>
                    </Sheet>
                </li>
            </ul>
            <p v-else class="rounded-md border border-border px-4 py-10 text-center text-sm text-muted-foreground">Belum ada tingkat member yang diatur.</p>
        </div>

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
