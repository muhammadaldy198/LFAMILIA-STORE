<script setup>
import { SwitchRoot, SwitchThumb } from 'reka-ui';
defineProps({ modelValue: Boolean, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
</script>
<template>
    <SwitchRoot :model-value="modelValue" :disabled="disabled" class="lf-admin-switch" @update:model-value="emit('update:modelValue', $event)">
        <SwitchThumb class="lf-admin-switch-thumb" />
    </SwitchRoot>
</template>
