<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { num, rupiah, wibDateTime } from '../../lib/adminFormat';

const props = defineProps({
    providers: { type: Array, default: () => [] },
    mappings: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const rows = ref(props.providers.map(row => ({ ...row })));
watch(() => props.providers, value => { rows.value = value.map(row => ({ ...row })); }, { deep: true });

const filter = reactive({
    q: props.filters?.q || '', provider: Number(props.filters?.provider || 0),
    status: props.filters?.status || '', per_page: Number(props.filters?.per_page || 25),
});
const advanced = ref(false);
const apply = () => {
    router.get('/admin/providers', {
        q: filter.q || undefined, provider: filter.provider || undefined,
        status: filter.status || undefined, per_page: filter.per_page,
    }, { preserveState: true, preserveScroll: true });
};
const reset = () => {
    Object.assign(filter, { q: '', provider: 0, status: '', per_page: 25 });
    apply();
};

const modeLabel = value => ({ AUTO_PROVIDER: 'Otomatis', MANUAL: 'Manual' }[value] || 'Lainnya');
const healthLabel = value => ({
    HEALTHY: 'Sehat', READY: 'Siap', UNTESTED: 'Belum diuji', STALE: 'Perlu diuji ulang',
    DOWN: 'Bermasalah', NOT_CONFIGURED: 'Belum dikonfigurasi', INACTIVE: 'Nonaktif',
}[value] || 'Belum diketahui');
const healthTone = value => value === 'DOWN' ? 'bad' : ['HEALTHY', 'READY'].includes(value) ? 'ok' : 'warn';

const draft = ref(null);
const saving = ref(false);
const edit = row => { draft.value = { ...row }; };
const save = () => {
    const row = draft.value;
    saving.value = true;
    router.put('/admin/providers/' + row.id, {
        display_name: row.display_name,
        description: row.description || null,
        sort_order: Number(row.sort_order || 0),
        is_active: Boolean(row.is_active),
    }, {
        preserveScroll: true,
        onSuccess: () => { draft.value = null; },
        onFinish: () => { saving.value = false; },
    });
};

const links = computed(() => (props.mappings?.links || []).filter(link => link.url));
</script>

<template>
    <Head title="Penyedia layanan" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Penyedia layanan</h1>
                <p>Aktifkan penyedia layanan, atur nama dan urutannya, lalu pantau nominal yang terhubung. Data akses penyedia dikelola di menu Integrasi.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/integrations" class="adm-btn">Buka Integrasi</Link>
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
            </div>
        </header>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Daftar penyedia</h2>
                </div>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>Penyedia / keterangan</th><th>Jenis layanan</th><th>Koneksi &amp; status</th>
                            <th>Nominal tertaut</th><th>Performa 24 jam</th><th>Urutan</th><th>Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>
                                <strong>{{ row.display_name }}</strong>
                                <div class="adm-note">{{ row.description || 'Tanpa keterangan' }}</div>
                            </td>
                            <td>
                                <span class="adm-tag plain">{{ modeLabel(row.fulfillment_mode) }}</span>
                                <div class="adm-note">Posisi {{ row.sort_order }}</div>
                            </td>
                            <td>
                                <span class="adm-tag" :class="healthTone(row.health?.status)">{{ healthLabel(row.health?.status) }}</span>
                                <div v-if="row.health?.message" class="adm-note">{{ row.health.message }}</div>
                            </td>
                            <td>
                                <strong>{{ row.active_mapping_count }}</strong> / {{ row.mapping_count }} aktif
                                <div class="adm-note">Terakhir: {{ wibDateTime(row.last_attempt_at) || 'Belum ada' }}</div>
                            </td>
                            <td>
                                <span style="color:var(--a-ok);font-weight:600">{{ num(row.success_24h) }} sukses</span>
                                <div v-if="row.attention_count" class="adm-note" style="color:var(--a-danger)">{{ row.attention_count }} perhatian</div>
                            </td>
                            <td class="mono">{{ row.sort_order }}</td>
                            <td>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                    <span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                    <button type="button" class="adm-btn sm" @click="edit(row)">Ubah</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.length" class="adm-empty">Belum ada penyedia layanan.</p>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Nominal produk yang dilayani penyedia</h2>
                </div>
                <Link href="/admin/catalog" class="adm-btn">Kelola di Produk</Link>
            </div>
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari nominal</span>
                    <input v-model="filter.q" class="adm-input" maxlength="100" placeholder="Produk, nominal, kode penyedia, atau penyedia">
                </label>
                <label class="adm-field narrow">
                    <span>Penyedia</span>
                    <select v-model.number="filter.provider" class="adm-select">
                        <option :value="0">Semua penyedia</option>
                        <option v-for="row in providers" :key="row.id" :value="row.id">{{ row.display_name }}</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary">Terapkan</button>
                    <button type="button" class="adm-btn" @click="reset">Hapus filter</button>
                </div>
                <div style="flex:1 0 100%">
                    <button type="button" class="adm-btn link" @click="advanced = !advanced">Filter lanjutan</button>
                </div>
                <template v-if="advanced">
                    <label class="adm-field narrow">
                        <span>Status nominal</span>
                        <select v-model="filter.status" class="adm-select">
                            <option value="">Semua status</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </label>
                    <label class="adm-field narrow">
                        <span>Nominal per halaman</span>
                        <select v-model.number="filter.per_page" class="adm-select">
                            <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} nominal</option>
                        </select>
                    </label>
                </template>
            </form>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>Produk</th><th>Nominal</th><th>Penyedia &amp; kode</th>
                            <th style="text-align:right">Modal / Batas</th><th>Pembaruan harga</th><th>Sumber</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in mappings.data" :key="row.id">
                            <td>
                                <strong>{{ row.product_name }}</strong>
                                <div class="adm-note">{{ row.category_name }}</div>
                            </td>
                            <td>
                                {{ row.package_name }}
                                <div class="adm-note">{{ row.package_code }}</div>
                            </td>
                            <td>
                                <strong>{{ row.provider_name || row.provider_code }}</strong>
                                <div class="mono adm-note">{{ row.external_sku || '—' }}</div>
                                <div v-if="!row.provider_active" class="adm-note" style="color:var(--a-danger)">Nonaktif</div>
                            </td>
                            <td style="text-align:right">
                                <strong>{{ rupiah(row.cost_idr) }}</strong>
                                <div class="adm-note">Maks: {{ rupiah(row.max_price_idr) }}</div>
                            </td>
                            <td>{{ row.provider_code === 'DIGIFLAZZ' && row.is_manual_price ? 'Manual' : 'Otomatis' }}</td>
                            <td>{{ row.source_position ? 'Posisi ' + row.source_position : '—' }}</td>
                            <td>
                                <span class="adm-tag" :class="row.is_active && row.product_active && row.package_active && row.provider_active ? 'ok' : 'bad'">
                                    {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!mappings.data?.length" class="adm-empty">Belum ada nominal produk yang sesuai dengan filter.</p>
            <div v-if="links.length > 1" class="adm-pager">
                <span>{{ mappings.from || 0 }}–{{ mappings.to || 0 }} dari {{ num(mappings.total) }} nominal</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!mappings.prev_page_url" @click="router.get(mappings.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!mappings.next_page_url" @click="router.get(mappings.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>

        <AdminModal v-model:open="draft" :title="'Ubah ' + (draft?.display_name || 'penyedia')"
            description="Nama tampilan dan urutan pemrosesan penyedia. Perubahan berlaku untuk pesanan baru."
            @update:open="value => { if (!value) draft = null; }">
            <div v-if="draft" class="adm-grid">
                <label class="adm-field">
                    <span>Nama tampilan</span>
                    <input v-model="draft.display_name" class="adm-input" maxlength="80">
                </label>
                <label class="adm-field">
                    <span>Urutan</span>
                    <input v-model.number="draft.sort_order" type="number" min="0" max="9999" class="adm-input">
                    <span class="hint">Angka lebih kecil diproses lebih dulu.</span>
                </label>
                <label class="adm-field full">
                    <span>Keterangan</span>
                    <input v-model="draft.description" class="adm-input" maxlength="500">
                </label>
                <label class="adm-switch-row full">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="draft.is_active" @click="draft.is_active = !draft.is_active" />
                    Aktif
                </label>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="draft = null">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="saving" @click="save">{{ saving ? 'Menyimpan…' : 'Simpan penyedia' }}</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
