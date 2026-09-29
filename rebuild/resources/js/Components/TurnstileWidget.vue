<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    siteKey: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const container = ref(null);
let widgetId = null;

const render = () => {
    if (!props.siteKey || !container.value || !window.turnstile) return;
    widgetId = window.turnstile.render(container.value, {
        sitekey: props.siteKey,
        callback: (token) => emit('update:modelValue', token),
        'expired-callback': () => emit('update:modelValue', ''),
        'error-callback': () => emit('update:modelValue', ''),
    });
};

onMounted(() => {
    if (!props.siteKey) return;
    const existing = document.querySelector('script[data-lfamilia-turnstile]');
    if (existing) {
        if (window.turnstile) render();
        else existing.addEventListener('load', render, { once: true });
        return;
    }

    const script = document.createElement('script');
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    script.async = true;
    script.defer = true;
    script.dataset.lfamiliaTurnstile = '1';
    script.addEventListener('load', render, { once: true });
    document.head.appendChild(script);
});

onBeforeUnmount(() => {
    if (widgetId !== null && window.turnstile) window.turnstile.remove(widgetId);
});
</script>

<template>
    <div v-if="siteKey" class="space-y-2">
        <div ref="container"></div>
        <p class="text-xs text-slate-400">Verifikasi keamanan Cloudflare.</p>
    </div>
</template>
