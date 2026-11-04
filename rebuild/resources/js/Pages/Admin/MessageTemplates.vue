<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';

const props = defineProps({ entries: { type: Array, default: () => [] } });
const entries = ref(props.entries.map(x => ({ ...x })));
const key = ref(props.entries[0]?.key || '');
const selected = computed(() => entries.value.find(x => x.key === key.value));
const emails = computed(() => entries.value.filter(x => x.group === 'email'));
const admins = computed(() => entries.value.filter(x => x.group === 'admin'));
const activeField = ref('body');
const subjectInput = ref();
const bodyInput = ref();
const busy = ref(false);
const loading = ref(false);
const resultHtml = ref('');
const resultSubject = ref('');
const error = ref('');
watch(() => props.entries, value => { entries.value = value.map(x => ({ ...x })); });
const choose = x => { key.value = x.key; resultHtml.value = ''; resultSubject.value = ''; error.value = ''; };
const addToken = async name => {
    const field = activeField.value;
    const element = field === 'subject' ? subjectInput.value : bodyInput.value;
    const current = selected.value[field];
    const start = element?.selectionStart ?? current.length;
    const end = element?.selectionEnd ?? start;
    const token = '{' + name + '}';
    selected.value[field] = current.slice(0, start) + token + current.slice(end);
    await nextTick();
    element?.focus();
    element?.setSelectionRange(start + token.length, start + token.length);
};
const save = () => {
    if (!selected.value || busy.value) return;
    busy.value = true;
    error.value = '';
    router.put('/admin/message-templates/overrides', {
        template_key: selected.value.key, subject: selected.value.subject,
        body: selected.value.body, is_enabled: selected.value.is_enabled,
    }, {
        preserveScroll: true,
        onError: e => { error.value = Object.values(e).join(' '); },
        onFinish: () => { busy.value = false; },
    });
};
const reset = () => {
    if (!selected.value || busy.value || !window.confirm('Hapus perubahan dan kembali ke teks bawaan?')) return;
    busy.value = true;
    router.delete('/admin/message-templates/overrides/' + encodeURIComponent(selected.value.key), {
        preserveScroll: true, onFinish: () => { busy.value = false; resultHtml.value = ''; },
    });
};
const toggle = event => {
    if (!event.target.checked && ['email.verification', 'email.password_reset'].includes(selected.value.key) &&
        !window.confirm('PERINGATAN: Mematikan email ini dapat menghalangi pendaftaran atau pemulihan akun. Tetap nonaktifkan?')) {
        event.target.checked = true;
        return;
    }
    selected.value.is_enabled = event.target.checked;
};
const preview = async () => {
    if (!selected.value || loading.value) return;
    loading.value = true;
    error.value = '';
    try {
        const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        const response = await fetch('/admin/message-templates/preview', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {}) },
            body: JSON.stringify({ template_key: selected.value.key, subject: selected.value.subject,
                body: selected.value.body, is_enabled: selected.value.is_enabled }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Pratinjau gagal dimuat.');
        resultHtml.value = data.html;
        resultSubject.value = data.subject;
    } catch (e) { error.value = e.message; }
    finally { loading.value = false; }
};
const updated = value => value ? new Intl.DateTimeFormat('id-ID',
    { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value)) + ' WIB' : '—';
</script>

<template>
    <Head title="Template Email & Notifikasi" />
    <AdminLayout>
        <header class="adm-head"><div>
            <h1>Template Email &amp; Notifikasi</h1>
            <p>Edit teks tanpa mengubah rangka visual. Kontak support dan jam layanan tetap dari Pengaturan Toko.</p>
        </div></header>
        <section v-for="group in [{ title: 'Email Pelanggan', rows: emails }, { title: 'Notifikasi Admin', rows: admins }]" :key="group.title">
            <h2 class="tpl-section-title">{{ group.title }}</h2>
            <div class="tpl-scroll"><table class="tpl-table">
                <thead><tr><th>Template</th><th>Status</th><th>Terakhir Diubah</th><th>Aksi</th></tr></thead>
                <tbody><tr v-for="entry in group.rows" :key="entry.key" :class="{ chosen: key === entry.key }">
                    <td>{{ entry.label }}</td>
                    <td><span class="tpl-status" :class="{ modified: entry.status === 'Diubah', off: entry.status === 'Nonaktif' }">{{ entry.status }}</span></td>
                    <td>{{ updated(entry.updated_at) }}</td>
                    <td><button type="button" class="adm-btn sm primary" @click="choose(entry)">Edit</button></td>
                </tr></tbody>
            </table></div>
        </section>
        <section v-if="selected">
            <h2 class="tpl-section-title">Editor — {{ selected.label }}</h2>
            <div class="tpl-grid">
                <article class="tpl-card">
                    <div class="tpl-head"><h3>Edit Template</h3><label v-if="selected.group === 'email'">
                        Aktif <input type="checkbox" :checked="selected.is_enabled" @change="toggle" />
                    </label></div>
                    <label class="adm-field"><span>Subjek</span>
                        <input ref="subjectInput" v-model="selected.subject" class="adm-input" maxlength="255" @focus="activeField = 'subject'" />
                    </label>
                    <div class="adm-field"><span>Placeholder (klik untuk menyisipkan)</span>
                        <div class="tpl-chips">
                            <button v-for="token in selected.placeholders" :key="token" type="button" class="tpl-chip"
                                @mousedown.prevent @click="addToken(token)">{{ '{' + token + '}' }}</button>
                        </div>
                    </div>
                    <label class="adm-field"><span>Badan teks</span>
                        <textarea ref="bodyInput" v-model="selected.body" class="adm-textarea" rows="7" maxlength="10000" @focus="activeField = 'body'" />
                    </label>
                    <p v-if="selected.has_legacy" class="tpl-warning">Template ini memiliki penyesuaian dari editor lama. Pratinjau memperlihatkan rancangan baru; Simpan akan menggantikan penyesuaian lama, sedangkan Kembalikan ke Bawaan akan menghapus keduanya.</p>
                    <p v-if="error" class="tpl-warning" role="alert">{{ error }}</p>
                    <div class="tpl-buttons">
                        <button type="button" class="adm-btn primary" :disabled="busy" @click="save">Simpan</button>
                        <button type="button" class="adm-btn" :disabled="loading" @click="preview">Pratinjau</button>
                        <button type="button" class="tpl-danger" :disabled="busy" @click="reset">Kembalikan ke Bawaan</button>
                    </div>
                    <p class="tpl-hint">Sapaan otomatis, kotak ringkasan, tombol, tabel fakta, warna, dan footer terkunci di kode. {subjek_bawaan} dan {pesan_bawaan} menjaga teks asli; untuk admin gunakan {judul} dan {detail}.</p>
                </article>
                <article class="tpl-card">
                    <h3>Pratinjau (data contoh)</h3>
                    <p v-if="resultSubject"><strong>Subjek:</strong> {{ resultSubject }}</p>
                    <div v-if="resultHtml" class="tpl-preview"><iframe title="Pratinjau email" sandbox="" :srcdoc="resultHtml" /></div>
                    <div v-else class="tpl-empty">Klik Pratinjau untuk merender email contoh. Tidak ada email yang dikirim.</div>
                    <p v-if="['email.verification', 'email.password_reset'].includes(selected.key)" class="tpl-warning">Peringatan: menonaktifkan template ini dapat membuat pelanggan tidak bisa mendaftar atau memulihkan akun.</p>
                </article>
            </div>
        </section>
    </AdminLayout>
</template>

<style scoped>
.tpl-section-title{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#9eacc1;margin:24px 0 10px}
.tpl-scroll{overflow-x:auto;border:1px solid #293449;border-radius:9px}
.tpl-table{width:100%;min-width:540px;border-collapse:collapse;background:#111a2a;text-align:left;font-size:13px}
.tpl-table th,.tpl-table td{padding:10px 12px;border-bottom:1px solid #273346}
.tpl-table tr.chosen{background:#1a2d45}
.tpl-table th{font-size:12px;color:#9eacc1}
.tpl-table td:last-child{text-align:right}
.tpl-status{display:inline-block;padding:4px 9px;border-radius:99px;background:#263244;color:#b8c6d8;font-size:11px;font-weight:700}
.tpl-status.modified{background:#3b2f0b;color:#fbbf24}
.tpl-status.off{background:#3f1d1d;color:#f87171}
.tpl-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}
.tpl-card{min-width:0;padding:16px;background:#111a2a;border:1px solid #2b374a;border-radius:9px}
.tpl-card h3{font-size:15px;margin:0 0 12px}
.tpl-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.tpl-head label{display:flex;align-items:center;gap:8px;font-size:12px}
.tpl-chips,.tpl-buttons{display:flex;flex-wrap:wrap;gap:7px;margin:9px 0}
.tpl-chip{background:#1b304e;border:1px solid #35557b;color:#a4c6fa;border-radius:99px;padding:6px 9px;font-size:12px;cursor:pointer}
.tpl-danger{border:1px solid #7f1d1d;border-radius:6px;background:transparent;color:#f87171;padding:8px 11px;cursor:pointer}
.tpl-hint{font-size:12px;line-height:1.55;color:#9eacc1}
.tpl-preview{background:#fff;border-radius:7px;overflow:hidden}
.tpl-preview iframe{width:100%;height:480px;border:0;background:white}
.tpl-empty{padding:22px;background:#f4f4f5;color:#333;border-radius:6px;font-size:13px}
.tpl-warning{background:#3f1d1d;border:1px solid #7f1d1d;color:#fecaca;border-radius:6px;padding:10px;margin-top:12px;font-size:12px}
@media(max-width:850px){.tpl-grid{grid-template-columns:1fr}}
</style>
