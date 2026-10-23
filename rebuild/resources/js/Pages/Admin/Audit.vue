<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { num, wibDateTime } from '../../lib/adminFormat';

const props = defineProps({
    logs: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    actors: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    targetTypes: { type: Array, default: () => [] },
});

const filter = reactive({
    q: props.filters?.q || '',
    actor_id: props.filters?.actor_id || '',
    role: props.filters?.role || '',
    action: props.filters?.action || '',
    target_type: props.filters?.target_type || '',
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
    per_page: Number(props.filters?.per_page || 50),
});

const query = () => Object.fromEntries(Object.entries(filter)
    .filter(([, value]) => value !== '' && value !== null && value !== undefined));
const apply = () => router.get('/admin/audit', query(), { preserveState: true, preserveScroll: true });
const reset = () => {
    Object.assign(filter, { q: '', actor_id: '', role: '', action: '', target_type: '', date_from: '', date_to: '', per_page: 50 });
    router.get('/admin/audit', {}, { preserveState: true, preserveScroll: true });
};

const rows = computed(() => (Array.isArray(props.logs?.data) ? props.logs.data : []));

const roleLabel = role => ({ SUPER_ADMIN: 'Super Admin', ADMIN: 'Admin', SYSTEM: 'Sistem' }[role] || 'Sistem');

// Alamat IP disamarkan: cukup blok pertama dan dua digit terakhir.
const maskIp = value => {
    if (!value) return '—';
    const parts = String(value).split('.');
    if (parts.length === 4) {
        return parts[0] + '.•••.••.' + parts[3].slice(-2);
    }
    return String(value).slice(0, 4) + '••••';
};

const detail = ref(null);
const hasState = value => value !== null && value !== undefined && !(typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0);

const pretty = value => {
    if (!hasState(value)) return 'Tidak ada detail perubahan';
    return JSON.stringify(value, null, 2);
};
</script>

<template>
    <Head title="Catatan Audit" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Catatan Audit</h1>
                <p>Setiap perubahan penting di panel dicatat: siapa, kapan, dan apa yang berubah.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Ringkasan catatan audit">
            <div class="adm-stat"><span class="lb">Total catatan</span><strong class="vl">{{ num(summary.total) }}</strong></div>
            <div class="adm-stat"><span class="lb">Hari ini</span><strong class="vl">{{ num(summary.today) }}</strong></div>
            <div class="adm-stat"><span class="lb">7 hari terakhir</span><strong class="vl">{{ num(summary.last_7_days) }}</strong></div>
            <div class="adm-stat"><span class="lb">Admin tercatat</span><strong class="vl">{{ num(summary.actors) }}</strong></div>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari catatan</span>
                    <input v-model="filter.q" class="adm-input" maxlength="100" placeholder="Aktivitas, sasaran, admin, atau IP">
                </label>
                <label class="adm-field narrow">
                    <span>Admin</span>
                    <select v-model="filter.actor_id" class="adm-select">
                        <option value="">Semua admin</option>
                        <option v-for="actor in actors" :key="actor.id" :value="actor.id">{{ actor.name }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Peran</span>
                    <select v-model="filter.role" class="adm-select">
                        <option value="">Semua peran</option>
                        <option value="SUPER_ADMIN">Super Admin</option>
                        <option value="ADMIN">Admin</option>
                        <option value="SYSTEM">Sistem</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary">Terapkan</button>
                    <button type="button" class="adm-btn" @click="reset">Hapus filter</button>
                </div>
                <label class="adm-field narrow">
                    <span>Aksi</span>
                    <select v-model="filter.action" class="adm-select">
                        <option value="">Semua aksi</option>
                        <option v-for="item in actions" :key="item.value" :value="item.value">{{ item.label }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Sasaran</span>
                    <select v-model="filter.target_type" class="adm-select">
                        <option value="">Semua sasaran</option>
                        <option v-for="item in targetTypes" :key="item.value" :value="item.value">{{ item.label }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Dari tanggal</span>
                    <input v-model="filter.date_from" type="date" class="adm-input">
                </label>
                <label class="adm-field narrow">
                    <span>Sampai tanggal</span>
                    <input v-model="filter.date_to" type="date" class="adm-input">
                </label>
                <label class="adm-field narrow">
                    <span>Catatan per halaman</span>
                    <select v-model.number="filter.per_page" class="adm-select">
                        <option v-for="size in [25, 50, 100]" :key="size" :value="size">{{ size }} catatan</option>
                    </select>
                </label>
            </form>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr><th>Waktu</th><th>Admin</th><th>Aktivitas</th><th>Sasaran</th><th>IP</th><th>Detail</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td class="adm-note">{{ wibDateTime(row.created_at) }}</td>
                            <td>
                                {{ row.actor?.name || 'Sistem' }}
                                <div class="adm-note">{{ roleLabel(row.actor?.role) }}</div>
                            </td>
                            <td><strong>{{ row.action_label || row.action }}</strong><div class="adm-note">{{ row.action }}</div></td>
                            <td>
                                {{ row.target_label || row.target_type }}
                                <div v-if="row.target_id" class="adm-note">#{{ row.target_id }}</div>
                            </td>
                            <td class="adm-note">{{ maskIp(row.ip_address) }}</td>
                            <td>
                                <button v-if="hasState(row.before_state) || hasState(row.after_state)" type="button" class="adm-btn sm" @click="detail = row">
                                    Lihat
                                </button>
                                <span v-else class="adm-note">Tidak ada detail</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.length" class="adm-empty">Tidak ada catatan yang sesuai filter.</p>
            <div class="adm-pager">
                <span>Menampilkan {{ logs.from || 0 }} dari {{ num(logs.total) }} catatan · Halaman {{ logs.current_page || 1 }} dari {{ logs.last_page || 1 }}</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!logs.prev_page_url" @click="router.get(logs.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!logs.next_page_url" @click="router.get(logs.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>

        <AdminModal :open="!!detail" :title="detail ? (detail.action_label || detail.action) : ''"
            :description="detail ? wibDateTime(detail.created_at) + ' · ' + (detail.actor?.name || 'Sistem') : ''"
            @update:open="value => { if (!value) detail = null; }">
            <div v-if="detail">
                <dl class="adm-kv">
                    <dt>Aktivitas</dt><dd>{{ detail.action_label || detail.action }}</dd>
                    <dt>Sasaran</dt><dd>{{ detail.target_label || detail.target_type }}<template v-if="detail.target_id"> #{{ detail.target_id }}</template></dd>
                    <dt>Admin</dt><dd>{{ detail.actor?.name || 'Sistem' }} · {{ roleLabel(detail.actor?.role) }}</dd>
                    <dt>IP</dt><dd>{{ maskIp(detail.ip_address) }}</dd>
                    <dt>Perangkat</dt><dd style="word-break:break-word">{{ detail.user_agent || 'Tidak tercatat' }}</dd>
                    <dt>Kode korelasi</dt><dd><code class="adm-code">{{ detail.correlation_id || '—' }}</code></dd>
                </dl>
                <div class="adm-block full" style="margin-top:10px">
                    <strong>Sebelum</strong>
                    <pre class="adm-code" style="white-space:pre-wrap">{{ pretty(detail.before_state) }}</pre>
                </div>
                <div class="adm-block full" style="margin-top:8px">
                    <strong>Sesudah</strong>
                    <pre class="adm-code" style="white-space:pre-wrap">{{ pretty(detail.after_state) }}</pre>
                </div>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="detail = null">Tutup</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
