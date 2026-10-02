<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

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
const form = reactive({
    name: '',
    code: '',
    requires_server: false,
    requires_region_check: false,
    is_active: true,
    sort_order: null,
});

const activeCodes = computed(() => codes.value.filter((item) => item.is_active));
const regionCodes = computed(() => activeCodes.value.filter((item) => item.requires_region_check));
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
    if (!props.validationConfig?.is_active) return { text: 'Nonaktif', className: 'border-slate-200 bg-slate-50 text-slate-600' };
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
    if (nextTab === 'game' && !activeCodes.value.some((item) => item.code === gameCode.value)) {
        gameCode.value = activeCodes.value[0]?.code || '';
    }
}

function clearForm() {
    gameCode.value = tab.value === 'region'
        ? (regionCodes.value[0]?.code || '')
        : (activeCodes.value[0]?.code || '');
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

    if (form.requires_region_check) form.requires_server = true;

    try {
        const payload = await requestJson(
            editingId.value ? `/admin/nickname-tools/game-codes/${editingId.value}` : '/admin/nickname-tools/game-codes',
            {
                method: editingId.value ? 'PUT' : 'POST',
                body: JSON.stringify({
                    name: form.name.trim(),
                    code: form.code.trim(),
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

        if (!activeCodes.value.some((item) => item.code === gameCode.value)) {
            gameCode.value = activeCodes.value[0]?.code || '';
        }
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Kode game tidak berhasil disimpan.';
    } finally {
        editorBusy.value = false;
    }
}

async function deleteCode(item) {
    if (editorBusy.value || item.product_count > 0) return;
    if (!window.confirm(`Hapus kode game “${item.name}”? Tindakan ini tidak dapat dibatalkan.`)) return;

    editorBusy.value = true;
    editorError.value = '';
    try {
        const payload = await requestJson(`/admin/nickname-tools/game-codes/${item.id}`, {
            method: 'DELETE',
            body: JSON.stringify({}),
        });
        codes.value = payload.game_codes || [];
        if (gameCode.value === item.code) {
            gameCode.value = activeCodes.value[0]?.code || '';
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

if (activeCodes.value.length > 0) gameCode.value = activeCodes.value[0].code;
</script>

<template>
<Head title="Validasi Akun" />
<AdminShell>
<div class="mx-auto max-w-[1540px]">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-[-0.025em] text-[#20324c]">Validasi Akun</h1>
            <p class="mt-1 text-xs text-[#7a899e]">Uji data akun dan kelola aturan validasi game. Credential tetap tersimpan di backend.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full border px-3 py-1.5 text-xs font-bold" :class="integrationStatus.className">{{ integrationStatus.text }}</span>
            <a href="/admin/integrations" class="rounded-md border border-[#dfe5ed] bg-white px-3 py-2 text-xs font-bold text-[#52627a] hover:bg-[#f8fafc]">Pengaturan Integrasi</a>
        </div>
    </div>

    <section class="overflow-hidden rounded-lg border border-[#e1e6ed] bg-white">
        <header class="border-b border-[#edf0f4] px-4 pt-3">
            <div class="flex gap-1 overflow-x-auto">
                <Button
                    v-for="item in [['game','Cek Game'],['region','Cek Region'],['pln','PLN'],['codes','Kode Game']]"
                    :key="item[0]"
                    type="button"
                    class="h-9 shrink-0 rounded-t-md px-3 text-sm font-bold transition"
                    :class="tab===item[0] ? 'bg-[#1769e8] text-white' : 'text-[#62728a] hover:bg-[#f5f7fa]'"
                    @click="selectTab(item[0])"
                >
                    {{ item[1] }}
                </Button>
            </div>
        </header>

        <div v-if="tab!=='codes'" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="grid content-start gap-4 sm:grid-cols-2">
                <label v-if="tab==='game' || tab==='region'" class="text-sm font-bold text-[#52627a]">
                    Game
                    <select v-model="gameCode" class="mt-1 block h-10 w-full rounded-md border border-[#dfe5ed] bg-[#f8fafc] px-3 text-xs text-[#243653] outline-none focus:border-[#8cb6ef]">
                        <option value="" disabled>Pilih game</option>
                        <option v-for="item in (tab==='region' ? regionCodes : activeCodes)" :key="item.id" :value="item.code">
                            {{ item.name }} · {{ item.code }}
                        </option>
                    </select>
                </label>

                <label v-if="tab!=='pln'" class="text-sm font-bold text-[#52627a]">
                    User ID
                    <Input v-model="userId" maxlength="80" placeholder="Masukkan User ID" class="mt-1 h-10 text-xs" />
                </label>

                <label v-if="tab!=='pln'" class="text-sm font-bold text-[#52627a]">
                    Server / Zone
                    <Input
                        v-model="server"
                        maxlength="40"
                        :placeholder="requiresServer || tab==='region' ? 'Wajib diisi' : 'Opsional'"
                        class="mt-1 h-10 text-xs"
                    />
                    <small class="mt-1 block font-normal text-[#8b98aa]">
                        {{ requiresServer || tab==='region' ? 'Wajib untuk pemeriksaan ini.' : 'Isi jika akun game menggunakan Server / Zone.' }}
                    </small>
                </label>

                <label v-if="tab==='pln'" class="text-sm font-bold text-[#52627a] sm:col-span-2">
                    Nomor Meter / ID Pelanggan PLN
                    <Input
                        :value="customerNumber"
                        inputmode="numeric"
                        maxlength="12"
                        placeholder="11–12 angka"
                        class="mt-1 h-10 text-xs"
                        @input="customerNumber=$event.target.value.replace(/\D/g,'').slice(0,12)"
                    />
                </label>

                <div v-if="tab==='region'" class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs leading-4 text-blue-800 sm:col-span-2">
                    Hanya game aktif yang ditandai “Perlu cek region” yang tersedia pada pemeriksaan ini.
                </div>

                <div class="flex flex-wrap gap-2 border-t border-[#edf0f4] pt-4 sm:col-span-2">
                    <Button type="button" :disabled="busy" class="h-9 bg-[#1769e8] px-4 text-sm font-bold text-white disabled:opacity-50" @click="runCheck">
                        {{ busy ? 'Memeriksa...' : 'Cek Data' }}
                    </Button>
                    <Button type="button" :disabled="busy" variant="outline" class="h-9 px-4 text-sm font-bold" @click="clearForm">
                        Bersihkan
                    </Button>
                </div>
            </div>

            <aside class="space-y-3">
                <div class="rounded-md border border-[#e1e6ed] bg-[#f8fafc] p-3">
                    <div class="flex items-center justify-between gap-2">
                        <strong class="text-sm text-[#34445f]">Alamat layanan aktif</strong>
                        <span class="rounded-full border border-slate-200 bg-white px-2 py-1 text-xs font-bold text-slate-500">Hanya di server</span>
                    </div>
                    <code class="mt-2 block break-all rounded border border-[#e5eaf1] bg-white px-2.5 py-2 text-xs text-[#1769e8]">{{ endpointText }}</code>
                    <p v-if="validationConfig?.base_url" class="mt-2 break-all text-xs text-[#8190a5]">{{ validationConfig.base_url }}</p>
                </div>
                <div v-if="error" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-xs font-semibold leading-4 text-rose-700">{{ error }}</div>
                <div v-if="result" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs font-semibold leading-4 text-emerald-700">
                    <template v-if="tab==='pln'">Nama pelanggan: {{ result.customer_name || '-' }}</template>
                    <template v-else>Nickname: {{ result.nickname || '-' }}<span v-if="result.region"> · Region: {{ result.region }}</span></template>
                </div>
                <div class="rounded-md border border-[#e1e6ed] bg-white p-3 text-xs leading-4 text-[#52627a]">
                    API key tidak pernah dikirim ke browser. Alamat utama dan jalur layanan dapat diubah melalui menu Integrasi.
                </div>
            </aside>
        </div>

        <div v-else class="p-4">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-sm font-extrabold text-[#243653]">Daftar Kode Game</h2>
                    <p class="mt-1 text-xs text-[#8190a5]">Nama, kode, kebutuhan Server / Zone, pemeriksaan region, status, dan urutan disimpan di database.</p>
                </div>
                <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                    <Input v-model="query" placeholder="Cari game atau kode..." class="h-9 w-full text-sm sm:w-[280px]" />
                    <Button type="button" class="h-9 bg-[#1769e8] px-4 text-sm font-bold text-white" @click="createCode">Tambah Kode Game</Button>
                </div>
            </div>

            <div v-if="editorError" class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ editorError }}</div>

            <div v-if="editorOpen" class="mb-4 rounded-lg border border-[#dfe5ed] bg-[#f8fafc] p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-xs font-extrabold text-[#243653]">{{ editingId ? 'Edit Kode Game' : 'Tambah Kode Game' }}</h3>
                        <p class="mt-0.5 text-xs text-[#8190a5]">Perubahan langsung menjadi sumber aturan validasi backend.</p>
                    </div>
                    <Button type="button" variant="ghost" class="h-8 px-2 text-xs" @click="editorOpen=false;resetEditor()">Tutup</Button>
                </div>

                <div v-if="editorNotice" class="mb-3 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800">{{ editorNotice }}</div>

                <div class="grid gap-3 md:grid-cols-2">
                    <label class="text-xs font-bold text-[#52627a]">
                        Nama game
                        <Input v-model="form.name" maxlength="120" placeholder="Contoh: Mobile Legends" class="mt-1 h-10 text-xs" />
                    </label>
                    <label class="text-xs font-bold text-[#52627a]">
                        Kode game
                        <Input v-model="form.code" maxlength="100" placeholder="contoh-kode-game" class="mt-1 h-10 font-mono text-xs" @blur="normalizeCodeInput" />
                        <small class="mt-1 block font-normal text-[#8b98aa]">Huruf kecil, angka, dan tanda hubung. Jika kode yang sudah dipakai produk diubah, relasi produk ikut diperbarui.</small>
                    </label>
                </div>

                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-[#dfe5ed] bg-white px-3 py-2 text-xs font-semibold text-[#52627a]">
                        <input v-model="form.requires_server" type="checkbox" class="h-4 w-4 rounded border-[#cbd5e1]" :disabled="form.requires_region_check" />
                        Wajib Server / Zone
                    </label>
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-[#dfe5ed] bg-white px-3 py-2 text-xs font-semibold text-[#52627a]">
                        <input v-model="form.requires_region_check" type="checkbox" class="h-4 w-4 rounded border-[#cbd5e1]" @change="form.requires_server = form.requires_server || form.requires_region_check" />
                        Perlu cek region
                    </label>
                    <label class="flex min-h-12 items-center gap-2 rounded-md border border-[#dfe5ed] bg-white px-3 py-2 text-xs font-semibold text-[#52627a]">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[#cbd5e1]" />
                        Aktif
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Button type="button" :disabled="editorBusy" class="h-9 bg-[#1769e8] px-4 text-sm font-bold text-white disabled:opacity-50" @click="saveCode">
                        {{ editorBusy ? 'Menyimpan...' : 'Simpan' }}
                    </Button>
                    <Button type="button" :disabled="editorBusy" variant="outline" class="h-9 px-4 text-sm font-bold" @click="editorOpen=false;resetEditor()">Batal</Button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-md border border-[#e1e6ed]">
                <Table class="min-w-[980px] w-full text-left text-xs">
                    <TableHeader class="bg-[#f8fafc] text-[#607089]">
                        <TableRow>
                            <TableHead class="w-[92px] px-3 py-2.5">Urutan</TableHead>
                            <TableHead class="px-3 py-2.5">Game</TableHead>
                            <TableHead class="px-3 py-2.5">Kode Game</TableHead>
                            <TableHead class="px-3 py-2.5">Server / Zone</TableHead>
                            <TableHead class="px-3 py-2.5">Region</TableHead>
                            <TableHead class="px-3 py-2.5">Status</TableHead>
                            <TableHead class="px-3 py-2.5">Produk</TableHead>
                            <TableHead class="px-3 py-2.5 text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody class="divide-y divide-[#edf0f4] bg-white">
                        <TableRow v-for="item in filteredCodes" :key="item.id" :class="{ 'opacity-60': !item.is_active }">
                            <TableCell class="px-3 py-2.5">
                                <div class="flex gap-1">
                                    <Button type="button" variant="outline" class="h-7 w-7 p-0 text-xs" :disabled="reorderBusy || codes[0]?.id===item.id || query.trim()!==''" @click="moveCode(item,-1)">↑</Button>
                                    <Button type="button" variant="outline" class="h-7 w-7 p-0 text-xs" :disabled="reorderBusy || codes[codes.length-1]?.id===item.id || query.trim()!==''" @click="moveCode(item,1)">↓</Button>
                                </div>
                            </TableCell>
                            <TableCell class="px-3 py-2.5 font-semibold text-[#34445f]">{{ item.name }}</TableCell>
                            <TableCell class="px-3 py-2.5 font-mono text-[#1769e8]">{{ item.code }}</TableCell>
                            <TableCell class="px-3 py-2.5">
                                <span class="rounded-full px-2 py-1 text-xs font-bold" :class="item.requires_server ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'">
                                    {{ item.requires_server ? 'Wajib' : 'Tidak' }}
                                </span>
                            </TableCell>
                            <TableCell class="px-3 py-2.5">
                                <span class="rounded-full px-2 py-1 text-xs font-bold" :class="item.requires_region_check ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500'">
                                    {{ item.requires_region_check ? 'Dicek' : 'Tidak' }}
                                </span>
                            </TableCell>
                            <TableCell class="px-3 py-2.5">
                                <span class="rounded-full px-2 py-1 text-xs font-bold" :class="item.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                                    {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </TableCell>
                            <TableCell class="px-3 py-2.5 text-[#52627a]">{{ item.product_count }}</TableCell>
                            <TableCell class="px-3 py-2.5">
                                <div class="flex justify-end gap-1.5">
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold" @click="copyCode(item.code)">{{ copied===item.code ? 'Tersalin' : 'Salin' }}</Button>
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold" @click="editCode(item)">Edit</Button>
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold" :disabled="editorBusy || (item.is_active && item.product_count>0)" :title="item.is_active && item.product_count>0 ? 'Masih digunakan produk' : ''" @click="toggleCode(item)">{{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</Button>
                                    <Button type="button" variant="outline" class="h-8 px-2.5 text-xs font-bold text-rose-600" :disabled="editorBusy || item.product_count>0" :title="item.product_count>0 ? 'Masih digunakan produk' : 'Hapus kode game'" @click="deleteCode(item)">Hapus</Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="filteredCodes.length===0">
                            <TableCell colspan="8" class="px-4 py-10 text-center text-sm text-[#8190a5]">Tidak ada kode game yang cocok.</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <p v-if="query.trim()" class="mt-2 text-xs text-[#8b98aa]">Kosongkan pencarian untuk mengubah urutan.</p>
        </div>
    </section>
</div>
</AdminShell>
</template>
