<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    siteKey: { type: String, required: true },
    action: { type: String, required: true },
});
const emit = defineEmits(['token']);
const target = ref(null);
let widgetId = null;

function renderWidget() {
    if (!target.value || !window.turnstile || widgetId !== null) return;
    widgetId = window.turnstile.render(target.value, {
        sitekey: props.siteKey,
        action: props.action,
        callback: (token) => emit('token', token),
        'expired-callback': () => emit('token', ''),
        'error-callback': () => emit('token', ''),
    });
}

function ensureScript() {
    if (window.turnstile) {
        renderWidget();
        return;
    }

    const existing = document.querySelector('script[data-lfamilia-turnstile]');
    if (existing) {
        existing.addEventListener('load', renderWidget, { once: true });
        return;
    }

    const script = document.createElement('script');
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    script.async = true;
    script.defer = true;
    script.dataset.lfamiliaTurnstile = '1';
    script.addEventListener('load', renderWidget, { once: true });
    document.head.appendChild(script);
}

function reset() {
    emit('token', '');
    if (window.turnstile && widgetId !== null) {
        window.turnstile.reset(widgetId);
    }
}

defineExpose({ reset });

onMounted(() => nextTick(ensureScript));
onBeforeUnmount(() => {
    if (window.turnstile && widgetId !== null) {
        window.turnstile.remove(widgetId);
    }
});
</script>

<template>
    <div ref="target" class="min-h-[65px]"></div>
</template>
