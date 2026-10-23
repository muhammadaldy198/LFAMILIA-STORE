<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AdminModal from './Admin/AdminModal.vue';
import { useSensitivePrompt, setSensitiveReady } from '../lib/sensitivePrompt';

// Konfirmasi kata sandi di tempat, supaya tombol sensitif tidak melempar
// admin ke halaman lain dan kehilangan isian yang sudah diketik.
const page = usePage();
const { state, finish } = useSensitivePrompt();
setSensitiveReady(Boolean(page.props.adminPanel?.sensitive_ready));
watch(() => page.props.adminPanel?.sensitive_ready, (value) => setSensitiveReady(value));
const password = ref('');
const error = ref('');
const busy = ref(false);
const open = computed(() => Boolean(state.value));
const reason = computed(() => state.value?.reason || 'Tindakan ini memerlukan konfirmasi kata sandi.');

watch(open, (value) => {
    if (value) { password.value = ''; error.value = ''; }
});

async function submit() {
    if (busy.value || !password.value) return;
    busy.value = true;
    error.value = '';
    try {
        // Kirim dua bentuk token: meta (X-CSRF-TOKEN) dan cookie (X-XSRF-TOKEN),
        // supaya tetap lolos walau sesi sempat diperbarui setelah login.
        const xsrf = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
        const response = await fetch('/admin/password/confirm', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.split('=')[1]) : '',
            },
            body: JSON.stringify({ password: password.value }),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            error.value = data?.errors?.password?.[0] || data?.message || 'Kata sandi tidak cocok.';
            return;
        }
        // Segarkan status sesi agar permintaan berikutnya tidak bertanya lagi.
        page.props.adminPanel && (page.props.adminPanel.sensitive_ready = true);
        finish(true);
    } catch {
        error.value = 'Tidak dapat menghubungi server. Coba lagi.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <AdminModal :open="open" title="Konfirmasi kata sandi" :description="reason" @update:open="(value) => { if (!value) finish(false); }">
        <form id="sensitive-confirm" class="adm-grid" @submit.prevent="submit">
            <label class="adm-field full">
                <span>Kata sandi admin</span>
                <input v-model="password" type="password" autocomplete="current-password" required class="adm-input">
            </label>
            <p v-if="error" role="alert" class="err full">{{ error }}</p>
        </form>
        <template #footer>
            <button type="button" class="adm-btn" :disabled="busy" @click="finish(false)">Batal</button>
            <button type="submit" form="sensitive-confirm" class="adm-btn primary" :disabled="busy || !password">{{ busy ? 'Memeriksa…' : 'Lanjutkan' }}</button>
        </template>
    </AdminModal>
</template>
