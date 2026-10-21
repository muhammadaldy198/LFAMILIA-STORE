import { ref } from 'vue';

// Antrean permintaan konfirmasi kata sandi untuk tindakan sensitif admin.
// Komponen AdminSensitivePrompt yang menampilkannya (dipasang di AdminShell).
const state = ref(null);
const ready = ref(false);

export function setSensitiveReady(value) {
    ready.value = Boolean(value);
}

export function confirmSensitive(reason) {
    if (ready.value) return Promise.resolve(true);

    return new Promise((resolve) => {
        state.value = { reason, resolve };
    });
}

// Jalankan aksi hanya setelah kata sandi dikonfirmasi.
export async function withSensitive(reason, action) {
    if (!(await confirmSensitive(reason))) return;
    await action();
}

export function useSensitivePrompt() {
    const finish = (ok) => {
        if (ok) ready.value = true;
        state.value?.resolve(ok);
        state.value = null;
    };

    return { state, finish };
}
