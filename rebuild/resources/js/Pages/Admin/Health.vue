<script setup>
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../Components/ui/table';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    checks: { type: Array, default: () => [] },
    integrations: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    environment: { type: Object, default: () => ({}) },
    checkedAt: { type: String, default: null },
});

const refreshing = ref(false);

const statusLabel = (status) => ({
    HEALTHY: 'Normal',
    CONFIGURED: 'Tersimpan — tes otomatis tidak tersedia',
    DEGRADED: 'Perlu diperiksa',
    DOWN: 'Bermasalah',
    STALE: 'Kedaluwarsa',
    MAINTENANCE: 'Maintenance',
    NOT_CONFIGURED: 'Nonaktif',
    UNTESTED: 'Belum dites',
    UNKNOWN: 'Belum diketahui',
}[status] || 'Belum diketahui');

const statusVariant = (status) => {
    if (status === 'DOWN') return 'destructive';
    if (status === 'HEALTHY') return 'secondary';
    return 'outline';
};

const gatewayKindLabel = (kind) => ({
    EXTERNAL: 'Eksternal',
    MANUAL: 'Manual',
    INTERNAL: 'Internal',
}[kind] || kind || '—');

const overallLabel = computed(() => statusLabel(props.summary?.overall));
const attentionChecks = computed(() => props.checks.filter(check => ['DOWN', 'DEGRADED', 'UNKNOWN', 'STALE'].includes(check.status)));
const orderedChecks = computed(() => [...props.checks].sort((a, b) => Number(attentionChecks.value.includes(b)) - Number(attentionChecks.value.includes(a))));

const attentionLabel = computed(() => attentionChecks.value.length
    ? 'Komponen bermasalah ditampilkan lebih dahulu.'
    : 'Tidak ada komponen yang memerlukan perhatian.');

const checkStatusDescription = check => check.status === 'HEALTHY' ? 'Normal' : statusLabel(check.status);

const formatDate = (value) => {
    if (!value) return 'Belum tersedia';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Belum tersedia';
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(date) + ' WIB';
};

function refresh() {
    if (refreshing.value) return;
    refreshing.value = true;
    router.reload({
        only: ['summary', 'checks', 'integrations', 'gateways', 'environment', 'checkedAt'],
        onFinish: () => { refreshing.value = false; },
    });
}
</script>

<template>
    <Head title="Kesehatan Sistem" />
    <AdminShell>
        <div class="space-y-5">
            <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-semibold tracking-tight">Kesehatan Sistem</h1>
                        <Badge :variant="statusVariant(summary.overall)">{{ overallLabel }}</Badge>
                    </div>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Halaman ini hanya membaca status yang sudah tersimpan; tidak menjalankan transaksi atau mengirim permintaan baru ke layanan luar. Status integrasi memakai hasil tes koneksi terakhir.
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Pemeriksaan terakhir {{ formatDate(checkedAt) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link href="/admin/integrations">Buka Integrasi</Link>
                    </Button>
                    <Button :disabled="refreshing" @click="refresh">
                        {{ refreshing ? 'Memeriksa…' : 'Muat ulang' }}
                    </Button>
                </div>
            </header>

            <div class="lf-admin-summary ">
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Komponen normal</p>
                    <p class="mt-2 text-2xl font-semibold">{{ summary.healthy_core ?? 0 }}/{{ summary.core_total ?? checks.length }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Perlu perhatian</p>
                    <p class="mt-2 text-2xl font-semibold">{{ summary.attention ?? 0 }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Job gagal</p>
                    <p class="mt-2 text-2xl font-semibold">{{ summary.failed_jobs ?? '—' }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Rekonsiliasi tertunda</p>
                    <p class="mt-2 text-2xl font-semibold">{{ summary.stale_fulfillment ?? '—' }}</p>
                </Card>
                <Card class="col-span-2 p-4 lg:col-span-1">
                    <p class="text-xs text-muted-foreground">Status sistem</p>
                    <p class="mt-2 text-lg font-semibold">{{ overallLabel }}</p>
                </Card>
            </div>

            <section class="border-b border-border pb-4" aria-labelledby="health-attention-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="health-attention-heading" class="text-lg font-semibold">Perlu perhatian</h2>
                    <p class="text-sm text-muted-foreground" role="status">{{ attentionLabel }}</p>
                </div>
                <ul v-if="attentionChecks.length" class="mt-3 divide-y divide-border">
                    <li v-for="check in attentionChecks" :key="check.key" class="flex flex-wrap items-start justify-between gap-2 py-3">
                        <div><strong class="text-sm">{{ check.name }}</strong><p class="mt-1 text-sm text-muted-foreground">{{ check.message }}</p></div>
                        <Badge :variant="statusVariant(check.status)">{{ checkStatusDescription(check) }}</Badge>
                    </li>
                </ul>
            </section>

            <section class="space-y-3" aria-labelledby="health-checks-heading">
                <div>
                    <h2 id="health-checks-heading" class="text-lg font-semibold">Semua komponen</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Aplikasi, database, penyimpanan sementara, penyimpanan berkas, pengolah latar belakang, penjadwal, pekerjaan gagal, dan proses yang perlu dicocokkan.</p>
                </div>
                <ul class="divide-y divide-border rounded-md border border-border">
                    <li v-for="check in orderedChecks" :key="check.key" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <strong class="text-sm">{{ check.name }}</strong>
                            <p class="mt-1 text-sm text-muted-foreground">{{ check.message }}</p>
                            <p v-if="check.detail" class="mt-1 break-words text-xs text-muted-foreground">{{ check.detail }}</p>
                        </div>
                        <Badge class="shrink-0" :variant="statusVariant(check.status)">{{ statusLabel(check.status) }}</Badge>
                    </li>
                    <li v-if="!orderedChecks.length" class="px-4 py-8 text-center text-sm text-muted-foreground">Belum ada komponen yang diperiksa.</li>
                </ul>
            </section>

            <section class="space-y-3" aria-labelledby="health-integrations-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="health-integrations-heading" class="text-lg font-semibold">Kesehatan integrasi</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Hanya status yang aman ditampilkan. Kunci akses, token, dan sandi layanan tidak pernah dikirim ke halaman ini.</p>
                    </div>
                    <Button size="sm" variant="outline" as-child>
                        <Link href="/admin/integrations">Kelola integrasi</Link>
                    </Button>
                </div>

                <div class="overflow-x-auto rounded-md border border-border" role="region" aria-label="Status integrasi" tabindex="0">
                    <AdminResponsiveTable :mobile-columns="[0,2,3]" class="min-w-[760px]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Integrasi</TableHead>
                                <TableHead>Kelompok</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Tes terakhir</TableHead>
                                <TableHead>Keterangan</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="item in integrations" :key="item.code">
                                <TableCell>
                                    <strong class="text-sm">{{ item.name }}</strong>
                                    <p class="text-xs text-muted-foreground">{{ item.active ? 'Aktif' : 'Nonaktif' }}</p>
                                </TableCell>
                                <TableCell>{{ item.group }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusVariant(item.status)">{{ item.status === 'HEALTHY' ? 'Tes terakhir berhasil' : statusLabel(item.status) }}</Badge>
                                </TableCell>
                                <TableCell class="text-xs">{{ formatDate(item.tested_at) }}</TableCell>
                                <TableCell class="max-w-sm text-xs text-muted-foreground">{{ item.message }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>
            </section>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(280px,1fr)]">
                <section class="space-y-3" aria-labelledby="health-gateways-heading">
                    <div>
                        <h2 id="health-gateways-heading" class="text-lg font-semibold">Gateway pembayaran</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Ringkasan status layanan pembayaran. Pengaturan cara bayar dan perawatan diatur di menu Pembayaran.</p>
                    </div>
                    <div class="overflow-x-auto rounded-md border border-border" role="region" aria-label="Status gateway pembayaran" tabindex="0">
                        <AdminResponsiveTable :mobile-columns="[0,1,2]" class="min-w-[560px]">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Gateway</TableHead>
                                    <TableHead>Jenis</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="gateway in gateways" :key="gateway.code">
                                    <TableCell>
                                        <strong class="text-sm">{{ gateway.name }}</strong>
                                        <p class="text-xs text-muted-foreground">{{ gateway.code }}</p>
                                    </TableCell>
                                    <TableCell>{{ gatewayKindLabel(gateway.kind) }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="statusVariant(gateway.status)">{{ statusLabel(gateway.status) }}</Badge>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="!gateways.length">
                                    <TableCell colspan="3" class="py-6 text-center text-sm text-muted-foreground">
                                        Status gateway belum dapat dibaca.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </AdminResponsiveTable>
                    </div>
                </section>

                <section class="space-y-3" aria-labelledby="health-env-heading">
                    <div>
                        <h2 id="health-env-heading" class="text-lg font-semibold">Lingkungan aplikasi</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Keterangan teknis tanpa data rahasia, untuk membantu pemeriksaan.</p>
                    </div>
                    <dl class="divide-y divide-border rounded-md border border-border text-sm">
                        <div class="flex justify-between gap-4 px-4 py-2">
                            <dt class="text-muted-foreground">Mode situs</dt>
                            <dd class="font-medium">{{ environment.app_env === 'production' ? 'Toko aktif' : 'Masa pengembangan' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2">
                            <dt class="text-muted-foreground">Pesan galat rinci</dt>
                            <dd class="font-medium">{{ environment.debug_enabled ? 'Ditampilkan' : 'Disembunyikan' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2">
                            <dt class="text-muted-foreground">Pengolah latar belakang</dt>
                            <dd class="font-medium">{{ environment.queue_connection || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2">
                            <dt class="text-muted-foreground">Penyimpanan sementara</dt>
                            <dd class="font-medium">{{ environment.cache_store || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2">
                            <dt class="text-muted-foreground">Sesi masuk</dt>
                            <dd class="font-medium">{{ environment.session_driver || '—' }}</dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </AdminShell>
</template>
