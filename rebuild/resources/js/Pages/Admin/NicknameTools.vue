<script setup>
import AdminConfirmDialog from '../../Components/AdminConfirmDialog.vue';
import AdminSwitch from '../../Components/AdminSwitch.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '../../Components/ui/sheet';
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

const props = defineProps({
    gameCodes: { type: Array, default: () => [] },
    validationConfig: { type: Object, default: () => ({}) },
});

const codes = ref(props.gameCodes.map((item) => ({ ...item })));
watch(() => props.gameCodes, (value) => {
    codes.value = value.map((item) => ({ ...item }));
}, { deep: true });

const tab = ref('game');
const gameCode = ref('');
const userId = ref('');
const server = ref('');
const customerNumber = ref('');
const query = ref('');
const result = ref(null);
const error = ref('');
const busy = ref(false);
const copied = ref('');

const editorOpen = ref(false);
const editingId = ref(null);
const editorBusy = ref(false);
const editorError = ref('');
const editorNotice = ref('');
const reorderBusy = ref(false);
const pendingAction = ref(null);
const ask = (title, message, confirmLabel, run, destructive = false) => {
    pendingAction.value = { title, message, confirmLabel, run, destructive };
};
const runPending = () => {
    const action = pendingAction.value;
    pendingAction.value = null;
    action?.run();
};
const form = reactive({
    name: '',
    code: '',
    supports_nickname_check: false,
    requires_server: false,
    requires_region_check: false,
    is_active: true,
    sort_order: null,
});

const activeCodes = computed(() => codes.value.filter((item) => item.is_active));
const nicknameCodes = computed(() => activeCodes.value.filter((item) => item.supports_nickname_check));
const regionCodes = computed(() => nicknameCodes.value.filter((item) => item.requires_region_check));
const selectedGame = computed(() => codes.value.find((item) => item.code === gameCode.value) || null);
const requiresServer = computed(() => Boolean(selectedGame.value?.requires_server));
const filteredCodes = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return codes.value;
    return codes.value.filter((item) =>
        item.name.toLowerCase().includes(needle) || item.code.toLowerCase().includes(needle)
    );
});
const integrationStatus = computed(() => {
    if (!props.validationConfig?.is_active) return { text: 'Nonaktif', className: 'border-border bg-muted text-muted-foreground' };
    if (!props.validationConfig?.is_configured) return { text: 'Belum lengkap', className: 'border-amber-200 bg-amber-50 text-amber-700' };
    return { text: 'Siap digunakan', className: 'border-emerald-200 bg-emerald-50 text-emerald-700' };
});
const endpointText = computed(() => {
    const nickname = props.validationConfig?.nickname_path || 'Path nickname belum diatur';
    const region = props.validationConfig?.region_path || 'Path region belum diatur';
    const pln = props.validationConfig?.pln_path || 'Path PLN belum diatur';

    if (tab.value === 'pln') return pln;
    if (tab.value === 'region' || selectedGame.value?.requires_region_check) return `${nickname} + ${region}`;
    return nickname;
});

function csrf() {
    return decodeURIComponent(document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.slice(11) || '');
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf(),
            ...(options.headers || {}),
        },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validation || payload.message || 'Permintaan tidak berhasil.');
    }
    return payload;
}

function resetResult() {
    result.value = null;
    error.value = '';
}

function selectTab(nextTab) {
    tab.value = nextTab;
    resetResult();

    if (nextTab === 'region' && !regionCodes.value.some((item) => item.code === gameCode.value)) {
        gameCode.value = regionCodes.value[0]?.code || '';
    }
    if (nextTab === 'game' && !nicknameCodes.value.some((item) => item.code === gameCode.value)) {
        gameCode.value = nicknameCodes.value[0]?.code || '';
    }
}

function clearForm() {
    gameCode.value = tab.value === 'region'
        ? (regionCodes.value[0]?.code || '')
        : (nicknameCodes.value[0]?.code || '');
    userId.value = '';
    server.value = '';
    customerNumber.value = '';
    resetResult();
}

async function runCheck() {
    if (busy.value) return;
    resetResult();
    busy.value = true;

    try {
        const body = tab.value === 'game'
            ? { action: 'game', game_code: gameCode.value, user_id: userId.value.trim(), server: server.value.trim() || null }
            : tab.value === 'region'
                ? { action: 'region', game_code: gameCode.value, user_id: userId.value.trim(), server: server.value.trim() }
                : { action: 'pln', customer_number: customerNumber.value.replace(/\D/g, '') };

        result.value = await requestJson('/admin/nickname-tools/check', {
            method: 'POST',
            body: JSON.stringify(body),
        });
    } catch (reason) {
        error.value = reason instanceof Error ? reason.message : 'Pemeriksaan tidak berhasil.';
    } finally {
        busy.value = false;
    }
}

async function copyCode(code) {
    await navigator.clipboard.writeText(code);
    copied.value = code;
    window.setTimeout(() => {
        if (copied.value === code) copied.value = '';
    }, 1200);
}

function resetEditor() {
    editingId.value = null;
    form.name = '';
    form.code = '';
    form.supports_nickname_check = false;
    form.requires_server = false;
    form.requires_region_check = false;
    form.is_active = true;
    form.sort_order = null;
    editorError.value = '';
    editorNotice.value = '';
}

function createCode() {
    resetEditor();
    editorOpen.value = true;
}

function editCode(item) {
    editingId.value = item.id;
    form.name = item.name;
    form.code = item.code;
    form.supports_nickname_check = item.supports_nickname_check;
    form.requires_server = item.requires_server;
    form.requires_region_check = item.requires_region_check;
    form.is_active = item.is_active;
    form.sort_order = item.sort_order;
    editorError.value = '';
    editorNotice.value = item.product_count > 0
        ? `Kode ini dipakai oleh ${item.product_count} produk. Jika kode diubah, produk terkait ikut diperbarui otomatis.`
        : '';
    editorOpen.value = true;
}

function syncNicknameSupportRules() {
    if (!form.supports_nickname_check) {
        form.requires_server = false;
        form.requires_region_check = false;
    }
}

function normalizeCodeInput() {
    form.code = form.code
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

async function saveCode() {
    if (editorBusy.value) return;
    editorBusy.value = true;
    editorError.value = '';

    if (!form.supports_nickname_check) {
        form.requires_server = false;
        form.requires_region_check = false;
    } else if (form.requires_region_check) {
        form.requires_server = true;
    }

    try {
        const payload = await requestJson(
            editingId.value ? `/admin/nickname-tools/game-codes/${editingId.value}` : '/admin/nickname-tools/game-codes',
            {
                method: editingId.value ? 'PUT' : 'POST',
                body: JSON.stringify({
                    name: form.name.trim(),
                    code: form.code.trim(),
                    supports_nickname_check: form.supports_nickname_check,
                    requires_server: form.requires_server,
                    requires_region_check: form.requires_region_check,
                    is_active: form.is_active,
                    sort_order: form.sort_order,
                }),
            },
        );
        codes.value = payload.game_codes || [];
        editorOpen.value = false;
        resetEditor();

        if (!nicknameCodes.value.some((item) => item.code === gameCode.value)) {
            gameCode.value = nicknameCodes.value[0]?.code || '';
        }
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Kode game tidak berhasil disimpan.';
    } finally {
        editorBusy.value = false;
    }
}

async function deleteCode(item) {
    if (editorBusy.value || item.product_count > 0) return;
    ask('Hapus kode game', 'Kode “' + item.name + '” akan dihapus dan tidak bisa dikembalikan.', 'Hapus kode', () => removeCode(item), true);
}

async function removeCode(item) {
    editorBusy.value = true;
    editorError.value = '';
    try {
        const payload = await requestJson(`/admin/nickname-tools/game-codes/${item.id}`, {
            method: 'DELETE',
            body: JSON.stringify({}),
        });
        codes.value = payload.game_codes || [];
        if (editingId.value === item.id) {
            editorOpen.value = false;
            resetEditor();
        }
        if (gameCode.value === item.code) {
            gameCode.value = nicknameCodes.value[0]?.code || '';
        }
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Kode game tidak berhasil dihapus.';
    } finally {
        editorBusy.value = false;
    }
}

async function toggleCode(item) {
    if (editorBusy.value) return;
    editorBusy.value = true;
    editorError.value = '';

    try {
        const payload = await requestJson(`/admin/nickname-tools/game-codes/${item.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                name: item.name,
                code: item.code,
                supports_nickname_check: item.supports_nickname_check,
                requires_server: item.requires_server,
                requires_region_check: item.requires_region_check,
                is_active: !item.is_active,
                sort_order: item.sort_order,
            }),
        });
        codes.value = payload.game_codes || [];
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Status kode game tidak berhasil diubah.';
    } finally {
        editorBusy.value = false;
    }
}

async function moveCode(item, direction) {
    if (reorderBusy.value) return;
    const index = codes.value.findIndex((candidate) => candidate.id === item.id);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= codes.value.length) return;

    const reordered = codes.value.map((candidate) => ({ ...candidate }));
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];

    reorderBusy.value = true;
    editorError.value = '';
    try {
        const payload = await requestJson('/admin/nickname-tools/game-codes/reorder', {
            method: 'PUT',
            body: JSON.stringify({ ordered_ids: reordered.map((candidate) => candidate.id) }),
        });
        codes.value = payload.game_codes || reordered;
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Urutan kode game tidak berhasil disimpan.';
    } finally {
        reorderBusy.value = false;
    }
}

if (nicknameCodes.value.length > 0) gameCode.value = nicknameCodes.value[0].code;
</script>

<template>
<Head title="Alat cek nama game" />
<AdminShell>
<div class="mx-auto max-w-[1540px]">
    <header class="mb-4 flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight">Alat cek nama game</h1>
            <p class="mt-1 max-w-3xl text-sm text-muted-foreground">Cek nama panggilan pemain, server/region, atau nomor pelanggan PLN sebelum pelanggan membeli. Data akses layanan tetap tersimpan di server.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full border px-3 py-1.5 text-xs font-bold" :class="integrationStatus.className">{{ integrationStatus.text }}</span>
            <a href="/admin/integrations" class="rounded-md border border-border bg-card px-3 py-2 text-xs font-bold text-muted-foreground hover:bg-muted">Pengaturan integrasi</a>
        </div>
    </header>

    <section>
        <header>
            <nav class="lf-admin-tabs" aria-label="Validasi akun">
                <Button
                    v-for="item in [['game','Cek nama game'],['region','Cek server'],['pln','Cek PLN'],['codes','Daftar kode game']]"
                    :key="item[0]"
                    type="button"
                    class="shrink-0"
                    :aria-current="tab===item[0] ? 'page' : undefined"
                    :variant="tab===item[0] ? 'secondary' : 'ghost'"
                    @click="selectTab(item[0])"
                >
                    {{ item[1] }}
                </Button>
            </nav>
        </header>

        <div v-if="tab!=='codes'" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="grid content-start gap-4 sm:grid-cols-2">
                <label v-if="tab==='game' || tab==='region'" class="text-sm font-bold text-muted-foreground">
                    Pilih game
                    <select v-model="gameCode" class="mt-1 block h-10 w-full rounded-md border border-border bg-muted px-3 text-xs text-foreground outline-none focus:border-primary">
                        <option value="" disabled>Pilih game</option>
                        <option v-for="item in (tab==='region' ? regionCodes : nicknameCodes)" :key="item.id" :value="item.code">
                            {{ item.name }} · {{ item.code }}
                        </option>
                    </select>
                </label>

                <label v-if="tab!=='pln'" class="text-sm font-bold text-muted-foreground">
                    ID pemain
                    <Input v-model="userId" maxlength="80" placeholder="Masukkan ID pemain" class="mt-1 h-10 text-xs" />
                </label>

                <label v-if="tab!=='pln'" class="text-sm font-bold text-muted-foreground">
                    Server / zona
                    <Input
                        v-model="server"
                        maxlength="40"
                        :placeholder="requiresServer || tab==='region' ? 'Wajib diisi' : 'Boleh dikosongkan'"
                        class="mt-1 h-10 text-xs"
                    />
                    <small class="mt-1 block font-normal text-muted-foreground">
                        {{ requiresServer || tab==='region' ? 'Wajib untuk pemeriksaan ini.' : 'Isi bila akun game memakai server atau zona.' }}
                    </small>
                </label>

                <label v-if="tab==='pln'" class="text-sm font-bold text-muted-foreground sm:col-span-2">
                    Nomor meter / ID pelanggan PLN
                    <Input
                        :value="customerNumber"
                        inputmode="numeric"
                        maxlength="12"
                        placeholder="11–12 angka"
                        class="mt-1 h-10 text-xs"
                        @input="customerNumber=$event.target.value.replace(/\D/g,'').slice(0,12)"
                    />
                </label>

                <p v-if="tab==='region'" class="rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground sm:col-span-2">Hanya game aktif yang ditandai perlu cek server yang bisa diperiksa di sini.</p>

                <div class="flex flex-wrap gap-2 border-t border-border pt-4 sm:col-span-2">
                    <Button type="button" :disabled="busy" class="h-9 bg-primary px-4 text-sm font-bold text-white disabled:opacity-50" @click="runCheck">
                        {{ busy ? 'Memeriksa…' : 'Cek data' }}
                    </Button>
                    <Button type="button" :disabled="busy" variant="outline" class="h-9 px-4 text-sm font-bold" @click="clearForm">
                        Bersihkan
                    </Button>
                </div>
            </div>

            <aside class="space-y-3">
                <div class="rounded-md border border-border bg-muted p-3">
                    <div class="flex items-center justify-between gap-2">
                        <strong class="text-sm">Alamat layanan yang dipakai</strong>
                        <span class="rounded-full border border-border bg-card px-2 py-1 text-xs font-bold text-muted-foreground">Disimpan di server</span>
                    </div>
                    <code class="mt-2 block break-all rounded border border-border bg-card px-2.5 py-2 text-xs text-primary">{{ endpointText }}</code>
                    <p v-if="validationConfig?.base_url" class="mt-2 break-all text-xs text-muted-foreground">{{ validationConfig.base_url }}</p>
                </div>
                <p v-if="error" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm font-medium">{{ error }}</p>
                <div v-if="result" role="status" class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-3 py-2.5 text-sm font-medium">
                    <template v-if="tab==='pln'">Nama pelanggan: {{ result.customer_name || 'Tidak ditemukan' }}</template>
                    <template v-else>Nama panggilan: {{ result.nickname || 'Tidak ditemukan' }}<span v-if="result.region"> · Server: {{ result.region }}</span></template>
                </div>
                <p class="rounded-md border border-border p-3 text-sm text-muted-foreground">Kunci akses tidak pernah dikirim ke peramban. Alamat layanan diatur di menu Integrasi.</p>
            </aside>
        </div>

        <div v-else class="p-4">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">Daftar kode game</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Game yang aktif belum tentu bisa dicek nama panggilannya. Dukungan cek nama, kebutuhan server, cek region, status, dan urutan diatur per game.</p>
                </div>
                <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                    <Input aria-label="Cari game atau kode" v-model="query" placeholder="Cari nama game atau kode" class="h-10 w-full text-sm sm:w-[280px]" />
                    <Button type="button" class="h-9 bg-primary px-4 text-sm font-bold text-white" @click="createCode">Tambah kode game</Button>
                </div>
            </div>

        <Sheet :open="editorOpen" @update:open="(value) => { if (!value) { editorOpen = false; resetEditor(); } }">
            <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader><SheetTitle>{{ editingId ? 'Ubah kode game' : 'Tambah kode game' }}</SheetTitle><SheetDescription>Perubahan di sini langsung dipakai sistem saat memeriksa akun.</SheetDescription></SheetHeader>
                <p v-if="editorError" role="alert" class="mt-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm font-medium">{{ editorError }}</p>



                <p v-if="editorNotice" class="mb-3 rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground">{{ editorNotice }}</p>

                <div class="grid gap-3 md:grid-cols-2">
                    <label class="text-xs font-bold text-muted-foreground">
                        Nama game
                        <Input v-model="form.name" maxlength="120" placeholder="Contoh: Mobile Legends" class="mt-1 h-10 text-xs" />
                    </label>
                    <label class="text-xs font-bold text-muted-foreground">
                        Kode game
                        <Input v-model="form.code" maxlength="100" placeholder="contoh-kode-game" class="mt-1 h-10 font-mono text-xs" @blur="normalizeCodeInput" />
                        <small class="mt-1 block font-normal text-muted-foreground">Huruf kecil, angka, dan tanda hubung. Jika kode yang sudah dipakai produk diubah, relasi produk ikut diperbarui.</small>
                    </label>
                </div>

                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-xs font-semibold text-muted-foreground">
                        <AdminSwitch v-model="form.supports_nickname_check" @update:model-value="syncNicknameSupportRules" />
                        Bisa dicek nama panggilannya
                    </label>
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-xs font-semibold text-muted-foreground">
                        <AdminSwitch v-model="form.requires_server" :disabled="!form.supports_nickname_check || form.requires_region_check" />
                        Wajib server / zona
                    </label>
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-xs font-semibold text-muted-foreground">
                        <AdminSwitch v-model="form.requires_region_check" :disabled="!form.supports_nickname_check" @update:model-value="form.requires_server = form.requires_server || form.requires_region_check" />
                        Perlu cek region
                    </label>
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-xs font-semibold text-muted-foreground">
                        <AdminSwitch v-model="form.is_active" />
                        Aktif
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Button type="button" :disabled="editorBusy" class="h-9 bg-primary px-4 text-sm font-bold text-white disabled:opacity-50" @click="saveCode">
                        {{ editorBusy ? 'Menyimpan…' : 'Simpan' }}
                    </Button>
                    <Button type="button" :disabled="editorBusy" variant="outline" class="h-9 px-4 text-sm font-bold" @click="editorOpen=false;resetEditor()">Batal</Button>
                </div>
                        </SheetContent>
        </Sheet>

            <div class="overflow-x-auto rounded-md border border-border" role="region" aria-label="Daftar aturan validasi game" tabindex="0">
                <AdminResponsiveTable :mobile-columns="[1,3,6,8]" class="min-w-[980px] w-full text-left text-xs">
                    <TableHeader class="bg-muted text-muted-foreground">
                        <TableRow>
                            <TableHead class="w-[92px] px-3 py-2.5">Urutan</TableHead>
                            <TableHead class="px-3 py-2.5">Game</TableHead>
                            <TableHead class="px-3 py-2.5">Kode game</TableHead>
                            <TableHead class="px-3 py-2.5">Cek nama</TableHead>
                            <TableHead class="px-3 py-2.5">Server / zona</TableHead>
                            <TableHead class="px-3 py-2.5">Region</TableHead>
                            <TableHead class="px-3 py-2.5">Status</TableHead>
                            <TableHead class="px-3 py-2.5">Produk</TableHead>
                            <TableHead class="px-3 py-2.5 text-right">Tindakan</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody class="divide-y divide-border">
                        <TableRow v-for="item in filteredCodes" :key="item.id" :class="{ 'opacity-60': !item.is_active }">
                            <TableCell class="px-3 py-2.5">
                                <div class="flex gap-1">
                                    <Button type="button" variant="outline" class="h-9 w-9 p-0 text-xs" :disabled="reorderBusy || codes[0]?.id===item.id || query.trim()!==''" aria-label="Pindahkan game ke atas" @click="moveCode(item,-1)">↑</Button>
                                    <Button type="button" variant="outline" class="h-9 w-9 p-0 text-xs" :disabled="reorderBusy || codes[codes.length-1]?.id===item.id || query.trim()!==''" aria-label="Pindahkan game ke bawah" @click="moveCode(item,1)">↓</Button>
                                </div>
                            </TableCell>
                            <TableCell class="px-3 py-2.5 font-semibold text-foreground">{{ item.name }}</TableCell>
                            <TableCell class="px-3 py-2.5 font-mono text-primary">{{ item.code }}</TableCell>
                            <TableCell class="px-3 py-2.5">
                                <Badge :variant="item.supports_nickname_check ? 'secondary' : 'outline'">{{ item.supports_nickname_check ? 'Didukung' : 'Tidak didukung' }}</Badge>
                            </TableCell>
                            <TableCell class="px-3 py-2.5">
                                <Badge :variant="item.requires_server ? 'outline' : 'secondary'">{{ item.requires_server ? 'Wajib' : 'Tidak' }}</Badge>
                            </TableCell>
                            <TableCell class="px-3 py-2.5">
                                <Badge :variant="item.requires_region_check ? 'outline' : 'secondary'">{{ item.requires_region_check ? 'Dicek' : 'Tidak' }}</Badge>
                            </TableCell>
                            <TableCell class="px-3 py-2.5">
                                <Badge :variant="item.is_active ? 'secondary' : 'outline'">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</Badge>
                            </TableCell>
                            <TableCell class="px-3 py-2.5 text-muted-foreground">{{ item.product_count }}</TableCell>
                            <TableCell class="px-3 py-2.5">
                                <div class="flex justify-end gap-1.5">
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold" @click="copyCode(item.code)">{{ copied===item.code ? 'Tersalin' : 'Salin' }}</Button>
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold" @click="editCode(item)">Ubah</Button>
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold" :disabled="editorBusy || (item.is_active && item.product_count>0)" :title="item.is_active && item.product_count>0 ? 'Masih dipakai produk' : ''" @click="toggleCode(item)">{{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</Button>
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold text-destructive" :disabled="editorBusy || item.product_count>0" :title="item.product_count>0 ? 'Masih dipakai produk' : 'Hapus kode game'" @click="deleteCode(item)">Hapus</Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="filteredCodes.length===0">
                            <TableCell colspan="9" class="px-4 py-10 text-center text-sm text-muted-foreground">Tidak ada kode game yang cocok.</TableCell>
                        </TableRow>
                    </TableBody>
                </AdminResponsiveTable>
            </div>
            <p v-if="query.trim()" class="mt-2 text-xs text-muted-foreground">Kosongkan pencarian untuk mengubah urutan.</p>
        </div>
    </section>

    <AdminConfirmDialog
        :open="Boolean(pendingAction)"
        :title="pendingAction?.title || ''"
        :message="pendingAction?.message || ''"
        :confirm-label="pendingAction?.confirmLabel || 'Lanjutkan'"
        :destructive="Boolean(pendingAction?.destructive)"
        @update:open="value => { if (!value) pendingAction = null; }"
        @confirm="runPending"
    />
</div>
</AdminShell>
</template>
