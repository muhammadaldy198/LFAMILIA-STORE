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
    callbackUrls: { type: Object, default: () => ({}) },
});

const selectedCode = ref(props.integrations[0]?.code || '');
const copiedCallback = ref('');

const editableProfile = (profile = {}) => {
    const fields = (profile.fields || []).map((field) => ({ ...field }));

    return {
        fields,
        config: Object.fromEntries(fields.map((field) => [
            field.key,
            field.value ?? (field.type === 'boolean' ? false : ''),
        ])),
        clear_secrets: [],
        required_total: profile.required_total ?? 0,
        configured_required: profile.configured_required ?? 0,
        required_complete: Boolean(profile.required_complete),
    };
};

const toItem = (item) => {
    const profileCache = {};
    Object.entries(item.environment_profiles || {}).forEach(([environment, profile]) => {
        profileCache[environment] = editableProfile(profile);
    });

    const selected = item.environment && profileCache[item.environment]
        ? profileCache[item.environment]
        : editableProfile({
            fields: item.fields,
            required_total: item.required_total,
            configured_required: item.configured_required,
            required_complete: item.required_complete,
        });

    return {
        ...item,
        result: null,
        busy: false,
        profile_cache: profileCache,
        fields: selected.fields,
        config: selected.config,
        clear_secrets: selected.clear_secrets,
        required_total: selected.required_total,
        configured_required: selected.configured_required,
        required_complete: selected.required_complete,
    };
};

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

const testedAt = (value) => {
    if (!value) return 'Belum pernah dites';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Waktu tes tidak tersedia';
    return 'Terakhir dites ' + date.toLocaleString('id-ID');
};

const selectedEnvironment = (item) => item.environment_options?.find(
    (option) => option.value === item.environment,
) || null;

function switchEnvironment(item, event) {
    const next = event.target.value;
    if (!next || next === item.environment) return;

    if (item.credential_scope === 'per_environment') {
        item.profile_cache[item.environment] = {
            fields: item.fields,
            config: { ...item.config },
            clear_secrets: [...item.clear_secrets],
            required_total: item.required_total,
            configured_required: item.configured_required,
            required_complete: item.required_complete,
        };

        const target = item.profile_cache[next];
        if (target) {
            item.fields = target.fields.map((field) => ({ ...field }));
            item.config = { ...target.config };
            item.clear_secrets = [...target.clear_secrets];
            item.required_total = target.required_total;
            item.configured_required = target.configured_required;
            item.required_complete = target.required_complete;
        }
    }

    item.environment = next;
    item.result = null;
}

function toggleClearSecret(item, key) {
    const index = item.clear_secrets.indexOf(key);
    if (index >= 0) {
        item.clear_secrets.splice(index, 1);
    } else {
        item.clear_secrets.push(key);
        item.config[key] = '';
    }
}

async function copyCallback(key, value) {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        copiedCallback.value = key;
        window.setTimeout(() => {
            if (copiedCallback.value === key) copiedCallback.value = '';
        }, 1800);
    } catch {
        copiedCallback.value = '';
    }
}

function save(item) {
    router.put('/admin/integrations/' + encodeURIComponent(item.code), {
        is_active: Boolean(item.is_active),
        environment: item.environment,
        config: item.config,
        clear_secrets: item.clear_secrets,
    }, { preserveScroll: true });
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
                        Kelola provider, environment, dan credential dari satu tempat. Secret disimpan terenkripsi dan tidak pernah dikirim kembali ke browser.
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
                    <p class="text-xs text-muted-foreground">Credential wajib lengkap</p>
                    <p class="mt-2 text-2xl font-semibold">{{ completeCount }}/{{ items.length }}</p>
                </Card>
            </div>

            <Card class="p-4">
                <div>
                    <h2 class="text-lg font-semibold">Callback & Redirect</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        URL ini berasal dari alamat aplikasi canonical. Endpoint callback adalah bagian protocol aplikasi dan tidak dapat diedit dari panel.
                    </p>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div
                        v-for="(value, key) in callbackUrls"
                        :key="key"
                        class="rounded-md border p-3"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            {{ key === 'midtrans' ? 'Midtrans Notification'
                                : key === 'doku' ? 'DOKU Notification'
                                : key === 'digiflazz' ? 'Digiflazz Webhook'
                                : 'Google OAuth Callback' }}
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <code class="min-w-0 flex-1 break-all text-xs">{{ value }}</code>
                            <Button type="button" size="sm" variant="outline" @click="copyCallback(key, value)">
                                {{ copiedCallback === key ? 'Tersalin' : 'Salin' }}
                            </Button>
                        </div>
                    </div>
                </div>
            </Card>

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
                            <Badge :variant="item.is_active ? 'secondary' : 'outline'">
                                {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                            </Badge>
                            <Badge
                                v-if="selectedEnvironment(item)"
                                :variant="selectedEnvironment(item)?.live ? 'destructive' : 'outline'"
                            >
                                {{ selectedEnvironment(item)?.label }}
                            </Badge>
                            <Badge variant="outline">{{ item.credential_status }}</Badge>
                            <Badge :variant="item.readiness?.status === 'READY' ? 'secondary' : 'outline'">
                                {{ item.readiness?.label }}
                            </Badge>
                        </div>
                        <p v-if="item.description" class="mt-2 text-sm text-muted-foreground">
                            {{ item.description }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ item.configured_required }}/{{ item.required_total }} credential wajib tersimpan ·
                            {{ testedAt(item.health?.tested_at) }}
                        </p>
                    </div>

                    <label class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium">
                        <input v-model="item.is_active" type="checkbox" class="size-4">
                        Integrasi aktif
                    </label>
                </div>

                <div
                    v-if="item.environment_options?.length"
                    class="mt-5 grid gap-3 rounded-md border p-4 md:grid-cols-[minmax(0,260px)_1fr]"
                >
                    <label class="space-y-1.5">
                        <span class="text-sm font-medium">Environment</span>
                        <select
                            :value="item.environment"
                            class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                            @change="switchEnvironment(item, $event)"
                        >
                            <option
                                v-for="option in item.environment_options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <div class="text-sm text-muted-foreground">
                        <p>{{ selectedEnvironment(item)?.description }}</p>
                        <p v-if="item.credential_scope === 'per_environment'" class="mt-1">
                            Credential environment ini terisolasi. Backend tidak akan memakai credential environment lain sebagai fallback.
                        </p>
                        <p v-else class="mt-1">
                            Credential akun dipakai bersama; mode request mengikuti environment yang dipilih.
                        </p>
                    </div>
                </div>

                <div
                    v-else-if="item.environment_note"
                    class="mt-4 rounded-md border p-3 text-sm text-muted-foreground"
                >
                    {{ item.environment_note }}
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
                                <Badge v-if="field.secret && field.configured" variant="outline">
                                    {{ item.clear_secrets.includes(field.key) ? 'Akan dihapus' : 'Tersimpan' }}
                                </Badge>
                            </span>

                            <div class="flex flex-wrap gap-2">
                                <Input
                                    v-model="item.config[field.key]"
                                    :type="field.secret ? 'password' : 'text'"
                                    :autocomplete="field.secret ? 'new-password' : undefined"
                                    :disabled="field.secret && item.clear_secrets.includes(field.key)"
                                    :placeholder="field.secret && field.configured
                                        ? 'Kosong = pertahankan, isi = ganti'
                                        : ''"
                                    class="min-w-0 flex-1"
                                />
                                <Button
                                    v-if="field.secret && field.configured"
                                    type="button"
                                    variant="outline"
                                    @click="toggleClearSecret(item, field.key)"
                                >
                                    {{ item.clear_secrets.includes(field.key) ? 'Batalkan hapus' : 'Hapus credential' }}
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
