<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from './ui/sheet';
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
    <Sheet :open="open" @update:open="(value) => { if (!value) finish(false); }">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-sm">
            <SheetHeader><SheetTitle>Konfirmasi kata sandi</SheetTitle><SheetDescription>{{ reason }}</SheetDescription></SheetHeader>
            <form class="mt-4 space-y-3" @submit.prevent="submit">
                <label class="block space-y-1"><span class="text-sm font-medium">Kata sandi admin</span><Input v-model="password" type="password" autocomplete="current-password" required /></label>
                <p v-if="error" role="alert" class="text-sm text-destructive">{{ error }}</p>
                <div class="flex justify-end gap-2"><Button type="button" variant="outline" :disabled="busy" @click="finish(false)">Batal</Button><Button type="submit" :disabled="busy || !password">{{ busy ? 'Memeriksa…' : 'Lanjutkan' }}</Button></div>
            </form>
        </SheetContent>
    </Sheet>
</template>
