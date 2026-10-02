<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const selectedCode = ref(props.integrations[0]?.code || '');

const toItem = (item) => ({
    ...item,
    result: null,
    busy: false,
    reveal_password: '',
    config: Object.fromEntries(
        item.fields.map((field) => [
            field.key,
            field.value ?? (field.type === 'boolean' ? false : ''),
        ]),
    ),
});

const items = reactive(props.integrations.map(toItem));

watch(() => props.integrations, (value) => {
    items.splice(0, items.length, ...(value || []).map(toItem));
    if (!items.some((item) => item.code === selectedCode.value)) {
        selectedCode.value = items[0]?.code || '';
    }
});

const activeCount = computed(() => items.filter((item) => item.is_active).length);
const healthyCount = computed(() => items.filter((item) => item.is_active && item.health?.status === 'HEALTHY').length);
const attentionCount = computed(() => items.filter((item) => item.is_active && item.health?.status !== 'HEALTHY').length);
const completeCount = computed(() => items.filter((item) => item.required_complete).length);

const csrf = () => decodeURIComponent(
    document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.slice(11) || '',
);

const statusLabel = (status) => ({
    HEALTHY: 'Terverifikasi',
    DEGRADED: 'Perlu diperiksa',
    DOWN: 'Bermasalah',
    NOT_CONFIGURED: 'Belum dikonfigurasi',
    UNTESTED: 'Belum dites',
    OK: 'Berhasil',
}[status] || 'Belum diketahui');

const statusVariant = (status) => status === 'HEALTHY' ? 'secondary' : 'outline';

const testedAt = (value) => {
    if (!value) return 'Belum pernah dites';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Waktu tes tidak tersedia';
    return 'Terakhir dites ' + date.toLocaleString('id-ID');
};

function save(item) {
    router.put('/admin/integrations/' + encodeURIComponent(item.code), {
        is_active: Boolean(item.is_active),
        config: item.config,
    }, { preserveScroll: true });
}

async function reveal(item, field) {
    if (item.busy) return;
    item.busy = true;

    try {
        const response = await fetch(
            '/admin/integrations/' + encodeURIComponent(item.code) + '/reveal/' + encodeURIComponent(field.key),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ password: item.reveal_password }),
            },
        );
        const data = await response.json().catch(() => ({}));

        if (response.ok) {
            item.config[field.key] = data.value || '';
            field.revealed = true;
            item.reveal_password = '';
            item.result = {
                status: 'OK',
                message: 'Kredensial ditampilkan setelah verifikasi password.',
            };
        } else {
            item.result = {
                status: 'DOWN',
                message: data.errors?.password?.[0] || data.message || 'Kredensial tidak dapat ditampilkan.',
            };
        }
    } catch {
        item.result = { status: 'DOWN', message: 'Koneksi terputus. Coba lagi.' };
    } finally {
        item.busy = false;
    }
}

async function testConnection(item) {
    if (item.busy) return;
    item.busy = true;

    try {
        const response = await fetch('/admin/integrations/' + encodeURIComponent(item.code) + '/test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
            },
        });
        const result = await response.json().catch(() => ({
            status: 'DOWN',
            message: 'Tes koneksi gagal.',
        }));

        item.result = response.ok
            ? result
            : { status: 'DOWN', message: result.message || 'Tes koneksi ditolak.' };

        if (response.ok) {
            item.health = result;
        }
    } catch {
        item.result = { status: 'DOWN', message: 'Koneksi terputus. Coba lagi.' };
    } finally {
        item.busy = false;
    }
}
</script>

<template>
    <Head title="Integrasi" />
    <AdminShell>
        <div class="space-y-5">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold">Integrasi</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Kelola koneksi layanan eksternal dari satu tempat. Kredensial rahasia disimpan terenkripsi dan tidak ditanam di repo.
                    </p>
                </div>
                <Button variant="outline" as-child>
                    <Link href="/admin/nickname-tools">Buka Validasi Akun</Link>
                </Button>
            </header>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Total integrasi</p>
                    <p class="mt-2 text-2xl font-semibold">{{ summary.total ?? items.length }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Aktif</p>
                    <p class="mt-2 text-2xl font-semibold">{{ activeCount }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Terverifikasi</p>
                    <p class="mt-2 text-2xl font-semibold">{{ healthyCount }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">Perlu diperiksa</p>
                    <p class="mt-2 text-2xl font-semibold">{{ attentionCount }}</p>
                </Card>
                <Card class="col-span-2 p-4 md:col-span-1">
                    <p class="text-xs text-muted-foreground">Field wajib lengkap</p>
                    <p class="mt-2 text-2xl font-semibold">{{ completeCount }}/{{ items.length }}</p>
                </Card>
            </div>

            <nav class="flex max-w-full gap-1 overflow-x-auto rounded-lg border p-1" aria-label="Daftar integrasi">
                <Button
                    v-for="item in items"
                    :key="item.code"
                    type="button"
                    :variant="selectedCode === item.code ? 'secondary' : 'ghost'"
                    class="shrink-0"
                    @click="selectedCode = item.code"
                >
                    {{ item.name }}
                </Button>
            </nav>

            <Card
                v-for="item in items"
                v-show="selectedCode === item.code"
                :key="item.code"
                class="p-4 md:p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-3xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-semibold">{{ item.name }}</h2>
                            <Badge variant="outline">{{ item.group }}</Badge>
                            <Badge :variant="statusVariant(item.health?.status)">
                                {{ statusLabel(item.health?.status) }}
                            </Badge>
                        </div>
                        <p v-if="item.description" class="mt-2 text-sm text-muted-foreground">
                            {{ item.description }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ item.configured_required }}/{{ item.required_total }} field wajib tersimpan ·
                            {{ testedAt(item.health?.tested_at) }}
                        </p>
                    </div>

                    <label class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium">
                        <input v-model="item.is_active" type="checkbox" class="size-4">
                        Integrasi aktif
                    </label>
                </div>

                <div
                    v-if="item.note"
                    class="mt-4 rounded-md border p-3 text-sm text-muted-foreground"
                >
                    {{ item.note }}
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <template v-for="field in item.fields" :key="field.key">
                        <label v-if="field.type !== 'boolean'" class="space-y-1.5">
                            <span class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {{ field.label }}
                                <span v-if="field.required" class="text-xs text-destructive">Wajib</span>
                                <Badge v-if="field.secret && field.configured" variant="outline">Tersimpan</Badge>
                            </span>

                            <div class="flex gap-2">
                                <Input
                                    v-model="item.config[field.key]"
                                    :type="field.secret && !field.revealed ? 'password' : 'text'"
                                    :autocomplete="field.secret ? 'off' : undefined"
                                    :placeholder="field.secret && field.configured
                                        ? 'Kosongkan jika tidak ingin mengubah'
                                        : ''"
                                    class="min-w-0 flex-1"
                                />
                                <Button
                                    v-if="field.secret && field.configured"
                                    type="button"
                                    variant="outline"
                                    :disabled="item.busy || !item.reveal_password"
                                    @click="reveal(item, field)"
                                >
                                    Tampilkan
                                </Button>
                            </div>

                            <span v-if="field.help" class="block text-xs text-muted-foreground">
                                {{ field.help }}
                            </span>
                        </label>

                        <label
                            v-else
                            class="flex items-start justify-between gap-4 rounded-md border p-3"
                        >
                            <span>
                                <span class="block text-sm font-medium">{{ field.label }}</span>
                                <span v-if="field.help" class="mt-1 block text-xs text-muted-foreground">
                                    {{ field.help }}
                                </span>
                            </span>
                            <input v-model="item.config[field.key]" type="checkbox" class="mt-1 size-4">
                        </label>
                    </template>
                </div>

                <div
                    v-if="item.fields.some((field) => field.secret && field.configured)"
                    class="mt-5 max-w-lg rounded-md border p-3"
                >
                    <label class="space-y-1.5">
                        <span class="text-sm font-medium">Password Super Admin</span>
                        <Input
                            v-model="item.reveal_password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Diperlukan hanya untuk melihat kredensial tersimpan"
                        />
                        <span class="block text-xs text-muted-foreground">
                            Menampilkan kredensial dicatat ke Audit Log. Password tidak disimpan bersama konfigurasi.
                        </span>
                    </label>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <Button :disabled="item.busy" @click="save(item)">
                        Simpan Integrasi
                    </Button>
                    <Button variant="outline" :disabled="item.busy" @click="testConnection(item)">
                        {{ item.busy ? 'Memeriksa…' : 'Tes Koneksi' }}
                    </Button>
                </div>

                <div
                    v-if="item.result"
                    class="mt-4 rounded-md border p-3 text-sm"
                    :class="item.result.status === 'HEALTHY' || item.result.status === 'OK'
                        ? 'text-foreground'
                        : 'text-muted-foreground'"
                >
                    <strong>{{ statusLabel(item.result.status) }}</strong>
                    <span> · {{ item.result.message }}</span>
                </div>

                <div v-else-if="item.health?.message" class="mt-4 rounded-md border p-3 text-sm text-muted-foreground">
                    <strong>{{ statusLabel(item.health.status) }}</strong>
                    <span> · {{ item.health.message }}</span>
                </div>
            </Card>
        </div>
    </AdminShell>
</template>
