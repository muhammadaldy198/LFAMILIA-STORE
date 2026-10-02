<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../Components/ui/table';

const props = defineProps({
    logs: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    actors: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    targetTypes: { type: Array, default: () => [] },
});

const form = reactive({
    q: props.filters?.q || '',
    actor_id: props.filters?.actor_id ? String(props.filters.actor_id) : '',
    role: props.filters?.role || '',
    action: props.filters?.action || '',
    target_type: props.filters?.target_type || '',
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
    per_page: String(props.filters?.per_page || 50),
});

const selectedId = ref(null);
const rows = computed(() => props.logs?.data || []);

const roleLabel = (role) => ({
    SUPER_ADMIN: 'Super Admin',
    ADMIN: 'Admin',
    SYSTEM: 'Sistem',
}[role] || 'Tidak diketahui');

const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('id-ID');
};

const formatState = (value) => {
    if (value === null || value === undefined) return 'Tidak ada data.';
    if (typeof value === 'string') return value;
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return 'Data tidak dapat ditampilkan.';
    }
};

const applyFilters = () => {
    const payload = {};
    Object.entries(form).forEach(([key, value]) => {
        if (String(value ?? '').trim() !== '') payload[key] = value;
    });

    router.get('/admin/audit', payload, {
        preserveState: true,
        replace: true,
    });
};

const resetFilters = () => {
    form.q = '';
    form.actor_id = '';
    form.role = '';
    form.action = '';
    form.target_type = '';
    form.date_from = '';
    form.date_to = '';
    form.per_page = '50';
    router.get('/admin/audit', {}, { preserveState: false, replace: true });
};

const toggleDetail = (id) => {
    selectedId.value = selectedId.value === id ? null : id;
};
</script>

<template>
    <Head title="Audit Log" />

    <AdminShell>
        <div class="space-y-5">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-semibold">Audit Log</h1>
                        <Badge variant="outline">Khusus Super Admin</Badge>
                    </div>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Riwayat perubahan penting di panel Admin. Data bersifat baca-saja dan nilai rahasia disanitasi kembali sebelum dikirim ke halaman ini.
                    </p>
                </div>
                <Button variant="outline" as-child>
                    <Link href="/admin/health">System Health</Link>
                </Button>
            </header>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Total log</p>
                    <p class="mt-2 text-2xl font-semibold">{{ Number(summary.total || 0).toLocaleString('id-ID') }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Hari ini</p>
                    <p class="mt-2 text-2xl font-semibold">{{ Number(summary.today || 0).toLocaleString('id-ID') }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">7 hari terakhir</p>
                    <p class="mt-2 text-2xl font-semibold">{{ Number(summary.last_7_days || 0).toLocaleString('id-ID') }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Admin tercatat</p>
                    <p class="mt-2 text-2xl font-semibold">{{ Number(summary.actors || 0).toLocaleString('id-ID') }}</p>
                </Card>
            </div>

            <Card class="p-4 md:p-5">
                <div>
                    <h2 class="text-lg font-semibold">Filter Aktivitas</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cari berdasarkan aksi, target, correlation ID, IP, nama Admin, atau email Admin.
                    </p>
                </div>

                <form class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyFilters">
                    <label class="space-y-1.5 text-sm xl:col-span-2">
                        <span>Pencarian</span>
                        <Input v-model="form.q" maxlength="100" placeholder="Contoh: pembayaran, invoice, correlation ID..." />
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Admin</span>
                        <select v-model="form.actor_id" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua Admin</option>
                            <option v-for="actor in actors" :key="actor.id" :value="String(actor.id)">
                                {{ actor.name }} · {{ roleLabel(actor.role) }}{{ actor.is_active ? '' : ' · Nonaktif' }}
                            </option>
                        </select>
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Peran pelaku</span>
                        <select v-model="form.role" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua peran</option>
                            <option value="SUPER_ADMIN">Super Admin</option>
                            <option value="ADMIN">Admin</option>
                            <option value="SYSTEM">Sistem</option>
                        </select>
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Aksi</span>
                        <select v-model="form.action" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua aksi</option>
                            <option v-for="item in actions" :key="item.value" :value="item.value">{{ item.label }}</option>
                        </select>
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Target</span>
                        <select v-model="form.target_type" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua target</option>
                            <option v-for="item in targetTypes" :key="item.value" :value="item.value">{{ item.label }}</option>
                        </select>
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Dari tanggal</span>
                        <Input v-model="form.date_from" type="date" />
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Sampai tanggal</span>
                        <Input v-model="form.date_to" type="date" />
                    </label>

                    <label class="space-y-1.5 text-sm">
                        <span>Baris per halaman</span>
                        <select v-model="form.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                            <option value="25">25 baris</option>
                            <option value="50">50 baris</option>
                            <option value="100">100 baris</option>
                        </select>
                    </label>

                    <div class="flex items-end gap-2 md:col-span-2 xl:col-span-3">
                        <Button type="submit">Terapkan Filter</Button>
                        <Button type="button" variant="outline" @click="resetFilters">Reset</Button>
                    </div>
                </form>
            </Card>

            <Card class="overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b p-4 md:p-5">
                    <div>
                        <h2 class="text-lg font-semibold">Riwayat Aktivitas</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ Number(logs?.total || 0).toLocaleString('id-ID') }} hasil sesuai filter.
                        </p>
                    </div>
                    <p class="text-xs text-muted-foreground">Urutan terbaru lebih dulu</p>
                </div>

                <div class="overflow-x-auto">
                    <Table class="min-w-[1040px]">
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-[15%]">Waktu</TableHead>
                                <TableHead class="w-[18%]">Admin</TableHead>
                                <TableHead class="w-[24%]">Aktivitas</TableHead>
                                <TableHead class="w-[18%]">Target</TableHead>
                                <TableHead class="w-[13%]">IP</TableHead>
                                <TableHead class="w-[12%] text-right">Detail</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            <template v-for="row in rows" :key="row.id">
                                <TableRow>
                                    <TableCell class="align-top text-xs">
                                        <strong class="block text-foreground">{{ formatDate(row.created_at) }}</strong>
                                        <span class="mt-1 block text-muted-foreground">#{{ row.id }}</span>
                                    </TableCell>

                                    <TableCell class="align-top">
                                        <strong class="block break-words text-sm">{{ row.actor?.name || 'Sistem' }}</strong>
                                        <span v-if="row.actor?.email" class="mt-1 block break-all text-xs text-muted-foreground">{{ row.actor.email }}</span>
                                        <Badge class="mt-1" variant="outline">{{ roleLabel(row.actor?.role) }}</Badge>
                                    </TableCell>

                                    <TableCell class="align-top">
                                        <strong class="block break-words text-sm">{{ row.action_label }}</strong>
                                        <span class="mt-1 block break-all font-mono text-[11px] text-muted-foreground">{{ row.action }}</span>
                                    </TableCell>

                                    <TableCell class="align-top">
                                        <strong class="block text-sm">{{ row.target_label }}</strong>
                                        <span class="mt-1 block break-all text-xs text-muted-foreground">
                                            {{ row.target_id ? 'ID ' + row.target_id : 'Tanpa ID target' }}
                                        </span>
                                    </TableCell>

                                    <TableCell class="align-top break-all text-xs">{{ row.ip_address || '—' }}</TableCell>

                                    <TableCell class="align-top text-right">
                                        <Button type="button" size="sm" variant="outline" @click="toggleDetail(row.id)">
                                            {{ selectedId === row.id ? 'Tutup' : 'Lihat Detail' }}
                                        </Button>
                                    </TableCell>
                                </TableRow>

                                <TableRow v-if="selectedId === row.id">
                                    <TableCell colspan="6" class="bg-muted/20 p-4">
                                        <div class="grid gap-4 xl:grid-cols-2">
                                            <section class="min-w-0 rounded-md border bg-background p-3">
                                                <h3 class="text-sm font-semibold">Sebelum</h3>
                                                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words text-xs text-muted-foreground">{{ formatState(row.before_state) }}</pre>
                                            </section>
                                            <section class="min-w-0 rounded-md border bg-background p-3">
                                                <h3 class="text-sm font-semibold">Sesudah</h3>
                                                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words text-xs text-muted-foreground">{{ formatState(row.after_state) }}</pre>
                                            </section>
                                        </div>

                                        <dl class="mt-4 grid gap-3 text-xs md:grid-cols-2 xl:grid-cols-3">
                                            <div class="min-w-0">
                                                <dt class="text-muted-foreground">Correlation ID</dt>
                                                <dd class="mt-1 break-all font-mono">{{ row.correlation_id || '—' }}</dd>
                                            </div>
                                            <div class="min-w-0">
                                                <dt class="text-muted-foreground">Alamat IP</dt>
                                                <dd class="mt-1 break-all">{{ row.ip_address || '—' }}</dd>
                                            </div>
                                            <div class="min-w-0 md:col-span-2 xl:col-span-1">
                                                <dt class="text-muted-foreground">Perangkat / browser</dt>
                                                <dd class="mt-1 break-words">{{ row.user_agent || '—' }}</dd>
                                            </div>
                                        </dl>
                                    </TableCell>
                                </TableRow>
                            </template>

                            <TableRow v-if="!rows.length">
                                <TableCell colspan="6" class="py-10 text-center text-sm text-muted-foreground">
                                    Tidak ada aktivitas yang sesuai dengan filter.
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <nav v-if="logs?.links?.length > 3" class="flex flex-wrap gap-2 border-t p-4" aria-label="Halaman Audit Log">
                    <template v-for="link in logs.links" :key="link.label">
                        <Button v-if="link.url" :variant="link.active ? 'default' : 'outline'" size="sm" as-child>
                            <Link :href="link.url" preserve-state v-html="link.label" />
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled v-html="link.label" />
                    </template>
                </nav>
            </Card>

            <Card class="p-4 md:p-5">
                <h2 class="text-base font-semibold">Catatan Keamanan</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Audit Log tidak dapat diedit atau dihapus dari panel. Password, token, API key, secret, signature, credential, dan field sensitif lain disensor saat pencatatan serta disanitasi kembali ketika halaman ini dibuka.
                </p>
            </Card>
        </div>
    </AdminShell>
</template>
