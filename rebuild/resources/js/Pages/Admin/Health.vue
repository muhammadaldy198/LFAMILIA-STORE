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
    DEGRADED: 'Perlu diperiksa',
    DOWN: 'Bermasalah',
    STALE: 'Hasil tes lama',
    MANUAL_CHECK: 'Tes otomatis tidak tersedia',
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

const formatDate = (value) => {
    if (!value) return 'Belum tersedia';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Belum tersedia';
    return date.toLocaleString('id-ID');
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
    <Head title="System Health" />
    <AdminShell>
        <div class="space-y-5">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-semibold">System Health</h1>
                        <Badge :variant="statusVariant(summary.overall)">{{ overallLabel }}</Badge>
                    </div>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Pantau kesehatan aplikasi dan antrean tanpa menjalankan transaksi atau mengirim request baru ke provider.
                        Status integrasi menggunakan hasil Tes Koneksi terakhir yang tersimpan.
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
                        {{ refreshing ? 'Memeriksa…' : 'Muat Ulang' }}
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

            <Card class="p-4 md:p-5">
                <div>
                    <h2 class="text-lg font-semibold">Komponen Inti</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Aplikasi, database, Redis, storage, worker, scheduler, failed jobs, dan proses yang perlu rekonsiliasi.
                    </p>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <article v-for="check in checks" :key="check.key" class="rounded-md border p-3">
                        <div class="flex items-start justify-between gap-3">
                            <strong class="text-sm">{{ check.name }}</strong>
                            <Badge :variant="statusVariant(check.status)">{{ statusLabel(check.status) }}</Badge>
                        </div>
                        <p class="mt-2 text-sm">{{ check.message }}</p>
                        <p v-if="check.detail" class="mt-1 break-words text-xs text-muted-foreground">{{ check.detail }}</p>
                    </article>
                </div>
            </Card>

            <Card class="p-4 md:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Kesehatan Integrasi</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Hanya status aman yang ditampilkan. API key, token, secret, dan credential tidak dikirim ke halaman ini.
                        </p>
                    </div>
                    <Button size="sm" variant="outline" as-child>
                        <Link href="/admin/integrations">Kelola Integrasi</Link>
                    </Button>
                </div>

                <div class="mt-4 overflow-x-auto rounded-md border">
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
                                    <Badge :variant="statusVariant(item.status)">{{ statusLabel(item.status) }}</Badge>
                                </TableCell>
                                <TableCell class="text-xs">{{ formatDate(item.tested_at) }}</TableCell>
                                <TableCell class="max-w-sm text-xs text-muted-foreground">{{ item.message }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </AdminResponsiveTable>
                </div>
            </Card>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(280px,1fr)]">
                <Card class="p-4 md:p-5">
                    <div>
                        <h2 class="text-lg font-semibold">Gateway Pembayaran</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Ringkasan status operasional gateway. Routing dan maintenance tetap dikelola dari menu Pembayaran.
                        </p>
                    </div>
                    <div class="mt-4 overflow-x-auto rounded-md border">
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
                </Card>

                <Card class="p-4 md:p-5">
                    <h2 class="text-lg font-semibold">Lingkungan Aplikasi</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Informasi operasional non-secret untuk membantu diagnosis.
                    </p>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4 border-b pb-2">
                            <dt class="text-muted-foreground">Environment</dt>
                            <dd class="font-medium">{{ environment.app_env || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-b pb-2">
                            <dt class="text-muted-foreground">Debug</dt>
                            <dd class="font-medium">{{ environment.debug_enabled ? 'Aktif' : 'Nonaktif' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-b pb-2">
                            <dt class="text-muted-foreground">Queue</dt>
                            <dd class="font-medium">{{ environment.queue_connection || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-b pb-2">
                            <dt class="text-muted-foreground">Cache</dt>
                            <dd class="font-medium">{{ environment.cache_store || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Session</dt>
                            <dd class="font-medium">{{ environment.session_driver || '—' }}</dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </AdminShell>
</template>
