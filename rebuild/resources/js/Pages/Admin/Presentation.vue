<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import AdminModal from '../../Components/Admin/AdminModal.vue';

const props = defineProps({
    registry: { type: Object, default: () => ({}) },
    fields: { type: Object, default: () => ({}) },
    sections: { type: Object, default: () => ({}) },
    presentation: { type: Object, default: () => ({}) },
    theme: { type: Object, default: () => ({}) },
    themeFields: { type: Object, default: () => ({}) },
});

const tab = ref('text');
const tabs = [
    ['text', 'Teks halaman'], ['typography', 'Font & Panel'],
    ['sections', 'Bagian Homepage'], ['theme', 'Warna Website'],
];

// ---------- Teks halaman ----------
const query = ref('');
const group = ref('');
const groups = computed(() => [...new Set(Object.values(props.registry).map(item => item.group))]);
const pageName = value => String(value).replace('Pages/', '').replace('Components/', 'Layout/');
const entries = computed(() => Object.entries(props.registry).filter(([, item]) => {
    const term = query.value.trim().toLowerCase();
    return (!group.value || item.group === group.value)
        && (!term || `${item.label} ${item.group}`.toLowerCase().includes(term));
}));

const texts = reactive({ ...(props.presentation?.text || {}) });
const modal = ref(false);
const editing = ref(null);
const draft = ref('');
const saveText = () => {
    if (draft.value.trim() === '') delete texts[editing.value.key];
    else texts[editing.value.key] = draft.value;
    modal.value = false;
    save();
};
const openText = (key, item) => {
    editing.value = { key, item };
    draft.value = texts[key] || '';
    modal.value = true;
};
const resetText = () => {
    delete texts[editing.value.key];
    modal.value = false;
    save();
};

// ---------- Font & panel ----------
const sourceTypography = computed(() => props.presentation?.typography || {});
const typography = reactive(Object.fromEntries(['mobile', 'desktop'].map(viewport => [
    viewport,
    Object.fromEntries(Object.entries(props.fields).map(([key, definition]) => [
        key, sourceTypography.value?.[viewport]?.[key] ?? definition?.[viewport] ?? 0,
    ])),
])));
const restoreTypography = () => {
    for (const viewport of ['mobile', 'desktop']) {
        for (const [field, definition] of Object.entries(props.fields)) typography[viewport][field] = definition[viewport];
    }
};

// ---------- Bagian homepage ----------
const sections = reactive({ ...(props.presentation?.sections || {}) });

// ---------- Warna ----------
const colors = reactive({ ...(props.theme || {}) });
const restoreTheme = () => {
    for (const [key, field] of Object.entries(props.themeFields)) colors[key] = field.default;
};

const busy = ref(false);
const errors = ref({});
const save = () => {
    const options = {
        preserveScroll: true,
        onStart: () => { busy.value = true; },
        onFinish: () => { busy.value = false; },
        onError: bag => { errors.value = bag; },
    };
    if (tab.value === 'theme') {
        router.put('/admin/content/theme', { colors: { ...colors } }, options);
        return;
    }
    router.put('/admin/content/presentation', {
        text: { ...texts }, typography: { mobile: { ...typography.mobile }, desktop: { ...typography.desktop } },
        sections: { ...sections },
    }, options);
};
</script>

<template>
    <Head title="Tampilan Toko" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Tampilan Toko</h1>
                <p>Tulisan, ukuran huruf, bagian halaman depan, dan warna yang dilihat pelanggan. Ponsel dan desktop punya pengaturan sendiri.</p>
            </div>
            <div class="adm-head-actions">
                <Link href="/admin/content" class="adm-btn">Banner &amp; Konten</Link>
                <a href="/" target="_blank" rel="noreferrer" class="adm-btn">Lihat toko</a>
            </div>
        </header>

        <div class="adm-tabs" role="tablist" aria-label="Bagian tampilan toko">
            <button v-for="[key, text] in tabs" :key="key" type="button" role="tab"
                :aria-selected="tab === key" @click="tab = key">{{ text }}</button>
        </div>

        <!-- Teks halaman -->
        <section v-if="tab === 'text'" class="adm-card">
            <form class="adm-filters" @submit.prevent>
                <label class="adm-field narrow">
                    <span>Halaman</span>
                    <select v-model="group" class="adm-select">
                        <option value="">Semua halaman</option>
                        <option v-for="item in groups" :key="item" :value="item">{{ pageName(item) }}</option>
                    </select>
                </label>
                <label class="adm-field search">
                    <span>Cari tulisan</span>
                    <input v-model="query" class="adm-input" placeholder="Cari judul, tombol, atau informasi">
                </label>
                <div class="adm-filter-actions">
                    <button type="button" class="adm-btn" @click="query = ''; group = ''">Hapus filter</button>
                </div>
            </form>
            <p class="adm-toolbar">Kosongkan isian untuk memakai teks bawaan. Pilih satu baris untuk mengubahnya.</p>
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr><th>Tulisan</th><th>Halaman</th><th>Isi</th><th>Status</th><th>Tindakan</th></tr></thead>
                    <tbody>
                        <tr v-for="[key, item] in entries" :key="key">
                            <td><strong>{{ item.label }}</strong></td>
                            <td class="adm-note">{{ pageName(item.group) }}</td>
                            <td class="adm-note" style="max-width:420px">{{ texts[key] || item.default }}</td>
                            <td><span class="adm-tag" :class="texts[key] ? 'info' : 'plain'">{{ texts[key] ? 'Diubah' : 'Bawaan' }}</span></td>
                            <td><button type="button" class="adm-btn sm" @click="openText(key, item)">Ubah</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!entries.length" class="adm-empty">Tidak ada tulisan yang cocok dengan pencarian.</p>
        </section>

        <!-- Font & panel -->
        <section v-if="tab === 'typography'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Ukuran huruf &amp; panel</h2>
                    <p>Ukuran dalam px. Ketebalan 400 = normal, 500 = medium, 600 = semibold, 700 = bold.</p>
                </div>
                <button type="button" class="adm-btn" @click="restoreTypography">Kembalikan bawaan</button>
            </div>
            <div class="adm-card-body">
                <div class="adm-grid two">
                    <div v-for="viewport in ['mobile', 'desktop']" :key="viewport">
                        <h2 style="font-size:13px;margin:0 0 8px">{{ viewport === 'mobile' ? 'Ponsel' : 'Desktop' }}</h2>
                        <div class="adm-grid">
                            <label v-for="[key, field] in Object.entries(fields)" :key="key" class="adm-field">
                                <span>{{ field.label }}</span>
                                <input v-model.number="typography[viewport][key]" type="number" class="adm-input"
                                    :min="field.min" :max="field.max" :step="field.step || 1" required>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="adm-card-body" style="border-top:1px solid var(--a-line);display:flex;justify-content:flex-end">
                <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan ukuran</button>
            </div>
        </section>

        <!-- Bagian homepage -->
        <section v-if="tab === 'sections'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Bagian halaman depan</h2>
                </div>
            </div>
            <div class="adm-card-body" style="display:grid;gap:8px">
                <label v-for="(text, key) in sections" :key="key" class="adm-switch-row">
                    <button type="button" class="adm-switch" role="switch" :aria-checked="sections[key]" @click="sections[key] = !sections[key]" />
                    {{ props.sections[key] || key }}
                </label>
            </div>
            <div class="adm-card-body" style="border-top:1px solid var(--a-line);display:flex;justify-content:flex-end">
                <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan bagian</button>
            </div>
        </section>

        <!-- Warna -->
        <section v-if="tab === 'theme'" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Warna website</h2>
                    <p>Berlaku untuk halaman toko, checkout, dan pembayaran. Warna wajib HEX 6 digit.</p>
                </div>
                <button type="button" class="adm-btn" @click="restoreTheme">Kembalikan bawaan</button>
            </div>
            <div class="adm-card-body">
                <div class="adm-grid">
                    <label v-for="[key, field] in Object.entries(themeFields)" :key="key" class="adm-field">
                        <span>{{ field.label }}</span>
                        <div style="display:flex;gap:6px;align-items:center">
                            <input v-model="colors[key]" type="color" class="adm-color" :aria-label="field.label">
                            <input v-model="colors[key]" class="adm-input" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$" style="font-family:ui-monospace,monospace">
                        </div>
                    </label>
                </div>
            </div>
            <div class="adm-card-body" style="border-top:1px solid var(--a-line);display:flex;justify-content:flex-end">
                <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan warna</button>
            </div>
        </section>

        <p v-if="errors.text || errors.typography || errors.sections || errors.colors" class="adm-note" style="color:var(--a-danger)">
            {{ errors.text || errors.typography || errors.sections || errors.colors }}
        </p>

        <AdminModal v-model:open="modal" title="Ubah tulisan" :description="editing?.item?.group ? pageName(editing.item.group) : ''">
            <div class="adm-grid">
                <div class="adm-field full">
                    <span>{{ editing?.item?.label }}</span>
                    <p class="adm-note">Bawaan: {{ editing?.item?.default }}</p>
                </div>
                <label class="adm-field full">
                    <span>Isi tulisan</span>
                    <textarea v-model="draft" class="adm-textarea" rows="4" maxlength="5000" />
                    <span class="hint">Kosongkan untuk memakai teks bawaan.</span>
                </label>
            </div>
            <template #footer>
                <button v-if="texts[editing?.key]" type="button" class="adm-btn" @click="resetText">Pakai bawaan</button>
                <button type="button" class="adm-btn" @click="modal = false">Batal</button>
                <button type="button" class="adm-btn primary" @click="saveText">Simpan</button>
            </template>
        </AdminModal>
    </AdminLayout>
</template>
