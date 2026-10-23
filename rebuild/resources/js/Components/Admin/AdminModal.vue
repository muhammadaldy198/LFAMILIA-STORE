<script setup>
import { computed } from 'vue';
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';

defineProps({
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});
// Halaman Antrean & Detail Pesanan memakai string sebagai penanda jenis pop-up,
// jadi nilai apa pun selain kosong dianggap "buka".
const open = defineModel('open', { default: false });
const isOpen = computed({
    get: () => !!open.value,
    set: (value) => { if (!value) open.value = false; },
});
</script>

<template>
    <DialogRoot v-model:open="isOpen">
        <DialogPortal>
            <DialogOverlay class="adm-overlay" />
            <DialogContent class="adm-dialog" :class="{ wide }">
                <div class="adm-dialog-head">
                    <div>
                        <DialogTitle as="h2">{{ title }}</DialogTitle>
                        <DialogDescription v-if="description" as="p">{{ description }}</DialogDescription>
                    </div>
                    <button type="button" class="adm-btn sm" aria-label="Tutup" @click="open = false">Tutup</button>
                </div>
                <div class="adm-dialog-body"><slot /></div>
                <div v-if="$slots.footer" class="adm-dialog-foot"><slot name="footer" /></div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
