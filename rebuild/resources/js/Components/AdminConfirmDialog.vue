<script setup>
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { Button } from './ui/button';

// Konfirmasi bertema untuk tindakan berbahaya. Menggantikan window.confirm
// yang tampil abu-abu bawaan browser di panel gelap.
const props = defineProps({
    open: Boolean,
    title: { type: String, default: 'Konfirmasi tindakan' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Lanjutkan' },
    cancelLabel: { type: String, default: 'Batal' },
    destructive: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'confirm']);
</script>

<template>
    <DialogRoot :open="props.open" @update:open="value => emit('update:open', value)">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-[95] bg-black/70" />
            <DialogContent class="fixed left-1/2 top-1/2 z-[96] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-md border border-border bg-card p-5 shadow-xl">
                <DialogTitle class="text-base font-semibold">{{ props.title }}</DialogTitle>
                <DialogDescription class="mt-2 text-sm text-muted-foreground">{{ props.message }}</DialogDescription>
                <div class="mt-5 flex justify-end gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">{{ props.cancelLabel }}</Button>
                    </DialogClose>
                    <Button type="button" :variant="props.destructive ? 'destructive' : 'default'" @click="emit('confirm')">{{ props.confirmLabel }}</Button>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
