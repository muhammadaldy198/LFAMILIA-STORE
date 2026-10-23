<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { num, wibDateTime } from '../../lib/adminFormat';
import { withSensitive } from '../../lib/sensitivePrompt';

const props = defineProps({
    admins: { type: Object, default: () => ({ data: [], links: [] }) },
    permissions: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    recentActivities: { type: Array, default: () => [] },
    currentAdminId: { type: Number, default: 0 },
});

const pendingDelete = ref(null);
const deleteBusy = ref(false);
// Server menolak penghapusan diri sendiri dan Super Admin terakhir.
const deleteAdmin = () => {
    const target = pendingDelete.value;
    if (!target) return;
    // Menghapus akun lebih berat daripada menonaktifkan, jadi ikut minta kata sandi.
    void withSensitive('Menghapus akun admin memerlukan konfirmasi kata sandi.', () => {
        deleteBusy.value = true;
        router.delete(`/admin/access/${target.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteBusy.value = false; pendingDelete.value = null; },
        });
    });
};

const filter = reactive({
    q: props.filters?.q || '',
    role: props.filters?.role || '',
    status: props.filters?.status || '',
    per_page: Number(props.filters?.per_page || 25),
});
const apply = () => router.get('/admin/access', Object.fromEntries(
    Object.entries(filter).filter(([, value]) => value !== '' && value !== null)
), { preserveState: true, preserveScroll: true });
const reset = () => {
    Object.assign(filter, { q: '', role: '', status: '', per_page: 25 });
    apply();
};

const rows = computed(() => (Array.isArray(props.admins?.data) ? props.admins.data : []));
const permissionList = computed(() => Object.entries(props.permissions || {}).map(([key, label]) => ({ key, label })));

const roleLabel = role => ({ SUPER_ADMIN: 'Super Admin', ADMIN: 'Admin' }[role] || role);
const roleTone = role => role === 'SUPER_ADMIN' ? 'info' : 'plain';

// ---------- pop-up akun ----------
const editor = ref(null);
const busy = ref(false);
const errors = ref({});
const openCreate = () => {
    editor.value = {
        id: null, name: '', email: '', role: 'ADMIN', is_active: true, password: '',
        permissions: ['dashboard.view'],
    };
    errors.value = {};
};
const openEdit = row => {
    const permissions = row.role === 'ADMIN' ? [...(row.permissions || [])] : [];
    editor.value = {
        id: row.id, name: row.name, email: row.email, role: row.role,
        is_active: row.is_active, password: '', permissions,
        original: { role: row.role, is_active: row.is_active, permissions: [...permissions] },
    };
    errors.value = {};
};
const togglePermission = key => {
    if (!editor.value || editor.value.role !== 'ADMIN' || key === 'dashboard.view') return;
    const list = editor.value.permissions;
    const index = list.indexOf(key);
    if (index >= 0) list.splice(index, 1);
    else list.push(key);
};
const sensitiveReason = () => {
    const target = editor.value;
    if (!target.id) return 'Membuat akun admin baru memerlukan konfirmasi kata sandi.';
    if (target.role !== 'SUPER_ADMIN' && !target.is_active) return 'Menonaktifkan akun admin memerlukan konfirmasi kata sandi.';
    return 'Perubahan peran atau hak akses memerlukan konfirmasi kata sandi.';
};
const submit = () => {
    const target = editor.value;
    if (!target) return;
    const payload = {
        name: target.name, email: target.email, role: target.role,
        is_active: Boolean(target.is_active),
        permissions: target.role === 'ADMIN' ? target.permissions : [],
        password: target.password || null,
    };
    const run = () => {
        busy.value = true;
        const options = {
            preserveScroll: true,
            onError: bag => { errors.value = bag; },
            onSuccess: () => { editor.value = null; },
            onFinish: () => { busy.value = false; },
        };
        if (target.id) router.put('/admin/access/' + target.id, payload, options);
        else router.post('/admin/access', payload, options);
    };
    // Kata sandi ulang untuk pembuatan akun baru (termasuk Super Admin), serta
    // saat peran, status aktif, atau izin berubah.
    const before = target.original;
    const needsPassword = !target.id || (before && (
        target.role !== before.role
        || Boolean(target.is_active) !== before.is_active
        || JSON.stringify([...target.permissions].sort()) !== JSON.stringify([...before.permissions].sort())
    ));
    if (needsPassword) void withSensitive(sensitiveReason(), run);
    else run();
};

// ---------- nonaktifkan ----------
const pendingOff = ref(null);
const deactivate = () => {
    const row = pendingOff.value;
    void withSensitive(`Menonaktifkan akun ${row.name} memerlukan konfirmasi kata sandi.`, () => {
        router.put('/admin/access/' + row.id, {
            name: row.name, email: row.email, role: row.role, is_active: false,
            permissions: row.role === 'ADMIN' ? row.permissions : [], password: null,
        }, {
            preserveScroll: true,
            onSuccess: () => { pendingOff.value = null; },
        });
    });
};
</script>

<template>
    <Head title="Admin & Akses" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Admin &amp; Akses</h1>
                <p>Akun yang boleh masuk panel dan izin yang dimiliki. Hanya Super Admin yang bisa mengubah bagian ini.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
                <button type="button" class="adm-btn primary" @click="openCreate">Tambah admin</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Ringkasan akses">
            <div class="adm-stat"><span class="lb">Total akun</span><strong class="vl">{{ num(summary.total) }}</strong></div>
            <div class="adm-stat"><span class="lb">Super Admin</span><strong class="vl">{{ num(summary.super_admins) }}</strong></div>
            <div class="adm-stat"><span class="lb">Super Admin aktif</span><strong class="vl">{{ num(summary.active_super_admins) }}</strong></div>
            <div class="adm-stat"><span class="lb">Admin biasa</span><strong class="vl">{{ num(summary.admins) }}</strong></div>
            <div class="adm-stat"><span class="lb">Aktif</span><strong class="vl">{{ num(summary.active) }}</strong></div>
            <div class="adm-stat"><span class="lb">Nonaktif</span><strong class="vl" :style="{ color: Number(summary.inactive) ? 'var(--a-danger)' : 'inherit' }">{{ num(summary.inactive) }}</strong></div>
        </section>

        <section class="adm-card">
            <form class="adm-filters" @submit.prevent="apply">
                <label class="adm-field search">
                    <span>Cari admin</span>
                    <input v-model="filter.q" class="adm-input" maxlength="100" placeholder="Nama atau email">
                </label>
                <label class="adm-field narrow">
                    <span>Peran</span>
                    <select v-model="filter.role" class="adm-select">
                        <option value="">Semua peran</option>
                        <option value="SUPER_ADMIN">Super Admin</option>
                        <option value="ADMIN">Admin</option>
                    </select>
                </label>
                <label class="adm-field narrow">
                    <span>Status</span>
                    <select v-model="filter.status" class="adm-select">
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </label>
                <div class="adm-filter-actions">
                    <button type="submit" class="adm-btn primary">Terapkan</button>
                    <button type="button" class="adm-btn" @click="reset">Hapus filter</button>
                </div>
                <label class="adm-field narrow">
                    <span>Akun per halaman</span>
                    <select v-model.number="filter.per_page" class="adm-select">
                        <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} akun</option>
                    </select>
                </label>
            </form>

            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr><th>Admin</th><th>Peran</th><th>Izin</th><th>Status</th><th>Login terakhir</th><th>Tindakan</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>
                                <strong>{{ row.name }}</strong>
                                <span v-if="row.id === currentAdminId" class="adm-tag plain" style="margin-left:4px">Akun ini</span>
                                <div class="adm-note">{{ row.email }}</div>
                            </td>
                            <td><span class="adm-tag" :class="roleTone(row.role)">{{ roleLabel(row.role) }}</span></td>
                            <td class="adm-note">
                                <template v-if="row.role === 'SUPER_ADMIN'">Semua izin</template>
                                <template v-else>{{ row.permissions.length }} dari {{ permissionList.length }} izin</template>
                            </td>
                            <td><span class="adm-tag" :class="row.is_active ? 'ok' : 'bad'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="adm-note">{{ wibDateTime(row.last_login_at) || 'Belum pernah' }}</td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap">
                                    <button type="button" class="adm-btn sm" @click="openEdit(row)">Ubah</button>
                                    <button v-if="row.is_active && row.id !== currentAdminId" type="button" class="adm-btn sm" @click="pendingOff = row">Nonaktifkan</button>
                                    <button v-if="row.id !== currentAdminId" type="button" class="adm-btn sm danger" @click="pendingDelete = row">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!rows.length" class="adm-empty">Tidak ada akun yang sesuai filter.</p>
            <div class="adm-pager">
                <span>{{ admins.from || 0 }}–{{ admins.to || 0 }} dari {{ num(admins.total) }} akun</span>
                <span class="sp" />
                <button type="button" class="adm-btn sm" :disabled="!admins.prev_page_url" @click="router.get(admins.prev_page_url, {}, { preserveState: true, preserveScroll: true })">Sebelumnya</button>
                <button type="button" class="adm-btn sm" :disabled="!admins.next_page_url" @click="router.get(admins.next_page_url, {}, { preserveState: true, preserveScroll: true })">Berikutnya</button>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div><h2>Aktivitas admin terbaru</h2></div>
                <Link href="/admin/audit" class="adm-btn sm">Buka Catatan Audit</Link>
            </div>
            <div class="adm-card-body" style="display:grid;gap:0">
                <div v-for="activity in recentActivities" :key="activity.id" class="adm-list-row">
                    <div>
                        <strong>{{ activity.action_label || activity.action }}</strong>
                        <div class="adm-note">{{ activity.actor_name }} · {{ roleLabel(activity.actor_role) }}</div>
                    </div>
                    <span class="adm-note">{{ wibDateTime(activity.created_at) }}</span>
                </div>
                <p v-if="!recentActivities.length" class="adm-note">Belum ada aktivitas admin.</p>
            </div>
        </section>

        <AdminModal :open="!!editor" :title="editor?.id ? 'Ubah akun admin' : 'Tambah admin'"
            description="Peran Super Admin memakai semua izin. Admin biasa hanya memakai izin yang dicentang."
            @update:open="value => { if (!value) editor = null; }">
            <div v-if="editor" class="adm-grid">
                <label class="adm-field">
                    <span>Nama</span>
                    <input v-model="editor.name" class="adm-input" maxlength="120" required>
                    <span v-if="errors.name" class="err">{{ errors.name }}</span>
                </label>
                <label class="adm-field">
                    <span>Email</span>
                    <input v-model="editor.email" type="email" class="adm-input" maxlength="255" required>
                    <span v-if="errors.email" class="err">{{ errors.email }}</span>
                </label>
                <label class="adm-field">
                    <span>Peran</span>
                    <select v-model="editor.role" class="adm-select">
                        <option value="ADMIN">Admin</option>
                        <option value="SUPER_ADMIN">Super Admin</option>
                    </select>
                    <span v-if="errors.role" class="err">{{ errors.role }}</span>
                </label>
                <label class="adm-field">
                    <span>{{ editor.id ? 'Kata sandi baru' : 'Kata sandi' }}</span>
                    <input v-model="editor.password" type="password" class="adm-input" autocomplete="new-password"
                        :placeholder="editor.id ? 'Kosongkan bila tidak diubah' : 'Minimal 12 karakter'">
                    <span class="hint">Minimal 12 karakter. {{ editor.id ? 'Isi hanya bila ingin mengganti.' : 'Wajib diisi untuk akun baru.' }}</span>
                    <span v-if="errors.password" class="err">{{ errors.password }}</span>
                </label>
                <label class="adm-switch-row full">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="editor.is_active"
                        @click="editor.is_active = !editor.is_active" />
                    Akun bisa masuk panel
                </label>

                <div v-if="editor.role === 'ADMIN'" class="adm-block full">
                    <strong>Izin yang diberikan</strong>
                    <p class="adm-note">Dashboard selalu aktif dan tidak bisa dilepas.</p>
                    <div class="adm-grid" style="margin-top:6px">
                        <label v-for="permission in permissionList" :key="permission.key" class="adm-switch-row">
                            <button type="button" class="adm-switch" role="switch"
                                :aria-checked="editor.permissions.includes(permission.key)"
                                :disabled="permission.key === 'dashboard.view'"
                                @click="togglePermission(permission.key)" />
                            {{ permission.label }}
                        </label>
                    </div>
                    <span v-if="errors.permissions" class="err">{{ errors.permissions }}</span>
                </div>
                <p v-else class="adm-note full">Super Admin memakai semua izin, termasuk Integrasi, Kesehatan Sistem, Catatan Audit, dan Admin &amp; Akses.</p>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="editor = null">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="busy" @click="submit">Simpan akun</button>
            </template>
        </AdminModal>

        <AdminModal :open="!!pendingOff" title="Nonaktifkan akun admin"
            :description="pendingOff ? 'Akun ' + pendingOff.name + ' tidak akan bisa masuk panel.' : ''"
            @update:open="value => { if (!value) pendingOff = null; }">
            <p class="adm-note">Akun tidak dihapus, hanya dinonaktifkan. Riwayat dan catatan audit tetap tersimpan.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="pendingOff = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="deactivate">Nonaktifkan</button>
            </template>
        </AdminModal>
        <AdminModal :open="!!pendingDelete" title="Hapus akun admin"
            :description="pendingDelete ? 'Akun ' + pendingDelete.name + ' dihapus permanen.' : ''"
            @update:open="value => { if (!value) pendingDelete = null; }">
            <p class="adm-note">Riwayat audit akun ini tetap tersimpan. Akun yang sedang dipakai atau Super Admin terakhir akan ditolak server.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="pendingDelete = null">Batal</button>
                <button type="button" class="adm-btn danger" :disabled="deleteBusy" @click="deleteAdmin">{{ deleteBusy ? 'Menghapus…' : 'Hapus akun' }}</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
