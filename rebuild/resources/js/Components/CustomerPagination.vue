<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: { type: Array, default: () => [] },
    label: { type: String, required: true },
});
</script>

<template>
    <nav v-if="links.length" :aria-label="label" class="flex flex-wrap gap-2">
        <template v-for="(link, index) in links" :key="index">
            <span
                v-if="!link.url || link.active"
                class="rounded-md px-3 py-2 text-sm"
                :class="link.active ? 'bg-cyan-400 text-slate-950' : 'bg-slate-800 text-slate-400 opacity-50'"
                :aria-current="link.active ? 'page' : undefined"
                :aria-disabled="!link.url ? 'true' : undefined"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                class="rounded-md bg-slate-800 px-3 py-2 text-sm text-slate-200"
                v-html="link.label"
            />
        </template>
    </nav>
</template>
