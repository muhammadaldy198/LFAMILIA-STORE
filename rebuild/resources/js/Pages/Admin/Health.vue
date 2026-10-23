<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { num, wibDateTime } from '../../lib/adminFormat';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    checks: { type: Array, default: () => [] },
    integrations: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    environment: { type: Object, default: () => ({}) },
    checkedAt: { type: String, default: null },
});

const tone = status => ({
    HEALTHY: 'ok', DEGRADED: 'warn', DOWN: 'bad', UNKNOWN: 'plain',
    CONFIGURED: 'warn', UNVERIFIED: 'warn', NOT_TESTED: 'warn', NOT_CONFIGURED: 'bad',
}[status] || 'plain');

const statusLabel = status => ({
    HEALTHY: 'Normal', DEGRADED: 'Terbatas', DOWN: 'Gangguan', UNKNOWN: 'Belum diperiksa',
    CONFIGURED: 'Sudah diatur', UNVERIFIED: 'Belum dipastikan', NOT_TESTED: 'Belum diuji',
    NOT_CONFIGURED: 'Belum diatur',
}[status] || 'Belum diketahui');

const overall = computed(() => ({
    HEALTHY: { label: 'Semua sistem normal', tone: 'ok' },
    DEGRADED: { label: 'Ada yang perlu diperhatikan', tone: 'warn' },
    DOWN: { label: 'Ada gangguan', tone: 'bad' },
}[props.summary.overall] || { label: 'Belum diperiksa', tone: 'plain' }));

const attention = computed(() => [
    ...props.checks.filter(check => check.status !== 'HEALTHY').map(check => ({
        key: 'check-' + check.key, name: check.name, message: check.message, status: check.status,
    })),
    ...props.integrations.filter(item => item.active && !['HEALTHY', 'CONFIGURED'].includes(item.status)).map(item => ({
        key: 'integration-' + item.code, name: item.name, message: item.message, status: item.status,
    })),
    ...props.gateways.filter(item => item.active && item.status !== 'HEALTHY').map(item => ({
        key: 'gateway-' + item.code, name: item.name, message: item.message, status: item.status,
    })),
]);

const reload = () => router.reload({ preserveScroll: true });
</script>

<template>
    <Head title="Kesehatan Sistem" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Kesehatan Sistem</h1>
                <p>Pemeriksaan langsung ke database, penyimpanan sementara, pengolah latar belakang, dan layanan luar.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="reload">Muat ulang</button>
                <a href="/admin/integrations" class="adm-btn primary">Buka Integrasi</a>
            </div>
        </header>

        <p class="adm-alert" :class="overall.tone" style="margin-bottom:12px">
            <strong>{{ overall.label }}</strong> · diperiksa {{ wibDateTime(checkedAt) || 'belum pernah' }}
        </p>

        <section class="adm-stats" aria-label="Ringkasan kesehatan">
            <div class="adm-stat"><span class="lb">Komponen normal</span><strong class="vl">{{ num(summary.healthy_core) }}<span style="font-size:13px;font-weight:400"> / {{ num(summary.core_total) }}</span></strong></div>
            <div class="adm-stat"><span class="lb">Perlu perhatian</span><strong class="vl" :style="{ color: Number(summary.attention) ? 'var(--a-warn)' : 'inherit' }">{{ num(summary.attention) }}</strong></div>
            <div class="adm-stat"><span class="lb">Antrean gagal</span><strong class="vl" :style="{ color: Number(summary.failed_jobs) ? 'var(--a-danger)' : 'inherit' }">{{ num(summary.failed_jobs) }}</strong></div>
            <div class="adm-stat"><span class="lb">Rekonsiliasi tertunda</span><strong class="vl">{{ num(summary.stale_fulfillment) }}</strong></div>
            <div class="adm-stat"><span class="lb">Integrasi aktif</span><strong class="vl">{{ num(integrations.filter(item => item.active).length) }}</strong><span class="nt">dari {{ num(integrations.length) }} terdaftar</span></div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Perlu perhatian</h2></div></div>
            <div class="adm-card-body" style="display:grid;gap:0">
                <div v-for="item in attention" :key="item.key" class="adm-list-row">
                    <div>
                        <strong>{{ item.name }}</strong>
                        <div class="adm-note">{{ item.message }}</div>
                    </div>
                    <span class="adm-tag" :class="tone(item.status)">{{ statusLabel(item.status) }}</span>
                </div>
                <p v-if="!attention.length" class="adm-note">Semua komponen berstatus normal.</p>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Pemeriksaan komponen</h2></div></div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Komponen</th><th>Status</th><th>Keterangan</th><th>Diperiksa</th></tr></thead>
                    <tbody>
                        <tr v-for="check in checks" :key="check.key">
                            <td><strong>{{ check.name }}</strong></td>
                            <td><span class="adm-tag" :class="tone(check.status)">{{ statusLabel(check.status) }}</span></td>
                            <td class="adm-note">{{ check.message }}</td>
                            <td class="adm-note">{{ wibDateTime(check.checked_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!checks.length" class="adm-empty">Belum ada pemeriksaan.</p>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Kesehatan integrasi</h2></div></div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Integrasi</th><th>Grup</th><th>Aktif</th><th>Status</th><th>Keterangan</th><th>Terakhir dites</th></tr></thead>
                    <tbody>
                        <tr v-for="item in integrations" :key="item.code">
                            <td><strong>{{ item.name }}</strong></td>
                            <td class="adm-note">{{ item.group }}</td>
                            <td><span class="adm-tag" :class="item.active ? 'ok' : 'bad'">{{ item.active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><span class="adm-tag" :class="tone(item.status)">{{ statusLabel(item.status) }}</span></td>
                            <td class="adm-note">{{ item.message }}</td>
                            <td class="adm-note">{{ wibDateTime(item.tested_at) || 'Belum pernah' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!integrations.length" class="adm-empty">Belum ada integrasi terdaftar.</p>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Gateway pembayaran</h2></div></div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Gateway</th><th>Aktif</th><th>Status</th><th>Keterangan</th></tr></thead>
                    <tbody>
                        <tr v-for="gateway in gateways" :key="gateway.code">
                            <td><strong>{{ gateway.name }}</strong></td>
                            <td><span class="adm-tag" :class="gateway.active ? 'ok' : 'bad'">{{ gateway.active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td><span class="adm-tag" :class="tone(gateway.status)">{{ statusLabel(gateway.status) }}</span></td>
                            <td class="adm-note">{{ gateway.message }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!gateways.length" class="adm-empty">Belum ada gateway pembayaran.</p>
        </section>

        <section class="adm-card">
            <div class="adm-card-head"><div><h2>Lingkungan aplikasi</h2></div></div>
            <div class="adm-card-body">
                <dl class="adm-kv">
                    <dt>Lingkungan</dt><dd>{{ environment.app_env || '—' }}</dd>
                    <dt>Mode debug</dt><dd><span class="adm-tag" :class="environment.debug_enabled ? 'warn' : 'ok'">{{ environment.debug_enabled ? 'Menyala' : 'Mati' }}</span></dd>
                    <dt>Pengolah latar belakang</dt><dd>{{ environment.queue_connection || '—' }}</dd>
                    <dt>Penyimpanan sementara</dt><dd>{{ environment.cache_store || '—' }}</dd>
                    <dt>Sesi</dt><dd>{{ environment.session_driver || '—' }}</dd>
                </dl>
            </div>
        </section>
    </AdminLayout>
</template>
