<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

// Combobox multi-pilih: tertutup, diklik membuka daftar bercari, label di dalam kotak.
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Pilih…' },
    searchPlaceholder: { type: String, default: 'Cari…' },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const query = ref('');
const root = ref(null);

const selected = computed(() => new Set((props.modelValue || []).map(String)));
const chosen = computed(() => props.options.filter(option => selected.value.has(String(option.value))));
const filtered = computed(() => {
    const term = query.value.trim().toLowerCase();
    if (!term) return props.options;
    return props.options.filter(option => String(option.label).toLowerCase().includes(term));
});

const toggle = (option) => {
    const id = String(option.value);
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    emit('update:modelValue', props.options.filter(item => next.has(String(item.value))).map(item => item.value));
};

const clear = () => emit('update:modelValue', []);

const onOutside = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
};
onMounted(() => document.addEventListener('mousedown', onOutside));
onUnmounted(() => document.removeEventListener('mousedown', onOutside));
</script>

<template>
    <div ref="root" style="position:relative">
        <button type="button" class="adm-input" style="display:flex;align-items:center;gap:6px;text-align:left;cursor:pointer" :aria-expanded="open" @click="open = !open">
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--a-muted)">
                <template v-if="!chosen.length">{{ placeholder }}</template>
                <template v-else>{{ chosen.length }} dipilih</template>
            </span>
            <span aria-hidden="true">▾</span>
        </button>
        <div v-if="chosen.length" style="display:flex;flex-wrap:wrap;gap:3px;margin-top:4px">
            <span v-for="option in chosen" :key="option.value" class="adm-tag plain">
                {{ option.label }}
                <button type="button" aria-label="Hapus pilihan" style="border:0;background:none;cursor:pointer;color:inherit;padding:0 0 0 2px" @click="toggle(option)">×</button>
            </span>
            <button type="button" class="adm-btn link" @click="clear">Kosongkan</button>
        </div>
        <div v-if="open" style="position:absolute;z-index:60;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--a-line);border-radius:8px;padding:6px;max-height:250px;overflow:auto">
            <input v-model="query" class="adm-input" :placeholder="searchPlaceholder" style="margin-bottom:5px">
            <label v-for="option in filtered" :key="option.value" class="adm-switch-row" style="padding:4px 5px;border-radius:5px;cursor:pointer">
                <input type="checkbox" :checked="selected.has(String(option.value))" @change="toggle(option)">
                <span>{{ option.label }}</span>
            </label>
            <p v-if="!filtered.length" class="adm-note" style="padding:6px">Tidak ada pilihan yang cocok.</p>
        </div>
    </div>
</template>
