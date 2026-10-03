<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    admins: { type: Object, default: () => ({ data: [], links: [] }) },
    permissions: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    recentActivities: { type: Array, default: () => [] },
    currentAdminId: Number,
});

const filters = reactive({
    q: props.filters?.q || '',
    role: props.filters?.role || '',
    status: props.filters?.status || '',
    per_page: Number(props.filters?.per_page || 25),
});

const editorOpen = ref(false);
const editingId = ref(null);
const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'ADMIN',
    permissions: ['dashboard.view'],
    is_active: true,
});

const permissionEntries = computed(() => Object.entries(props.permissions || {}));
const isEditingSelf = computed(() => Number(editingId.value) === Number(props.currentAdminId));

const date = (value) => value ? new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Jakarta',
}).format(new Date(value)) + ' WIB' : 'Belum pernah';

const roleLabel = (role) => role === 'SUPER_ADMIN' ? 'Super Admin' : 'Admin';
const actionLabel = (action) => ({
    'admin.created': 'Menambah akun Admin',
    'admin.updated': 'Mengubah akun / hak akses',
    'admin.deleted': 'Menghapus akun Admin',
}[action] || action);

function submitFilters() {
    router.get('/admin/access', {
        q: filters.q || undefined,
        role: filters.role || undefined,
        status: filters.status || undefined,
        per_page: filters.per_page,
    }, { preserveState: true, preserveScroll: true });
}

function resetFilters() {
    Object.assign(filters, { q: '', role: '', status: '', per_page: 25 });
    submitFilters();
}

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    Object.assign(form, {
        name: '',
        email: '',
        password: '',
        role: 'ADMIN',
        permissions: ['dashboard.view'],
        is_active: true,
    });
    editorOpen.value = true;
}

function openEdit(admin) {
    editingId.value = admin.id;
    form.clearErrors();
    Object.assign(form, {
        name: admin.name,
        email: admin.email,
        password: '',
        role: admin.role,
        permissions: admin.role === 'ADMIN' ? [...(admin.permissions || [])] : [],
        is_active: Boolean(admin.is_active),
    });
    editorOpen.value = true;
}

function closeEditor() {
    editorOpen.value = false;
    editingId.value = null;
    form.clearErrors();
}

function save() {
    if (form.role === 'ADMIN' && !form.permissions.includes('dashboard.view')) {
        form.permissions.push('dashboard.view');
    }
    if (form.role === 'SUPER_ADMIN') {
        form.permissions = [];
    }

    const options = {
        preserveScroll: true,
        onSuccess: closeEditor,
    };

    if (editingId.value) {
        form.put('/admin/access/' + editingId.value, options);
        return;
    }
    form.post('/admin/access', options);
}

function remove(admin) {
    if (Number(admin.id) === Number(props.currentAdminId)) return;
    if (!window.confirm('Hapus akun ' + admin.name + '? Tindakan ini tidak dapat dibatalkan.')) return;
    router.delete('/admin/access/' + admin.id, { preserveScroll: true });
}

function permissionChecked(key) {
    return form.permissions.includes(key);
}

function togglePermission(key, checked) {
    if (key === 'dashboard.view') return;
    const values = new Set(form.permissions);
    if (checked) values.add(key);
    else values.delete(key);
    values.add('dashboard.view');
    form.permissions = [...values];
}
</script>

<template>
<Head title="Admin & Akses" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Admin & Akses</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Kelola akun Super Admin dan Admin, status login, serta izin operasional tiap Admin.
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
                <Button @click="openCreate">Tambah Admin</Button>
            </div>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <Card class="p-4"><p class="text-xs text-muted-foreground">Total akun</p><p class="mt-2 text-2xl font-semibold">{{ summary.total || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Super Admin</p><p class="mt-2 text-2xl font-semibold">{{ summary.super_admins || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Admin</p><p class="mt-2 text-2xl font-semibold">{{ summary.admins || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Akun aktif</p><p class="mt-2 text-2xl font-semibold">{{ summary.active || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Nonaktif</p><p class="mt-2 text-2xl font-semibold">{{ summary.inactive || 0 }}</p></Card>
            <Card class="p-4"><p class="text-xs text-muted-foreground">Pemilik aktif</p><p class="mt-2 text-2xl font-semibold">{{ summary.active_super_admins || 0 }}</p></Card>
        </div>

        <Card class="p-4">
            <form class="grid gap-3 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="submitFilters">
                <label class="space-y-1 md:col-span-2">
                    <span class="text-sm font-medium">Cari akun</span>
                    <Input v-model="filters.q" maxlength="100" placeholder="Nama atau email" />
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Role</span>
                    <select v-model="filters.role" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua role</option>
                        <option value="SUPER_ADMIN">Super Admin</option>
                        <option value="ADMIN">Admin</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Status</span>
                    <select v-model="filters.status" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium">Baris</span>
                    <select v-model.number="filters.per_page" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                    </select>
                </label>
                <div class="flex gap-2 md:col-span-2 xl:col-span-5">
                    <Button size="sm">Terapkan</Button>
                    <Button type="button" size="sm" variant="outline" @click="resetFilters">Reset</Button>
                </div>
            </form>
        </Card>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_360px]">
            <Card class="min-w-0 p-4">
                <div class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Akun</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Hak akses</TableHead>
                                <TableHead>Login terakhir</TableHead>
                                <TableHead>Diperbarui</TableHead>
                                <TableHead class="text-right">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="admin in admins.data" :key="admin.id">
                                <TableCell>
                                    <strong class="block">{{ admin.name }}</strong>
                                    <span class="text-xs text-muted-foreground">{{ admin.email }}</span>
                                    <Badge v-if="Number(admin.id) === Number(currentAdminId)" variant="outline" class="mt-1">Sedang digunakan</Badge>
                                </TableCell>
                                <TableCell><Badge :variant="admin.role === 'SUPER_ADMIN' ? 'secondary' : 'outline'">{{ roleLabel(admin.role) }}</Badge></TableCell>
                                <TableCell><Badge :variant="admin.is_active ? 'secondary' : 'outline'">{{ admin.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></TableCell>
                                <TableCell>
                                    <span v-if="admin.role === 'SUPER_ADMIN'" class="text-sm">Seluruh akses</span>
                                    <span v-else class="text-sm">{{ (admin.permissions || []).length }} izin</span>
                                </TableCell>
                                <TableCell>{{ date(admin.last_login_at) }}</TableCell>
                                <TableCell>{{ date(admin.updated_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <div class="flex justify-end gap-1">
                                        <Button size="sm" variant="outline" @click="openEdit(admin)">Edit</Button>
                                        <Button size="sm" variant="ghost" :disabled="Number(admin.id) === Number(currentAdminId)" @click="remove(admin)">Hapus</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="!admins.data?.length">
                                <TableCell colspan="7" class="py-10 text-center text-muted-foreground">Tidak ada akun sesuai filter.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div v-if="admins.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="link in admins.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        preserve-scroll
                        :class="[
                            'rounded-md border px-3 py-1.5 text-sm',
                            link.active ? 'bg-primary text-primary-foreground' : 'bg-background',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"><span v-html="link.label" /></Link>
                </div>
            </Card>

            <Card class="p-4">
                <h2 class="text-lg font-semibold">Aturan Akses</h2>
                <div class="mt-4 space-y-3">
                    <div class="rounded-md border p-3">
                        <strong class="text-sm">Super Admin</strong>
                        <p class="mt-1 text-xs text-muted-foreground">Akses penuh termasuk Integrasi, Admin & Akses, System Health, Audit Log, routing pembayaran, serta operasi sensitif lainnya.</p>
                    </div>
                    <div class="rounded-md border p-3">
                        <strong class="text-sm">Admin</strong>
                        <p class="mt-1 text-xs text-muted-foreground">Akses hanya ke menu yang dicentang. Dashboard selalu wajib agar akun memiliki halaman masuk yang aman.</p>
                    </div>
                    <div class="rounded-md border p-3">
                        <strong class="text-sm">Role pelanggan terpisah</strong>
                        <p class="mt-1 text-xs text-muted-foreground">Membership BASIC sampai MAFIA bukan role panel Admin.</p>
                    </div>
                </div>
            </Card>
        </div>

        <Card v-if="editorOpen" class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ editingId ? 'Edit Akun Admin' : 'Tambah Akun Admin' }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ isEditingSelf ? 'Akun yang sedang digunakan tidak dapat dinonaktifkan atau diturunkan.' : 'Perubahan role, status, password, dan permission dicatat ke Audit Log.' }}
                    </p>
                </div>
                <Button type="button" size="sm" variant="ghost" @click="closeEditor">Tutup</Button>
            </div>

            <form class="mt-4 space-y-4" @submit.prevent="save">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Nama</span>
                        <Input v-model="form.name" required minlength="2" maxlength="120" />
                        <span v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Email login</span>
                        <Input v-model="form.email" required type="email" maxlength="255" />
                        <span v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">Role</span>
                        <select v-model="form.role" :disabled="isEditingSelf" class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60">
                            <option value="ADMIN">Admin</option>
                            <option value="SUPER_ADMIN">Super Admin</option>
                        </select>
                        <span v-if="form.errors.role" class="text-xs text-destructive">{{ form.errors.role }}</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ editingId ? 'Password baru' : 'Password sementara' }}</span>
                        <Input v-model="form.password" type="password" :required="!editingId" minlength="12" maxlength="128" :placeholder="editingId ? 'Kosongkan jika tidak diubah' : 'Minimal 12 karakter'" />
                        <span v-if="form.errors.password" class="text-xs text-destructive">{{ form.errors.password }}</span>
                    </label>
                </div>

                <label class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4" :disabled="isEditingSelf">
                    Akun aktif
                </label>

                <div v-if="form.role === 'ADMIN'" class="rounded-md border p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium">Hak Akses Admin</h3>
                            <p class="mt-1 text-xs text-muted-foreground">Centang hanya menu/operasi yang memang diperlukan. Otorisasi tetap diperiksa di backend.</p>
                        </div>
                        <Badge variant="outline">{{ form.permissions.length }} izin dipilih</Badge>
                    </div>
                    <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <label
                            v-for="[key, label] in permissionEntries"
                            :key="key"
                            class="flex items-start gap-2 rounded-md border p-3 text-sm"
                        >
                            <input
                                :checked="permissionChecked(key)"
                                type="checkbox"
                                class="mt-0.5 size-4"
                                :disabled="key === 'dashboard.view'"
                                @change="togglePermission(key, $event.target.checked)"
                            >
                            <span><strong class="block font-medium">{{ label }}</strong><small v-if="key === 'dashboard.view'" class="text-muted-foreground">Wajib</small></span>
                        </label>
                    </div>
                    <p v-if="form.errors.permissions" class="mt-2 text-xs text-destructive">{{ form.errors.permissions }}</p>
                </div>

                <div v-else class="rounded-md border p-4 text-sm text-muted-foreground">
                    Super Admin selalu memiliki seluruh permission. Daftar permission individual tidak digunakan untuk role ini.
                </div>

                <p v-if="form.errors.admin" class="text-sm text-destructive">{{ form.errors.admin }}</p>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="closeEditor">Batal</Button>
                    <Button :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan akun' }}</Button>
                </div>
            </form>
        </Card>

        <Card class="p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Aktivitas Admin Terbaru</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Ringkasan perubahan akun dan hak akses. Detail lengkap tetap tersedia di menu Audit Log.</p>
                </div>
                <Button variant="outline" size="sm" as-child><Link href="/admin/audit">Buka Audit Log</Link></Button>
            </div>
            <div class="mt-4 overflow-x-auto">
                <Table>
                    <TableHeader><TableRow><TableHead>Waktu</TableHead><TableHead>Pelaku</TableHead><TableHead>Role</TableHead><TableHead>Aktivitas</TableHead><TableHead>Target</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="activity in recentActivities" :key="activity.id">
                            <TableCell>{{ date(activity.created_at) }}</TableCell>
                            <TableCell>{{ activity.actor_name }}</TableCell>
                            <TableCell><Badge variant="outline">{{ roleLabel(activity.actor_role) }}</Badge></TableCell>
                            <TableCell>{{ actionLabel(activity.action) }}</TableCell>
                            <TableCell>{{ activity.target_id ? 'Admin #' + activity.target_id : '—' }}</TableCell>
                        </TableRow>
                        <TableRow v-if="!recentActivities.length"><TableCell colspan="5" class="py-10 text-center text-muted-foreground">Belum ada aktivitas pengelolaan Admin.</TableCell></TableRow>
                    </TableBody>
                </Table>
            </div>
        </Card>
    </div>
</AdminShell>
</template>
