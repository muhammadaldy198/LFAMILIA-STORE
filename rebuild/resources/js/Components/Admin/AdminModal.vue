<script setup>
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';

defineProps({
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});
const open = defineModel('open', { type: Boolean, default: false });
</script>

<template>
    <DialogRoot v-model:open="open">
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
