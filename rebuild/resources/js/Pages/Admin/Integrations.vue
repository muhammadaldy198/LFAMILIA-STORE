<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import { num, wibDateTime, copyText } from '../../lib/adminFormat';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    callbackUrls: { type: Object, default: () => ({}) },
});

const tab = ref(props.integrations[0]?.code || '');
const copied = ref('');
const busy = ref('');
const result = reactive({});

const editableProfile = (profile = {}) => {
    const fields = (profile.fields || []).map(field => ({ ...field }));
    return {
        fields,
        config: Object.fromEntries(fields.map(field => [
            field.key, field.value ?? (field.type === 'boolean' ? false : ''),
        ])),
        clear_secrets: [],
    };
};

const toItem = item => {
    const profiles = {};
    Object.entries(item.environment_profiles || {}).forEach(([environment, profile]) => {
        profiles[environment] = editableProfile(profile);
    });
    const selected = item.environment && profiles[item.environment] ? profiles[item.environment] : editableProfile(item);
    return { ...item, profiles, fields: selected.fields, config: selected.config, clear_secrets: selected.clear_secrets };
};

const items = reactive(props.integrations.map(toItem));
watch(() => props.integrations, value => {
    const draft = new Map(items.map(item => [item.code, item]));
    items.splice(0, items.length, ...(value || []).map(raw => {
        const fresh = toItem(raw);
        const current = draft.get(fresh.code);
        // Isian yang belum disimpan jangan dibuang saat prop disegarkan.
        if (current && JSON.stringify(current.config) !== JSON.stringify(fresh.config)) {
            fresh.config = current.config;
            fresh.clear_secrets = current.clear_secrets;
        }
        return fresh;
    }));
    if (!items.some(item => item.code === tab.value)) tab.value = items[0]?.code || '';
});

const current = computed(() => items.find(item => item.code === tab.value) || items[0] || null);

const needsAttention = item => item.is_active && (
    !item.required_complete || item.connection?.status === 'FAILED' || item.callback?.status === 'NOT_READY'
);

const connectionLabel = status => ({
    VERIFIED: 'Sudah terhubung', FAILED: 'Gagal terhubung', CONFIGURED: 'Data akses lengkap',
    UNVERIFIED: 'Belum dipastikan', NOT_TESTED: 'Belum diuji', NOT_CONFIGURED: 'Belum diatur',
}[status] || 'Belum diketahui');

const connectionTone = status => ({
    VERIFIED: 'ok', FAILED: 'bad', NOT_CONFIGURED: 'bad', CONFIGURED: 'warn', UNVERIFIED: 'warn',
}[status] || 'plain');

const readinessTone = item => {
    if (!item.is_active) return 'plain';
    if (needsAttention(item)) return 'bad';
    return item.readiness?.status === 'READY' ? 'ok' : 'warn';
};

const callbackTone = status => ({ READY: 'ok', NOT_READY: 'bad', NOT_REQUIRED: 'plain' }[status] || 'plain');

const toggleClearSecret = (item, key) => {
    const index = item.clear_secrets.indexOf(key);
    if (index >= 0) {
        item.clear_secrets.splice(index, 1);
    } else {
        item.clear_secrets.push(key);
        item.config[key] = '';
    }
};

const save = item => {
    busy.value = item.code;
    router.put('/admin/integrations/' + encodeURIComponent(item.code), {
        is_active: Boolean(item.is_active),
        environment: item.environment,
        config: item.config,
        clear_secrets: item.clear_secrets,
    }, {
        preserveScroll: true,
        onFinish: () => { busy.value = ''; },
    });
};

const csrf = () => decodeURIComponent(
    document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11) || '',
);

const testConnection = async item => {
    busy.value = item.code;
    try {
        const response = await fetch('/admin/integrations/' + encodeURIComponent(item.code) + '/test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
        });
        const payload = await response.json().catch(() => ({ status: 'DOWN', message: 'Tes koneksi gagal.' }));
        result[item.code] = response.ok ? payload : { status: 'DOWN', message: payload.message || 'Tes koneksi ditolak.' };
        if (response.ok) router.reload({ preserveScroll: true, only: ['integrations', 'summary'] });
    } finally {
        busy.value = '';
    }
};

const copy = async (key, value) => {
    if (!value) return;
    await copyText(value);
    copied.value = key;
    window.setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1800);
};

const addresses = computed(() => [
    { key: 'digiflazz', label: 'Pemberitahuan Digiflazz', url: props.callbackUrls.digiflazz },
    { key: 'midtrans', label: 'Pemberitahuan Midtrans', url: props.callbackUrls.midtrans },
    { key: 'doku', label: 'Pemberitahuan DOKU', url: props.callbackUrls.doku },
    { key: 'google', label: 'Callback Google', url: props.callbackUrls.google },
].filter(address => address.url));

const testEmail = reactive({ open: false, recipient: '', sending: false, message: '' });
const sendTestEmail = async () => {
    testEmail.sending = true;
    testEmail.message = '';
    try {
        const response = await fetch('/admin/integrations/resend/send-test-email', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() },
            body: JSON.stringify({ recipient: testEmail.recipient }),
        });
        const payload = await response.json().catch(() => ({}));
        testEmail.message = response.ok ? (payload.message || 'Email uji terkirim.') : (payload.message || 'Email uji gagal dikirim.');
    } finally {
        testEmail.sending = false;
    }
};
</script>

<template>
    <Head title="Integrasi" />
    <AdminLayout>
        <header class="adm-head">
            <div>
                <h1>Integrasi</h1>
                <p>Data akses layanan luar yang dipakai toko. Kredensial disimpan terenkripsi dan hanya bisa diubah Super Admin.</p>
            </div>
            <div class="adm-head-actions">
                <button type="button" class="adm-btn" @click="router.reload({ preserveScroll: true })">Muat ulang</button>
            </div>
        </header>

        <section class="adm-stats" aria-label="Ringkasan integrasi">
            <div class="adm-stat"><span class="lb">Total integrasi</span><strong class="vl">{{ num(summary.total) }}</strong></div>
            <div class="adm-stat"><span class="lb">Aktif</span><strong class="vl">{{ num(summary.active) }}</strong></div>
            <div class="adm-stat"><span class="lb">Sudah terhubung</span><strong class="vl">{{ num(summary.healthy) }}</strong></div>
            <div class="adm-stat"><span class="lb">Butuh tindakan</span><strong class="vl" :style="{ color: Number(summary.attention) ? 'var(--a-danger)' : 'inherit' }">{{ num(summary.attention) }}</strong></div>
            <div class="adm-stat"><span class="lb">Belum dipastikan</span><strong class="vl">{{ num(summary.awaiting_verification) }}</strong></div>
            <div class="adm-stat"><span class="lb">Data akses lengkap</span><strong class="vl">{{ num(summary.configuration_complete) }}<span style="font-size:13px;font-weight:400"> / {{ num(summary.total) }}</span></strong></div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>Mulai jualan top up</h2>
                    <p>Urutan pengisian: Digiflazz untuk produk, Validasi Akun untuk cek nama panggilan, lalu Midtrans atau DOKU untuk pembayaran.</p>
                </div>
            </div>
            <div class="adm-card-body">
                <div v-if="addresses.length">
                    <strong>Alamat pemberitahuan</strong>
                    <p class="adm-note">Tempel alamat ini di pengaturan layanan luar. Alamat muncul otomatis mengikuti integrasi yang memilikinya.</p>
                    <div v-for="address in addresses" :key="address.key" class="adm-copyrow">
                        <span>{{ address.label }}</span>
                        <code class="adm-code">{{ address.url }}</code>
                        <button type="button" class="adm-btn sm" @click="copy(address.key, address.url)">
                            {{ copied === address.key ? 'Tersalin' : 'Salin' }}
                        </button>
                    </div>
                </div>
                <p v-else class="adm-note">Belum ada integrasi yang memakai alamat pemberitahuan.</p>
            </div>
        </section>

        <div class="adm-tabs" role="tablist" aria-label="Daftar integrasi">
            <button v-for="item in items" :key="item.code" type="button" role="tab"
                :aria-selected="tab === item.code" @click="tab = item.code">
                {{ item.name }}<span class="adm-dot" :class="readinessTone(item)" aria-hidden="true" />
            </button>
        </div>

        <section v-if="current" class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2>{{ current.name }}</h2>
                    <p>{{ current.description || 'Integrasi layanan luar.' }}</p>
                </div>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <span class="adm-tag" :class="current.is_active ? 'ok' : 'bad'">{{ current.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    <span v-if="current.environment_label" class="adm-tag info">{{ current.environment_label }}</span>
                    <span class="adm-tag" :class="readinessTone(current)">{{ connectionLabel(current.connection?.status) }}</span>
                    <button type="button" class="adm-switch" role="switch" :aria-checked="current.is_active"
                        :aria-label="current.is_active ? 'Nonaktifkan integrasi' : 'Aktifkan integrasi'"
                        @click="current.is_active = !current.is_active" />
                </div>
            </div>
            <div class="adm-card-body">
                <p v-if="current.note" class="adm-alert">{{ current.note }}</p>

                <div class="adm-grid">
                    <div class="adm-block">
                        <strong>Data akses</strong>
                        <p class="adm-note">{{ current.credential_status }} · {{ num(current.configured_required) }} dari {{ num(current.required_total) }} isian wajib terisi</p>
                        <span class="adm-tag" :class="connectionTone(current.credential?.status)">{{ current.credential?.label }}</span>
                    </div>
                    <div class="adm-block">
                        <strong>Koneksi</strong>
                        <p class="adm-note">{{ current.connection?.message || 'Belum pernah menjalankan Tes Koneksi.' }}</p>
                        <p class="adm-note">{{ wibDateTime(current.health?.tested_at) || 'Belum pernah dites' }}</p>
                    </div>
                    <div class="adm-block">
                        <strong>Pemberitahuan layanan</strong>
                        <p class="adm-note">{{ current.callback?.message || 'Integrasi ini tidak memakai alamat pemberitahuan.' }}</p>
                        <span v-if="current.callback" class="adm-tag" :class="callbackTone(current.callback.status)">
                            {{ current.callback.status === 'READY' ? 'Siap' : current.callback.status === 'NOT_READY' ? 'Belum siap' : 'Tidak perlu' }}
                        </span>
                    </div>
                </div>

                <div class="adm-grid" style="margin-top:10px">
                    <label v-for="field in current.fields" :key="field.key" class="adm-field" :class="{ full: field.type === 'csv' }">
                        <span>{{ field.label }}<template v-if="field.required"> *</template></span>
                        <input v-if="field.type !== 'boolean'" v-model="current.config[field.key]" class="adm-input"
                            :type="field.secret ? 'password' : 'text'" :disabled="field.secret && current.clear_secrets.includes(field.key)"
                            :placeholder="field.secret && field.configured ? 'Tersimpan — kosongkan bila tidak diubah' : (field.placeholder || '')">
                        <label v-else class="adm-switch-row">
                            <button type="button" class="adm-switch" role="switch" :aria-checked="!!current.config[field.key]"
                                @click="current.config[field.key] = !current.config[field.key]" />
                            {{ current.config[field.key] ? 'Dinyalakan' : 'Dimatikan' }}
                        </label>
                        <span v-if="field.help" class="hint">{{ field.help }}</span>
                        <span v-if="field.secret && field.configured" class="adm-tag" :class="current.clear_secrets.includes(field.key) ? 'bad' : 'ok'" style="align-self:flex-start">
                            {{ current.clear_secrets.includes(field.key) ? 'Akan dihapus' : 'Tersimpan' }}
                        </span>
                        <button v-if="field.secret && field.configured" type="button" class="adm-btn sm link" style="align-self:flex-start"
                            @click="toggleClearSecret(current, field.key)">
                            {{ current.clear_secrets.includes(field.key) ? 'Batalkan hapus' : 'Hapus data akses' }}
                        </button>
                    </label>
                </div>

                <p v-if="current.code === 'kokinpay' && current.environment_live" class="adm-alert bad">
                    Mode produksi aktif. Validasi nama panggilan akan memakai kuota layanan sungguhan.
                </p>

                <div v-if="current.code === 'resend'" class="adm-block full" style="margin-top:10px">
                    <strong>Kirim email uji</strong>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                        <input v-model="testEmail.recipient" class="adm-input" style="flex:1 1 240px" placeholder="alamat@contoh.com">
                        <button type="button" class="adm-btn" :disabled="testEmail.sending || !testEmail.recipient" @click="sendTestEmail">Kirim email uji</button>
                    </div>
                    <p v-if="testEmail.message" class="adm-note">{{ testEmail.message }}</p>
                </div>

                <div v-if="result[current.code]" class="adm-alert" :class="result[current.code].status === 'DOWN' ? 'bad' : 'ok'" style="margin-top:10px">
                    <strong>{{ connectionLabel(result[current.code].status) }}</strong>
                    <p>{{ result[current.code].message }}</p>
                </div>

                <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
                    <button type="button" class="adm-btn" :disabled="busy === current.code" @click="testConnection(current)">Tes koneksi</button>
                    <button type="button" class="adm-btn primary" :disabled="busy === current.code" @click="save(current)">Simpan integrasi</button>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>
