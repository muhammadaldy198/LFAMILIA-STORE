<script setup>
import { computed } from 'vue';
import { Gamepad2, Grid3X3, Play, Smartphone, Ticket, Zap } from '@lucide/vue';

const props = defineProps({ name: { type: String, default: 'grid' } });
const icons = { gamepad: Gamepad2, ticket: Ticket, play: Play, smartphone: Smartphone, zap: Zap, grid: Grid3X3 };
const icon = computed(() => icons[props.name] || Grid3X3);
</script>

<template><component :is="icon" aria-hidden="true" /></template>
