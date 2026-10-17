<script setup>
import { computed, ref, watch } from 'vue';
import { phoneCountries } from '../Data/phoneCountries';

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    placeholder: { type: String, default: '081234567890' },
});
const emit = defineEmits(['update:modelValue']);

const country = ref(phoneCountries[0]);
const local = ref('');
const open = ref(false);
const search = ref('');
let lastEmitted = null;

const flag = (item) => String.fromCodePoint(...item.iso.toUpperCase().split('').map((char) => 127397 + char.charCodeAt(0)));
const filtered = computed(() => phoneCountries.filter((item) => `${item.name} +${item.dial}`.toLowerCase().includes(search.value.trim().toLowerCase())));

/** Nomor yang dikirim: kode negara terpilih + nomor lokal tanpa nol depan. */
const composed = computed(() => {
    let digits = local.value.replace(/\D/g, '');
    if (digits.length > country.value.dial.length + 6 && digits.startsWith(country.value.dial)) {
        digits = digits.slice(country.value.dial.length);
    }
    digits = digits.replace(/^0+/, '');
    return digits ? `+${country.value.dial}${digits}` : '';
});

/** Pecah nomor tersimpan menjadi negara + nomor lokal. */
function hydrate(value) {
    const text = String(value || '');
    const digits = text.replace(/\D/g, '');
    if (!digits) {
        country.value = phoneCountries[0];
        local.value = '';
        return;
    }

    if (text.trim().startsWith('+')) {
        const match = [...phoneCountries]
            .sort((a, b) => b.dial.length - a.dial.length)
            .find((item) => digits.startsWith(item.dial));
        country.value = match || phoneCountries[0];
        local.value = match ? digits.slice(match.dial.length) : digits;
        emitValue();
        return;
    }

    const indonesia = phoneCountries[0];
    if ((digits.startsWith('62') || digits.startsWith('0')) && digits.length > indonesia.dial.length + 6) {
        country.value = indonesia;
        local.value = digits.startsWith('62') ? digits.slice(2) : digits.slice(1);
        emitValue();
        return;
    }

    country.value = indonesia;
    local.value = digits;
    emitValue();
}

function emitValue() {
    lastEmitted = composed.value;
    emit('update:modelValue', lastEmitted);
}

function onInput(event) {
    local.value = String(event.target.value).replace(/\D/g, '').slice(0, 15);
    emitValue();
}

function select(item) {
    country.value = item;
    open.value = false;
    search.value = '';
    emitValue();
}

function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
}

watch(() => props.modelValue, (value) => {
    if (value !== lastEmitted) hydrate(value);
}, { immediate: true });
</script>

<template>
    <div class="lf-country-picker flex flex-wrap items-start gap-2">
        <button type="button" class="flex h-9 shrink-0 items-center gap-1 rounded border border-white/10 bg-white/5 px-2 text-xs font-semibold text-slate-100 hover:border-white/25 disabled:opacity-50" :disabled="disabled" :aria-expanded="open" aria-label="Pilih kode negara" @click="toggle">
            <span aria-hidden="true">{{ flag(country) }}</span>
            <span>+{{ country.dial }}</span>
            <span aria-hidden="true" class="text-[9px] text-slate-400">⌄</span>
        </button>
        <input :value="local" :disabled="disabled" :required="required" :aria-invalid="invalid" type="tel" inputmode="tel" :placeholder="placeholder" class="min-w-0 flex-1" @input="onInput" />
        <div v-if="open" class="order-last w-full rounded-lg border border-white/10 bg-slate-900/95 p-2 shadow-2xl">
            <input v-model="search" type="search" placeholder="Cari negara..." aria-label="Cari negara" class="h-9 w-full" />
            <div class="mt-1 max-h-52 overflow-y-auto">
                <button v-for="item in filtered" :key="item.iso" type="button" class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-slate-200 hover:bg-white/10" @click="select(item)">
                    <span aria-hidden="true">{{ flag(item) }}</span>
                    <span>{{ item.name }}</span>
                    <span class="ml-auto text-slate-400">+{{ item.dial }}</span>
                </button>
                <p v-if="!filtered.length" class="p-2 text-xs text-slate-400">Negara tidak ditemukan</p>
            </div>
        </div>
    </div>
</template>
