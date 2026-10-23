<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';
import { copyText } from '../../lib/adminFormat';

const props = defineProps({
    gameCodes: { type: Array, default: () => [] },
    validationConfig: { type: Object, default: () => ({}) },
});

const codes = ref(props.gameCodes.map(item => ({ ...item })));
watch(() => props.gameCodes, value => { codes.value = value.map(item => ({ ...item })); }, { deep: true });

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

const activeCodes = computed(() => codes.value.filter(item => item.is_active));
const nicknameCodes = computed(() => activeCodes.value.filter(item => item.supports_nickname_check));
const regionCodes = computed(() => nicknameCodes.value.filter(item => item.requires_region_check));
const selectedGame = computed(() => codes.value.find(item => item.code === gameCode.value) || null);
const requiresServer = computed(() => Boolean(selectedGame.value?.requires_server));
const filteredCodes = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return codes.value;
    return codes.value.filter(item => item.name.toLowerCase().includes(needle) || item.code.toLowerCase().includes(needle));
});

const integrationStatus = computed(() => {
    if (!props.validationConfig?.is_active) return { text: 'Nonaktif', tone: 'bad' };
    if (!props.validationConfig?.is_configured) return { text: 'Belum lengkap', tone: 'warn' };
    return { text: 'Siap digunakan', tone: 'ok' };
});
const endpointText = computed(() => {
    const nickname = props.validationConfig?.nickname_path || 'Alamat cek nama belum diatur';
    const region = props.validationConfig?.region_path || 'Alamat cek server belum diatur';
    const pln = props.validationConfig?.pln_path || 'Alamat cek PLN belum diatur';
    if (tab.value === 'pln') return pln;
    if (tab.value === 'region' || selectedGame.value?.requires_region_check) return `${nickname} + ${region}`;
    return nickname;
});

const csrf = () => decodeURIComponent(document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11) || '');

const requestJson = async (url, options = {}) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
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
};

const resetResult = () => { result.value = null; error.value = ''; };

const selectTab = nextTab => {
    tab.value = nextTab;
    resetResult();
    if (nextTab === 'region' && !regionCodes.value.some(item => item.code === gameCode.value)) {
        gameCode.value = regionCodes.value[0]?.code || '';
    }
    if (nextTab === 'game' && !nicknameCodes.value.some(item => item.code === gameCode.value)) {
        gameCode.value = nicknameCodes.value[0]?.code || '';
    }
};

const clearForm = () => {
    gameCode.value = tab.value === 'region' ? (regionCodes.value[0]?.code || '') : (nicknameCodes.value[0]?.code || '');
    userId.value = '';
    server.value = '';
    customerNumber.value = '';
    resetResult();
};

const runCheck = async () => {
    if (busy.value) return;
    resetResult();
    busy.value = true;
    try {
        const body = tab.value === 'game'
            ? { action: 'game', game_code: gameCode.value, user_id: userId.value.trim(), server: server.value.trim() || null }
            : tab.value === 'region'
                ? { action: 'region', game_code: gameCode.value, user_id: userId.value.trim(), server: server.value.trim() }
                : { action: 'pln', customer_number: customerNumber.value.replace(/\D/g, '') };
        result.value = await requestJson('/admin/nickname-tools/check', { method: 'POST', body: JSON.stringify(body) });
    } catch (reason) {
        error.value = reason instanceof Error ? reason.message : 'Pemeriksaan tidak berhasil.';
    } finally {
        busy.value = false;
    }
};

const copy = async code => {
    if (!await copyText(code)) return;
    copied.value = code;
    setTimeout(() => { if (copied.value === code) copied.value = ''; }, 1500);
};

// ---------- daftar kode game ----------
const editorOpen = ref(false);
const editingId = ref(null);
const editorBusy = ref(false);
const editorError = ref('');
const editorNotice = ref('');
const reorderBusy = ref(false);
const pending = ref(null);
const form = reactive({
    name: '', code: '', supports_nickname_check: false, requires_server: false,
    requires_region_check: false, is_active: true, sort_order: null,
});

const resetEditor = () => {
    editingId.value = null;
    Object.assign(form, {
        name: '', code: '', supports_nickname_check: false, requires_server: false,
        requires_region_check: false, is_active: true, sort_order: null,
    });
    editorError.value = '';
    editorNotice.value = '';
};

const createCode = () => { resetEditor(); editorOpen.value = true; };

const editCode = item => {
    editingId.value = item.id;
    Object.assign(form, {
        name: item.name, code: item.code, supports_nickname_check: item.supports_nickname_check,
        requires_server: item.requires_server, requires_region_check: item.requires_region_check,
        is_active: item.is_active, sort_order: item.sort_order,
    });
    editorError.value = '';
    editorNotice.value = item.product_count > 0
        ? `Kode ini dipakai oleh ${item.product_count} produk. Jika kode diubah, produk terkait ikut diperbarui otomatis.`
        : '';
    editorOpen.value = true;
};

const normalizeCodeInput = () => {
    form.code = form.code.toLowerCase().trim().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
};

const saveCode = async () => {
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
                    name: form.name.trim(), code: form.code.trim(),
                    supports_nickname_check: form.supports_nickname_check,
                    requires_server: form.requires_server, requires_region_check: form.requires_region_check,
                    is_active: form.is_active, sort_order: form.sort_order,
                }),
            },
        );
        codes.value = payload.game_codes || [];
        editorOpen.value = false;
        resetEditor();
        if (!nicknameCodes.value.some(item => item.code === gameCode.value)) {
            gameCode.value = nicknameCodes.value[0]?.code || '';
        }
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Kode game tidak berhasil disimpan.';
    } finally {
        editorBusy.value = false;
    }
};

const toggleCode = async item => {
    if (editorBusy.value) return;
    editorBusy.value = true;
    editorError.value = '';
    try {
        const payload = await requestJson(`/admin/nickname-tools/game-codes/${item.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                name: item.name, code: item.code, supports_nickname_check: item.supports_nickname_check,
                requires_server: item.requires_server, requires_region_check: item.requires_region_check,
                is_active: !item.is_active, sort_order: item.sort_order,
            }),
        });
        codes.value = payload.game_codes || [];
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Status kode game tidak berhasil diubah.';
    } finally {
        editorBusy.value = false;
    }
};

const removeCode = async item => {
    editorBusy.value = true;
    editorError.value = '';
    try {
        const payload = await requestJson(`/admin/nickname-tools/game-codes/${item.id}`, { method: 'DELETE', body: JSON.stringify({}) });
        codes.value = payload.game_codes || [];
        if (gameCode.value === item.code) gameCode.value = nicknameCodes.value[0]?.code || '';
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Kode game tidak berhasil dihapus.';
    } finally {
        editorBusy.value = false;
        pending.value = null;
    }
};

const moveCode = async (item, direction) => {
    if (reorderBusy.value) return;
    const index = codes.value.findIndex(candidate => candidate.id === item.id);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= codes.value.length) return;
    const reordered = codes.value.map(candidate => ({ ...candidate }));
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    reorderBusy.value = true;
    editorError.value = '';
    try {
        const payload = await requestJson('/admin/nickname-tools/game-codes/reorder', {
            method: 'PUT', body: JSON.stringify({ ordered_ids: reordered.map(candidate => candidate.id) }),
        });
        codes.value = payload.game_codes || reordered;
    } catch (reason) {
        editorError.value = reason instanceof Error ? reason.message : 'Urutan kode game tidak berhasil disimpan.';
    } finally {
        reorderBusy.value = false;
    }
};

if (nicknameCodes.value.length > 0) gameCode.value = nicknameCodes.value[0].code;
</script>

<template>
    <Head title="Alat cek nama game" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Alat cek nama game</h1>
                <p>Cek nama panggilan pemain, server/region, atau nomor pelanggan PLN sebelum pelanggan membeli.</p>
            </div>
            <div class="adm-head-actions">
                <span class="adm-tag" :class="integrationStatus.tone">{{ integrationStatus.text }}</span>
                <Link href="/admin/integrations" class="adm-btn">Pengaturan integrasi</Link>
            </div>
        </header>

        <div class="adm-tabs" role="tablist" aria-label="Validasi akun">
            <button v-for="[key, text] in [['game', 'Cek nama game'], ['region', 'Cek server'], ['pln', 'Cek PLN'], ['codes', 'Daftar kode game']]"
                :key="key" type="button" role="tab" :aria-selected="tab === key" @click="selectTab(key)">{{ text }}</button>
        </div>

        <section v-if="tab !== 'codes'" class="adm-split">
            <div class="adm-card">
                <div class="adm-card-head"><h2>{{ tab === 'pln' ? 'Cek pelanggan PLN' : tab === 'region' ? 'Cek server / zona' : 'Cek nama panggilan' }}</h2></div>
                <form class="adm-card-body" @submit.prevent="runCheck">
                    <div class="adm-grid">
                        <label v-if="tab !== 'pln'" class="adm-field full">
                            <span>Pilih game</span>
                            <select v-model="gameCode" class="adm-select">
                                <option value="" disabled>Pilih game</option>
                                <option v-for="item in (tab === 'region' ? regionCodes : nicknameCodes)" :key="item.id" :value="item.code">
                                    {{ item.name }} · {{ item.code }}
                                </option>
                            </select>
                        </label>
                        <label v-if="tab !== 'pln'" class="adm-field">
                            <span>ID pemain</span>
                            <input v-model="userId" class="adm-input" maxlength="80" placeholder="Masukkan ID pemain">
                        </label>
                        <label v-if="tab !== 'pln'" class="adm-field">
                            <span>Server / zona</span>
                            <input v-model="server" class="adm-input" maxlength="40"
                                :placeholder="requiresServer || tab === 'region' ? 'Wajib diisi' : 'Boleh dikosongkan'">
                            <span class="hint">{{ requiresServer || tab === 'region' ? 'Wajib untuk pemeriksaan ini.' : 'Isi bila akun game memakai server atau zona.' }}</span>
                        </label>
                        <label v-if="tab === 'pln'" class="adm-field full">
                            <span>Nomor meter / ID pelanggan PLN</span>
                            <input :value="customerNumber" class="adm-input" inputmode="numeric" maxlength="12" placeholder="11–12 angka"
                                @input="customerNumber = $event.target.value.replace(/\D/g, '').slice(0, 12)">
                        </label>
                    </div>
                    <p v-if="tab === 'region'" class="adm-note" style="margin-top:10px">Hanya game aktif yang ditandai perlu cek server yang bisa diperiksa di sini.</p>
                    <div style="display:flex;gap:6px;margin-top:12px">
                        <button type="submit" class="adm-btn primary" :disabled="busy">{{ busy ? 'Memeriksa…' : 'Cek data' }}</button>
                        <button type="button" class="adm-btn" :disabled="busy" @click="clearForm">Bersihkan</button>
                    </div>
                </form>
            </div>

            <div class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2>Alamat layanan yang dipakai</h2>
                    </div>
                </div>
                <div class="adm-card-body" style="display:grid;gap:8px">
                    <code class="adm-code">{{ endpointText }}</code>
                    <p v-if="validationConfig?.base_url" class="adm-note">{{ validationConfig.base_url }}</p>
                    <p v-if="error" class="adm-alert bad">{{ error }}</p>
                    <div v-if="result" class="adm-alert ok">
                        <template v-if="tab === 'pln'">Nama pelanggan: {{ result.customer_name || 'Tidak ditemukan' }}</template>
                        <template v-else>Nama panggilan: {{ result.nickname || 'Tidak ditemukan' }}<span v-if="result.region"> · Server: {{ result.region }}</span></template>
                    </div>
                    <p class="adm-note">Kunci akses tidak pernah dikirim ke peramban. Alamat layanan diatur di menu Integrasi.</p>
                </div>
            </div>
        </section>

        <section v-else class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Daftar kode game</h2>
                    <p>Game yang aktif belum tentu bisa dicek nama panggilannya. Dukungan cek nama, kebutuhan server, cek region, status, dan urutan diatur per game.</p>
                </div>
                <button type="button" class="adm-btn primary" @click="createCode">Tambah kode game</button>
            </div>
            <form class="adm-filters" @submit.prevent>
                <label class="adm-field search">
                    <span>Cari</span>
                    <input v-model="query" class="adm-input" placeholder="Nama game atau kode">
                </label>
                <div class="adm-filter-actions">
                    <button type="button" class="adm-btn" @click="query = ''">Hapus filter</button>
                </div>
            </form>
            <p v-if="query.trim()" class="adm-toolbar">Kosongkan pencarian untuk mengubah urutan.</p>
            <p v-if="editorError" class="adm-alert bad" style="margin:10px 14px">{{ editorError }}</p>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>Urutan</th><th>Game</th><th>Kode game</th><th>Cek nama</th>
                            <th>Server / zona</th><th>Region</th><th>Status</th><th>Produk</th><th>Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in filteredCodes" :key="item.id">
                            <td>
                                <div style="display:flex;gap:4px">
                                    <button type="button" class="adm-btn sm" :disabled="reorderBusy || codes[0]?.id === item.id || query.trim() !== ''"
                                        aria-label="Pindahkan game ke atas" @click="moveCode(item, -1)">↑</button>
                                    <button type="button" class="adm-btn sm" :disabled="reorderBusy || codes[codes.length - 1]?.id === item.id || query.trim() !== ''"
                                        aria-label="Pindahkan game ke bawah" @click="moveCode(item, 1)">↓</button>
                                </div>
                            </td>
                            <td><strong>{{ item.name }}</strong></td>
                            <td class="mono">{{ item.code }}</td>
                            <td><span class="adm-tag" :class="item.supports_nickname_check ? 'ok' : 'plain'">{{ item.supports_nickname_check ? 'Didukung' : 'Tidak didukung' }}</span></td>
                            <td><span class="adm-tag" :class="item.requires_server ? 'warn' : 'plain'">{{ item.requires_server ? 'Wajib' : 'Tidak' }}</span></td>
                            <td><span class="adm-tag" :class="item.requires_region_check ? 'warn' : 'plain'">{{ item.requires_region_check ? 'Dicek' : 'Tidak' }}</span></td>
                            <td><span class="adm-tag" :class="item.is_active ? 'ok' : 'bad'">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td>{{ item.product_count }}</td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap">
                                    <button type="button" class="adm-btn sm" @click="copy(item.code)">{{ copied === item.code ? 'Tersalin' : 'Salin' }}</button>
                                    <button type="button" class="adm-btn sm" @click="editCode(item)">Ubah</button>
                                    <button type="button" class="adm-btn sm" :disabled="editorBusy || (item.is_active && item.product_count > 0)"
                                        :title="item.is_active && item.product_count > 0 ? 'Masih dipakai produk' : ''" @click="toggleCode(item)">
                                        {{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                    <button v-if="item.product_count === 0" type="button" class="adm-btn sm danger" :disabled="editorBusy"
                                        @click="pending = item">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!filteredCodes.length" class="adm-empty">Tidak ada kode game yang cocok.</p>
        </section>

        <AdminModal v-model:open="editorOpen" :title="editingId ? 'Ubah kode game' : 'Tambah kode game'" description="Perubahan di sini langsung dipakai sistem saat memeriksa akun.">
            <p v-if="editorError" class="adm-alert bad">{{ editorError }}</p>
            <p v-if="editorNotice" class="adm-alert">{{ editorNotice }}</p>
            <div class="adm-grid">
                <label class="adm-field">
                    <span>Nama game <b class="req">*</b></span>
                    <input v-model="form.name" class="adm-input" maxlength="120" placeholder="Contoh: Mobile Legends">
                </label>
                <label class="adm-field">
                    <span>Kode game <b class="req">*</b></span>
                    <input v-model="form.code" class="adm-input mono" maxlength="100" placeholder="contoh-kode-game" @blur="normalizeCodeInput">
                    <span class="hint">Huruf kecil, angka, dan tanda hubung. Jika kode yang sudah dipakai produk diubah, relasi produk ikut diperbarui.</span>
                </label>
            </div>
            <div style="display:grid;gap:8px;margin-top:10px">
                <label class="adm-switch-row">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="form.supports_nickname_check" @click="form.supports_nickname_check = !form.supports_nickname_check" />
                    Bisa dicek nama panggilannya
                </label>
                <label class="adm-switch-row">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="form.requires_server"
                        :disabled="!form.supports_nickname_check || form.requires_region_check"
                        @click="form.requires_server = !form.requires_server" />
                    Wajib server / zona
                </label>
                <label class="adm-switch-row">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="form.requires_region_check"
                        :disabled="!form.supports_nickname_check"
                        @click="form.requires_region_check = !form.requires_region_check; form.requires_server = form.requires_server || form.requires_region_check" />
                    Perlu cek region
                </label>
                <label class="adm-switch-row">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="form.is_active" @click="form.is_active = !form.is_active" />
                    Aktif
                </label>
            </div>
            <template #footer>
                <button type="button" class="adm-btn" @click="editorOpen = false; resetEditor()">Batal</button>
                <button type="button" class="adm-btn primary" :disabled="editorBusy" @click="saveCode">{{ editorBusy ? 'Menyimpan…' : 'Simpan' }}</button>
            </template>
        </AdminModal>

        <AdminModal :open="!!pending" title="Hapus kode game"
            :description="pending ? 'Kode “' + pending.name + '” akan dihapus dan tidak bisa dikembalikan.' : ''"
            @update:open="value => { if (!value) pending = null; }">
            <p class="adm-note">Kode ini belum dipakai produk mana pun.</p>
            <template #footer>
                <button type="button" class="adm-btn" @click="pending = null">Batal</button>
                <button type="button" class="adm-btn danger" @click="removeCode(pending)">Hapus kode</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
