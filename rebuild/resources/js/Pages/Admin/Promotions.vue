<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import AdminMultiSelect from '../../Components/Admin/AdminMultiSelect.vue';
import { label, num, rupiah, tone, wibDateTime } from '../../lib/adminFormat';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    vouchers: { type: Object, default: () => ({ data: [] }) },
    categories: { type: Array, default: () => [] },
    scopeProducts: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const rows = computed(() => (Array.isArray(props.vouchers?.data) ? props.vouchers.data : []));
const meta = computed(() => props.vouchers || {});
const busy = ref(false);

const current = reactive({ q: '', status: '', discount_type: '', per_page: 25, ...(props.filters || {}) });
const apply = () => {
    busy.value = true;
    router.get('/admin/vouchers', { ...current }, { preserveState: true, preserveScroll: true, onFinish: () => { busy.value = false; } });
};
const reset = () => { Object.assign(current, { q: '', status: '', discount_type: '', per_page: 25 }); apply(); };

const categoryOptions = computed(() => props.categories.map(row => ({ value: row.id, label: row.name + (row.is_active ? '' : ' (nonaktif)') })));
const productOptions = computed(() => props.scopeProducts.map(row => ({ value: row.id, label: row.name + (row.is_active ? '' : ' (nonaktif)') })));

const boxes = computed(() => [
    { label: 'Total voucher', value: props.summary?.total_vouchers },
    { label: 'Voucher aktif', value: props.summary?.active_vouchers },
    { label: 'Terpakai', value: props.summary?.used_count },
    { label: 'Sedang dipakai', value: props.summary?.reserved_count },
]);

const open = ref(false);
const editing = ref(null);
const form = useForm({
    code: '', name: '', description: '', discount_type: 'PERCENT', discount_value: '',
    max_discount_idr: '', minimum_total_idr: 0, total_quota: '', per_customer_limit: '',
    starts_at: '', ends_at: '', is_active: true, category_ids: [], product_ids: [],
});

const blank = () => ({
    code: '', name: '', description: '', discount_type: 'PERCENT', discount_value: '',
    max_discount_idr: '', minimum_total_idr: 0, total_quota: '', per_customer_limit: '',
    starts_at: '', ends_at: '', is_active: true, category_ids: [], product_ids: [],
});

const openCreate = () => {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
};

const openEdit = (row) => {
    editing.value = row;
    form.defaults({
        code: row.code,
        name: row.name,
        description: row.description || '',
        discount_type: row.discount_type,
        discount_value: row.discount_value,
        max_discount_idr: row.max_discount_idr ?? '',
        minimum_total_idr: row.minimum_total_idr ?? 0,
        total_quota: row.total_quota ?? '',
        per_customer_limit: row.per_customer_limit ?? '',
        starts_at: row.starts_at || '',
        ends_at: row.ends_at || '',
        is_active: row.is_active,
        category_ids: [...(row.category_ids || [])],
        product_ids: [...(row.product_ids || [])],
    });
    form.reset();
    form.clearErrors();
    open.value = true;
};

const save = () => {
    const payload = {
        ...form.data(),
        max_discount_idr: form.max_discount_idr === '' ? null : Number(form.max_discount_idr),
        total_quota: form.total_quota === '' ? null : Number(form.total_quota),
        per_customer_limit: form.per_customer_limit === '' ? null : Number(form.per_customer_limit),
        minimum_total_idr: Number(form.minimum_total_idr || 0),
        discount_value: Number(form.discount_value || 0),
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
    };
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    form.transform(() => payload);
    if (editing.value) form.put(`/admin/vouchers/${editing.value.id}`, options);
    else form.post('/admin/vouchers', options);
};

const toggle = (row) => {
    router.put(`/admin/vouchers/${row.id}`, {
        code: row.code, name: row.name, description: row.description, discount_type: row.discount_type,
        discount_value: row.discount_value, max_discount_idr: row.max_discount_idr,
        minimum_total_idr: row.minimum_total_idr, total_quota: row.total_quota,
        per_customer_limit: row.per_customer_limit, starts_at: row.starts_at, ends_at: row.ends_at,
        is_active: !row.is_active, category_ids: row.category_ids || [], product_ids: row.product_ids || [],
    }, { preserveScroll: true });
};

const removeTarget = ref(null);
const remove = () => {
    if (!removeTarget.value) return;
    router.delete(`/admin/vouchers/${removeTarget.value.id}`, { preserveScroll: true, onFinish: () => { removeTarget.value = null; } });
};

const discountText = row => row.discount_type === 'PERCENT'
    ? `${row.discount_value}%` + (row.max_discount_idr ? ` · Maks. ${rupiah(row.max_discount_idr)}` : '')
    : rupiah(row.discount_value);

const usageText = row => {
    const used = `${num(row.used_count)} terpakai · ${num(row.reserved_count)} dipesan`;
    if (row.total_quota == null) return used;
    return `${used} · ${num(row.remaining_quota)} dari ${num(row.total_quota)} tersisa`;
};

const limitText = row => row.per_customer_limit == null ? 'Tanpa batas per pelanggan' : `maks. ${row.per_customer_limit} per pelanggan`;
</script>

<template>
    <Head title="Voucher & Promo" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Voucher &amp; Promo</h1>
                <p>Voucher berlaku sesuai cakupan kategori dan produk. Jika keduanya kosong, voucher berlaku global.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" :disabled="busy" @click="router.reload({ only: ['vouchers', 'summary'] })">Muat ulang</button>
                <button type="button" class="adm-btn primary" @click="openCreate">Tambah voucher</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Angka voucher">
            <div v-for="box in boxes" :key="box.label" class="adm-stat">
                <span class="lb">{{ box.label }}</span>
                <strong class="vl">{{ box.value == null ? '—' : num(box.value) }}</strong>
            </div>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari</span>
                    <input v-model="current.q" class="adm-input" placeholder="Kode voucher atau nama promo" maxlength="100">
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="current.status" class="adm-select">
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="scheduled">Terjadwal</option>
                        <option value="expired">Berakhir</option>
                        <option value="inactive">Nonaktif</option>
                        <option value="exhausted">Kuota habis</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Jenis diskon</span>
                    <select v-model="current.discount_type" class="adm-select">
                        <option value="">Semua jenis</option>
                        <option value="PERCENT">Persentase</option>
                        <option value="FIXED">Nominal tetap</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Per halaman</span>
                    <select v-model="current.per_page" class="adm-select">
                        <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} voucher</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary" :disabled="busy">Terapkan</button> <button type="button" class="adm-btn" :disabled="busy" @click="reset">Hapus filter</button>
                </div>

            </form>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr><th>Voucher</th><th>Diskon</th><th>Minimum</th><th>Pemakaian</th><th>Periode</th><th>Cakupan</th><th>Status</th><th>Tindakan</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td><span class="mono">{{ row.code }}</span><p class="dim">{{ row.name }}</p></td>
                            <td>{{ discountText(row) }}</td>
                            <td>{{ row.minimum_total_idr ? rupiah(row.minimum_total_idr) : 'Tanpa minimum' }}</td>
                            <td>{{ usageText(row) }}<p class="dim">{{ limitText(row) }}</p></td>
                            <td>
                                {{ row.starts_at ? wibDateTime(row.starts_at) : 'Langsung' }}
                                <p class="dim">{{ row.ends_at ? 'sampai ' + wibDateTime(row.ends_at) : 'tanpa batas akhir' }}</p>
                            </td>
                            <td>{{ row.scope_label }}</td>
                            <td><span class="adm-tag" :class="tone(row.status)">{{ label(row.status) }}</span></td>
                            <td>
                                <div style="display:flex;flex-wrap:wrap;gap:4px">
                                    <button type="button" class="adm-btn sm" @click="openEdit(row)">Ubah</button>
                                    <button type="button" class="adm-btn sm" @click="toggle(row)">{{ row.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                    <button type="button" class="adm-btn sm danger" @click="removeTarget = row">Hapus</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="adm-empty">Belum ada voucher yang cocok dengan filter.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="adm-pager">
                <span>Menampilkan {{ rows.length }} dari {{ num(meta.total ?? 0) }} voucher · Halaman {{ meta.current_page ?? 1 }} dari {{ meta.last_page ?? 1 }}</span>
                <span class="sp" />
                <Link v-if="meta.prev_page_url" :href="meta.prev_page_url" class="adm-btn sm" preserve-scroll>Sebelumnya</Link>
                <button v-else type="button" class="adm-btn sm" disabled>Sebelumnya</button>
                <Link v-if="meta.next_page_url" :href="meta.next_page_url" class="adm-btn sm" preserve-scroll>Berikutnya</Link>
                <button v-else type="button" class="adm-btn sm" disabled>Berikutnya</button>
            </div>
        </section>

        <AdminModal v-model:open="open" :title="editing ? 'Ubah voucher' : 'Tambah voucher'" :description="editing ? editing.code : 'Voucher baru langsung tampil setelah disimpan.'">
            <form id="voucher-form" class="adm-grid two" @submit.prevent="save">
                <label class="adm-field"><span>Kode voucher <b class="req">*</b></span><input v-model="form.code" class="adm-input" maxlength="40" required><span v-if="form.errors.code" class="err">{{ form.errors.code }}</span></label>
                <label class="adm-field"><span>Nama promo <b class="req">*</b></span><input v-model="form.name" class="adm-input" maxlength="100" required><span v-if="form.errors.name" class="err">{{ form.errors.name }}</span></label>

                <label class="adm-field">
                    <span>Jenis diskon <b class="req">*</b></span>
                    <select v-model="form.discount_type" class="adm-select">
                        <option value="PERCENT">Persentase</option>
                        <option value="FIXED">Nominal tetap</option>
                    </select>
                    <span v-if="form.errors.discount_type" class="err">{{ form.errors.discount_type }}</span>
                </label>
                <label class="adm-field">
                    <span>Nilai diskon <b class="req">*</b></span>
                    <input v-model="form.discount_value" type="number" min="1" class="adm-input" required>
                    <span class="hint">{{ form.discount_type === 'PERCENT' ? 'Persen, maksimal 100.' : 'Nominal rupiah.' }}</span>
                    <span v-if="form.errors.discount_value" class="err">{{ form.errors.discount_value }}</span>
                </label>

                <label class="adm-field">
                    <span>Maksimum diskon</span>
                    <input v-model="form.max_discount_idr" type="number" min="1" class="adm-input">
                    <span class="hint">Opsional; berguna terutama untuk diskon persen.</span>
                    <span v-if="form.errors.max_discount_idr" class="err">{{ form.errors.max_discount_idr }}</span>
                </label>
                <label class="adm-field">
                    <span>Minimum transaksi</span>
                    <input v-model="form.minimum_total_idr" type="number" min="0" class="adm-input">
                    <span class="hint">Isi 0 bila tidak ada minimum.</span>
                    <span v-if="form.errors.minimum_total_idr" class="err">{{ form.errors.minimum_total_idr }}</span>
                </label>

                <label class="adm-field">
                    <span>Total kuota</span>
                    <input v-model="form.total_quota" type="number" min="1" class="adm-input">
                    <span class="hint">Kosong = tanpa batas kuota.</span>
                    <span v-if="form.errors.total_quota" class="err">{{ form.errors.total_quota }}</span>
                </label>
                <label class="adm-field">
                    <span>Limit per pelanggan</span>
                    <input v-model="form.per_customer_limit" type="number" min="1" class="adm-input">
                    <span class="hint">Kosong = tanpa batas per pelanggan.</span>
                    <span v-if="form.errors.per_customer_limit" class="err">{{ form.errors.per_customer_limit }}</span>
                </label>

                <label class="adm-field"><span>Mulai</span><input v-model="form.starts_at" type="datetime-local" class="adm-input"><span class="hint">Kosong = langsung.</span><span v-if="form.errors.starts_at" class="err">{{ form.errors.starts_at }}</span></label>
                <label class="adm-field"><span>Berakhir</span><input v-model="form.ends_at" type="datetime-local" class="adm-input"><span v-if="form.errors.ends_at" class="err">{{ form.errors.ends_at }}</span></label>

                <label class="adm-switch-row full">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" />
                    Voucher aktif
                </label>

                <label class="adm-field full"><span>Deskripsi internal</span><textarea v-model="form.description" class="adm-textarea" maxlength="300" /><span v-if="form.errors.description" class="err">{{ form.errors.description }}</span></label>

                <div class="adm-field">
                    <span>Cakupan kategori</span>
                    <AdminMultiSelect v-model="form.category_ids" :options="categoryOptions" placeholder="Semua kategori" search-placeholder="Cari kategori…" />
                </div>
                <div class="adm-field">
                    <span>Cakupan produk</span>
                    <AdminMultiSelect v-model="form.product_ids" :options="productOptions" placeholder="Semua produk" search-placeholder="Cari produk…" />
                </div>
                <p class="adm-note full">Kategori dan produk memakai logika OR. Jika keduanya kosong, voucher berlaku global.</p>
            </form>
            <template #footer>
                <button type="button" class="adm-btn" @click="open = false">Batal</button>
                <button type="submit" form="voucher-form" class="adm-btn primary" :disabled="form.processing">Simpan voucher</button>
            </template>
        </AdminModal>

        <AdminModal :open="!!removeTarget" title="Hapus voucher" :description="removeTarget?.code || ''" @update:open="value => { if (!value) removeTarget = null; }">
            <p class="adm-note">Voucher yang sudah dipakai pesanan tidak dapat dihapus. Nonaktifkan bila hanya ingin menghentikan pemakaian.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="removeTarget = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="remove">Hapus voucher</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
