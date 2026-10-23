<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { num, rupiah, wibDateTime, tone } from '../../lib/adminFormat';

const props = defineProps({
    items: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    connection: { type: Object, default: () => ({}) },
    lastSyncedAt: String,
    catalogSync: { type: Object, default: () => ({}) },
    mappingCount: { type: Number, default: 0 },
    providerActive: Boolean,
    autoSync: Boolean,
    monitorSettings: { type: Object, default: () => ({}) },
    recentTransactions: { type: Array, default: () => [] },
    canSeeBalance: Boolean,
});

const rows = computed(() => (Array.isArray(props.items?.data) ? props.items.data : []));
const meta = computed(() => props.items || {});
const current = reactive({
    q: props.filters?.q || '', category: props.filters?.category || '', brand: props.filters?.brand || '',
    product: props.filters?.product || '', health: props.filters?.health || '',
    scope: props.filters?.scope || 'all', per_page: props.filters?.per_page || 25,
});
const advanced = ref(false);

const apply = () => {
    const query = Object.fromEntries(Object.entries(current).filter(([, value]) => value !== '' && value !== null));
    router.get('/admin/digiflazz', query, { preserveState: true, preserveScroll: true });
};
const reset = () => {
    Object.assign(current, { q: '', category: '', brand: '', product: '', health: '', scope: 'all', per_page: 25 });
    apply();
};
const filterBy = (health) => {
    current.health = current.health === health ? '' : health;
    apply();
};

const healthLabel = value => ({ healthy: 'Normal', warning: 'Peringatan', critical: 'Kritis' }[value] || 'Belum diperiksa');

const connectionLabel = value => ({
    HEALTHY: 'Terhubung', UNTESTED: 'Belum dites', STALE: 'Perlu dites ulang',
    DOWN: 'Bermasalah', NOT_CONFIGURED: 'Belum dikonfigurasi',
}[value] || value || 'Belum diketahui');

const settings = useForm({
    enabled: props.autoSync,
    sync_interval_minutes: Number(props.monitorSettings?.sync_interval_minutes || 15),
    low_stock_threshold: Number(props.monitorSettings?.low_stock_threshold || 5),
    price_warning_percent: Number(props.monitorSettings?.price_warning_percent || 3),
});
const saveSettings = () => settings.put('/admin/digiflazz/settings', { preserveScroll: true });

const syncBusy = ref(false);
const syncNow = () => {
    router.post('/admin/digiflazz/sync', {}, {
        preserveScroll: true,
        onStart: () => { syncBusy.value = true; },
        onFinish: () => { syncBusy.value = false; },
    });
};
const reload = () => router.reload({ preserveScroll: true });
</script>

<template>
    <Head title="Digiflazz" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Digiflazz</h1>
                <p>Pantau kesehatan SKU, harga modal, stok, dan hasil sinkronisasi. Harga jual dan margin diatur di menu Produk.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/catalog?import=digiflazz" class="adm-btn">Impor ke produk</Link>
                <button type="button" class="adm-btn" @click="reload">Muat ulang</button>
                <button type="button" class="adm-btn primary" :disabled="syncBusy" @click="syncNow">
                    {{ syncBusy ? 'Menyinkronkan…' : 'Sinkron daftar harga' }}
                </button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Ringkasan Digiflazz">
            <div class="adm-stat">
                <span class="lb">Koneksi</span>
                <strong class="vl" style="font-size:15px">{{ connectionLabel(connection?.status) }}</strong>
                <span class="nt">{{ connection?.message || 'Belum ada keterangan.' }}</span>
            </div>
            <div class="adm-stat">
                <span class="lb">Saldo</span>
                <strong class="vl" style="font-size:16px">{{ canSeeBalance ? rupiah(connection?.balance_idr) : 'Khusus Pemilik' }}</strong>
                <span class="nt">{{ canSeeBalance ? wibDateTime(connection?.balance_checked_at) : 'Saldo tidak ditampilkan untuk Admin.' }}</span>
            </div>
            <div class="adm-stat">
                <span class="lb">SKU dalam daftar</span>
                <strong class="vl">{{ num(summary?.total) }}</strong>
                <span class="nt">{{ mappingCount }} terhubung ke produk</span>
            </div>
            <button type="button" class="adm-stat" style="text-align:left;cursor:pointer" @click="filterBy('healthy')">
                <span class="lb">Normal</span>
                <strong class="vl" style="color:var(--a-ok)">{{ num(summary?.healthy) }}</strong>
                <span class="nt">{{ current.health === 'healthy' ? 'Sedang menyaring' : 'Klik untuk menyaring' }}</span>
            </button>
            <button type="button" class="adm-stat" style="text-align:left;cursor:pointer" @click="filterBy('warning')">
                <span class="lb">Peringatan</span>
                <strong class="vl" style="color:var(--a-warn)">{{ num(summary?.warning) }}</strong>
                <span class="nt">{{ current.health === 'warning' ? 'Sedang menyaring' : 'Klik untuk menyaring' }}</span>
            </button>
            <button type="button" class="adm-stat" style="text-align:left;cursor:pointer" @click="filterBy('critical')">
                <span class="lb">Kritis</span>
                <strong class="vl" style="color:var(--a-danger)">{{ num(summary?.critical) }}</strong>
                <span class="nt">{{ current.health === 'critical' ? 'Sedang menyaring' : 'Klik untuk menyaring' }}</span>
            </button>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Pengaturan pemantauan</h2>
                    <p>
                        Sinkron daftar harga terakhir: {{ wibDateTime(catalogSync?.at || lastSyncedAt) }} ·
                        Sinkron otomatis {{ autoSync ? 'setiap ' + (monitorSettings?.sync_interval_minutes || 15) + ' menit' : 'nonaktif' }} ·
                        Digiflazz {{ providerActive ? 'aktif' : 'nonaktif' }} di menu Provider
                    </p>
                </div>
            </div>
            <form class="adm-card-body" @submit.prevent="saveSettings">
                <div class="adm-grid">
                    <label class="adm-field">
                        <span>Interval sinkron (menit)</span>
                        <input v-model.number="settings.sync_interval_minutes" type="number" min="5" max="1440" step="5" class="adm-input">
                        <span class="hint">Antara 5 sampai 1440 menit.</span>
                    </label>
                    <label class="adm-field">
                        <span>Peringatan stok menipis</span>
                        <input v-model.number="settings.low_stock_threshold" type="number" min="1" max="1000000" class="adm-input">
                        <span class="hint">SKU dengan stok di bawah angka ini ditandai.</span>
                    </label>
                    <label class="adm-field">
                        <span>Peringatan kenaikan harga (%)</span>
                        <input v-model.number="settings.price_warning_percent" type="number" min="0.1" max="100" step="0.1" class="adm-input">
                        <span class="hint">Kenaikan harga modal di atas angka ini ditandai.</span>
                    </label>
                    <label class="adm-switch-row" style="align-self:end">
                        <button type="button" class="adm-switch" role="switch" :aria-checked="settings.enabled" @click="settings.enabled = !settings.enabled" />
                        Sinkron harga otomatis
                    </label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:10px">
                    <button type="submit" class="adm-btn primary" :disabled="settings.processing">Simpan pengaturan</button>
                </div>
            </form>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari SKU</span>
                    <input v-model="current.q" class="adm-input" maxlength="100" placeholder="Nama produk, nominal, kode SKU, penjual, atau brand">
                </label>
                <label class="adm-field narrow">
                    <span>Kategori</span>
                    <select v-model="current.category" class="adm-select">
                        <option value="">Semua</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Produk</span>
                    <select v-model="current.product" class="adm-select">
                        <option value="">Semua</option>
                        <option v-for="product in products" :key="product" :value="product">{{ product }}</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Kondisi SKU</span>
                    <select v-model="current.health" class="adm-select">
                        <option value="">Semua status</option>
                        <option value="healthy">Normal</option>
                        <option value="warning">Peringatan</option>
                        <option value="critical">Kritis</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary">Terapkan</button>
                    <button type="button" class="adm-btn" @click="reset">Hapus filter</button>
                </div>
                <div class="full" style="flex:1 0 100%">
                    <button type="button" class="adm-btn link" @click="advanced = !advanced">Filter lanjutan</button>
                </div>
                <template v-if="advanced">
                    <label class="adm-field narrow">
                        <span>Cakupan daftar</span>
                        <select v-model="current.scope" class="adm-select">
                            <option value="mapped">Hanya SKU terhubung</option>
                            <option value="all">Seluruh daftar harga</option>
                        </select>
                    </label>
                    <label class="adm-field narrow">
                        <span>Brand</span>
                        <select v-model="current.brand" class="adm-select">
                            <option value="">Semua</option>
                            <option v-for="brand in brands" :key="brand" :value="brand">{{ brand }}</option>
                        </select>
                    </label>
                    <label class="adm-field narrow">
                        <span>Baris per halaman</span>
                        <select v-model="current.per_page" class="adm-select">
                            <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} baris</option>
                        </select>
                    </label>
                </template>
            </form>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>Produk &amp; nominal</th>
                            <th>SKU / Penjual</th>
                            <th style="text-align:right">Harga modal</th>
                            <th>Stok</th>
                            <th>Kondisi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in rows" :key="item.id">
                            <td>
                                <strong>{{ item.local_product_name || item.product_name }}</strong>
                                <div class="adm-note">{{ item.local_package_name || item.product_name }}</div>
                                <div class="adm-note">{{ item.mapped ? 'Terhubung ke produk' : 'Belum dipakai di produk' }}</div>
                            </td>
                            <td>
                                <span class="mono">{{ item.buyer_sku_code }}</span>
                                <div class="adm-note">{{ item.seller_name || '—' }}</div>
                            </td>
                            <td style="text-align:right">
                                <strong>{{ rupiah(item.price_idr) }}</strong>
                            </td>
                            <td>{{ item.unlimited_stock ? 'Tak terbatas' : num(item.stock) }}</td>
                            <td>
                                <span class="adm-tag" :class="tone(item.health === 'critical' ? 'FAILED' : item.health === 'warning' ? 'PENDING' : item.health === 'healthy' ? 'HEALTHY' : 'UNKNOWN')">
                                    {{ healthLabel(item.health) }}
                                </span>
                                <div v-if="item.alert_reason" class="adm-note" style="color:var(--a-danger)">{{ item.alert_reason }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.length" class="adm-empty">Tidak ada SKU yang sesuai dengan filter.</p>
            <div class="adm-pager">
                <span>{{ meta.from || 0 }}–{{ meta.to || 0 }} dari {{ num(meta.total) }} SKU · Halaman {{ meta.current_page || 1 }} dari {{ meta.last_page || 1 }}</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!meta.prev_page_url" @click="router.get(meta.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!meta.next_page_url" @click="router.get(meta.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Transaksi Digiflazz terbaru</h2>
                </div>
            </div>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Pesanan</th><th>Produk</th><th>Status</th><th>Waktu</th><th>Catatan</th></tr></thead>
                    <tbody>
                        <tr v-for="row in recentTransactions" :key="row.id">
                            <td><Link :href="'/admin/orders/' + row.order_id" class="adm-btn link">{{ row.order_number }}</Link></td>
                            <td>
                                {{ row.product_name }}
                                <div class="adm-note">{{ row.package_name }}</div>
                            </td>
                            <td>
                                <span class="adm-tag" :class="tone(row.status)">{{ row.status_label }}</span>
                                <div v-if="row.provider_status" class="adm-note">{{ row.provider_status }}</div>
                            </td>
                            <td class="adm-note">{{ wibDateTime(row.created_at) }}</td>
                            <td class="adm-note">{{ row.message || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!recentTransactions.length" class="adm-empty">Belum ada transaksi Digiflazz.</p>
        </section>
    </AdminLayout>
</template>
