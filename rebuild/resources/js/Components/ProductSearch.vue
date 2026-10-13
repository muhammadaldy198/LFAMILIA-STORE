<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'home' },
    placeholder: { type: String, default: 'Cari game, voucher, pulsa, atau PLN' },
    initialQuery: { type: String, default: '' },
    autofocus: { type: Boolean, default: false },
});
const query = ref(props.initialQuery || '');
const results = ref([]);
const input = ref(null);
const focused = ref(false);
const loading = ref(false);
const ready = ref(false);
const activeIndex = ref(-1);
const menuId = 'lf-search-results-' + Math.random().toString(36).slice(2, 10);
const open = computed(() => focused.value && query.value.trim().length > 0);
let timer = null;
let pending = null;
let serial = 0;

function cancelPending() {
    if (timer !== null) clearTimeout(timer);
    timer = null;
    pending?.abort();
    pending = null;
}
async function lookup(value) {
    const requestId = ++serial;
    cancelPending();
    const term = value.trim();
    if (!term) {
        results.value = [];
        loading.value = false;
        ready.value = false;
        return [];
    }
    loading.value = true;
    ready.value = false;
    const controller = new AbortController();
    pending = controller;
    try {
        const response = await fetch('/catalog/search?q=' + encodeURIComponent(term), {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('Produk tidak dapat dicari');
        const data = await response.json();
        if (requestId !== serial || term !== query.value.trim()) return [];
        results.value = Array.isArray(data.products) ? data.products : [];
        activeIndex.value = -1;
        ready.value = true;
        return results.value;
    } catch (error) {
        if (error.name !== 'AbortError' && requestId === serial) {
            results.value = [];
            ready.value = false;
        }
        return [];
    } finally {
        if (requestId === serial) loading.value = false;
        if (pending === controller) pending = null;
    }
}

watch(query, value => {
    ++serial;
    cancelPending();
    results.value = [];
    ready.value = false;
    activeIndex.value = -1;
    if (!value.trim()) {
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = setTimeout(() => { timer = null; void lookup(value); }, 250);
});
watch(() => props.initialQuery, value => { if (value !== query.value) query.value = value || ''; });

function move(step) {
    if (!results.value.length) return;
    activeIndex.value = activeIndex.value < 0
        ? (step < 0 ? results.value.length - 1 : 0)
        : (activeIndex.value + step + results.value.length) % results.value.length;
}
async function submit() {
    const text = query.value.trim();
    if (!text) return;
    const choices = ready.value ? results.value : await lookup(text);
    const product = choices[activeIndex.value >= 0 ? activeIndex.value : 0];
    if (product?.slug) {
        focused.value = false;
        router.visit('/catalog/' + encodeURIComponent(product.slug));
    }
}
function focusSearch() {
    focused.value = true;
    // A bookmarked /?q=... value was populated before the query watcher attached.
    if (query.value.trim() && !ready.value && !loading.value) void lookup(query.value);
}
function dismissSuggestions() {
    // Escape also works when keyboard focus has moved into a suggestion link.
    input.value?.focus();
    focused.value = false;
}
function blur(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) focused.value = false;
}
onMounted(async () => {
    if (props.autofocus) { await nextTick(); input.value?.focus(); }
});
onUnmounted(() => { ++serial; cancelPending(); });
</script>

<template>
    <div class="lf-product-search" :class="'lf-product-search-' + variant" @focusout="blur" @keydown.esc="dismissSuggestions">
        <form class="lf-product-search-form" role="search" @submit.prevent="submit">
            <svg class="lf-product-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
            <input
                ref="input"
                v-model="query"
                class="lf-product-search-input"
                type="search"
                name="q"
                maxlength="80"
                autocomplete="off"
                spellcheck="false"
                :placeholder="placeholder"
                :aria-label="placeholder"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="open"
                :aria-controls="menuId"
                :aria-activedescendant="open && activeIndex >= 0 ? menuId + '-option-' + activeIndex : undefined"
                @focus="focusSearch"
                @input="focused=true"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
            >
        </form>
        <div v-if="open" :id="menuId" class="lf-product-search-results" role="listbox" aria-label="Hasil pencarian produk">
            <p v-if="loading" class="lf-product-search-status">Mencari produk…</p>
            <template v-else-if="results.length">
                <Link
                    v-for="(product, index) in results"
                    :key="product.slug"
                    :id="menuId + '-option-' + index"
                    class="lf-product-search-result"
                    :class="{ 'is-active': activeIndex === index }"
                    :href="'/catalog/' + encodeURIComponent(product.slug)"
                    role="option"
                    :aria-selected="activeIndex===index"
                    @pointerdown.prevent
                    @click="focused=false"
                >
                    <img v-if="product.image_url" :src="product.image_url" :alt="product.name">
                    <span v-else class="lf-product-search-fallback" aria-hidden="true">LF</span>
                    <span class="lf-product-search-result-text"><strong>{{ product.name }}</strong><small>{{ product.category_name }}</small></span>
                    <span aria-hidden="true">›</span>
                </Link>
            </template>
            <p v-else-if="ready" class="lf-product-search-status">Produk tidak ditemukan.</p>
            <p v-else class="lf-product-search-status">Pencarian belum tersedia. Coba lagi.</p>
        </div>
    </div>
</template>

<style scoped>
.lf-product-search { position:relative; min-width:0; }
.lf-product-search-form { display:flex; align-items:center; position:relative; width:100%; height:100%; margin:0; }
.lf-product-search-icon { position:absolute; left:13px; top:50%; transform:translateY(-50%); height:18px; width:18px; flex:none; stroke:var(--lf-color-muted); stroke-width:2; fill:none; pointer-events:none; z-index:1; }
.lf-product-search-input { width:100%; min-width:0; height:40px; min-height:40px; padding:0 14px 0 44px; border:1px solid var(--lf-color-border); border-radius:10px; background:var(--lf-color-background-alt); color:var(--lf-color-text); outline:none; box-sizing:border-box; }
.lf-product-search-input::placeholder { color:var(--lf-color-muted); opacity:1; text-overflow:ellipsis; }
.lf-product-search-input:focus { border-color:var(--lf-color-primary); }
.lf-product-search-results { position:absolute; top:calc(100% + 7px); inset-inline:0; z-index:150; max-height:min(360px,65vh); overflow-y:auto; border:1px solid var(--lf-color-border); border-radius:12px; background:var(--lf-color-surface); box-shadow:0 16px 45px rgba(0,0,0,.48); }
.lf-product-search-result { display:flex; align-items:center; gap:10px; padding:9px 12px; text-decoration:none; color:var(--lf-color-text); border-bottom:1px solid rgba(255,255,255,.06); }
.lf-product-search-result:last-child { border-bottom:0; }
.lf-product-search-result:hover,.lf-product-search-result.is-active { background:color-mix(in srgb,var(--lf-color-primary) 15%,transparent); }
.lf-product-search-result img,.lf-product-search-fallback { width:40px; height:40px; flex:0 0 40px; border-radius:9px; object-fit:cover; }
.lf-product-search-fallback { display:grid; place-items:center; background:var(--lf-color-card); color:var(--lf-color-primary); font-weight:800; }
.lf-product-search-result-text { display:grid; gap:2px; flex:1; min-width:0; }
.lf-product-search-result-text strong { font-size:13px; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.lf-product-search-result-text small { font-size:11px; color:var(--lf-color-muted); }
.lf-product-search-status { padding:14px; margin:0; font-size:12px; color:var(--lf-color-muted); }
</style>
