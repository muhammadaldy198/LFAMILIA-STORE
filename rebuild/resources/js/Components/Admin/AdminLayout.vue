<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminSensitivePrompt from '../AdminSensitivePrompt.vue';

const page = usePage();
const panel = computed(() => page.props.adminPanel || {});
const base = computed(() => panel.value.base_path || '/admin');
const admin = computed(() => panel.value.admin || {});
const initial = computed(() => String(admin.value.name || 'A').trim().slice(0, 1).toUpperCase());
const unread = computed(() => Number(panel.value.unread_notifications || 0));

// Label, urutan, kelompok dan izin menu berasal dari server, bukan daftar duplikat di Vue.
const allowed = computed(() => new Set((panel.value.menu || []).map(item => item.href)));
const groups = computed(() => {
    const sections = [];
    for (const item of panel.value.menu || []) {
        const group = item.group ?? null;
        let section = sections.find(entry => entry.group === group);
        if (!section) {
            section = { group, items: [] };
            sections.push(section);
        }
        section.items.push({ label: item.label, href: item.href });
    }
    return sections;
});

const isActive = href => {
    const path = page.url.split('?')[0];
    if (href === '/admin/panel') return path === href;
    if (href === '/admin/catalog') return path === '/admin/catalog';
    return path === href || path.startsWith(href + '/');
};

const drawer = ref(false);
watch(() => page.url, () => { drawer.value = false; accountOpen.value = false; noteOpen.value = false; });

const accountOpen = ref(false);
const noteOpen = ref(false);

const query = ref('');
const results = ref([]);
const searching = ref(false);
const searchError = ref('');
let searchTimer;
let searchSeq = 0;

const search = async () => {
    const term = query.value.trim();
    if (term.length < 2) { results.value = []; return; }
    const seq = ++searchSeq;
    searching.value = true;
    searchError.value = '';
    try {
        const response = await fetch(`${base.value}/search?q=${encodeURIComponent(term)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Pencarian belum dapat dimuat.');
        const data = await response.json();
        if (seq === searchSeq) results.value = data.results || [];
    } catch (error) {
        if (seq === searchSeq) searchError.value = error.message;
    } finally {
        if (seq === searchSeq) searching.value = false;
    }
};

watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(search, 220);
});

const onKey = event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        document.querySelector('.adm-search input')?.focus();
    }
    if (event.key === 'Escape') { drawer.value = false; accountOpen.value = false; noteOpen.value = false; }
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => { window.removeEventListener('keydown', onKey); clearTimeout(searchTimer); });

const logout = () => router.post(`${base.value}/logout`);
</script>

<template>
    <div class="adm">
        <button v-if="drawer" type="button" class="adm-scrim" aria-label="Tutup menu" @click="drawer = false" />

        <aside class="adm-side" :class="{ open: drawer }" aria-label="Navigasi admin">
            <Link :href="base + '/panel'" class="adm-brand">
                <span class="adm-mark">LF</span>
                <span>
                    <b>LFAMILIA ADMIN</b>
                    <small>{{ $page.props.storefront?.tagline || 'Top Up Game Solution' }}</small>
                </span>
            </Link>
            <nav class="adm-nav">
                <template v-for="section in groups" :key="section.group || 'utama'">
                    <span v-if="section.group" class="adm-nav-group">{{ section.group }}</span>
                    <Link v-for="item in section.items" :key="item.href" :href="item.href"
                        :aria-current="isActive(item.href) ? 'page' : undefined">
                        {{ item.label }}
                    </Link>
                </template>
            </nav>
            <div class="adm-side-foot">© {{ new Date().getFullYear() }} {{ $page.props.storefront?.storeName || 'LFAMILIA' }}</div>
        </aside>

        <div class="adm-main">
            <header class="adm-top">
                <button type="button" class="adm-burger" aria-label="Buka menu" @click="drawer = true">☰</button>
                <form class="adm-search" @submit.prevent="search">
                    <input v-model="query" type="search" placeholder="Cari di Admin…" aria-label="Pencarian admin">
                    <kbd>Ctrl K</kbd>
                    <div v-if="results.length || searchError" class="adm-search-results">
                        <p v-if="searchError" class="adm-note" style="padding:8px 11px">{{ searchError }}</p>
                        <Link v-for="(result, i) in results" :key="i" :href="result.href">
                            <strong>{{ result.title }}</strong>
                            <small>{{ result.detail }}</small>
                        </Link>
                    </div>
                </form>
                <div class="adm-top-actions">
                    <div v-if="panel.can_notifications" style="position:relative">
                        <button type="button" class="adm-icon-btn" aria-label="Notifikasi" @click="noteOpen = !noteOpen; accountOpen = false">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                            <span v-if="unread" class="adm-dot">{{ unread }}</span>
                        </button>
                        <div v-if="noteOpen" class="adm-menu-pop">
                            <div class="who">
                                <strong>Notifikasi</strong>
                                <span>{{ unread }} notifikasi belum dibaca.</span>
                            </div>
                            <Link :href="base + '/notifications'" @click="noteOpen = false">Lihat semua notifikasi</Link>
                        </div>
                    </div>
                    <div style="position:relative">
                        <button type="button" class="adm-account" @click="accountOpen = !accountOpen; noteOpen = false">
                            <span class="adm-avatar">{{ initial }}</span>
                            <span>
                                <strong>{{ admin.name || 'Administrator' }}</strong>
                                <small>{{ admin.role }}</small>
                            </span>
                        </button>
                        <div v-if="accountOpen" class="adm-menu-pop">
                            <div class="who">
                                <strong>{{ admin.name }}</strong>
                                <span>{{ admin.email }}</span>
                            </div>
                            <Link href="/" @click="accountOpen = false">Lihat toko</Link>
                            <Link v-if="allowed.has('/admin/settings')" :href="base + '/settings'" @click="accountOpen = false">Pengaturan</Link>
                            <button type="button" @click="logout">Keluar</button>
                        </div>
                    </div>
                </div>
            </header>

            <main class="adm-body">
                <div v-if="Object.keys($page.props.errors || {}).length" class="adm-alert" role="alert">
                    <p v-for="(error, key) in $page.props.errors" :key="key">{{ error }}</p>
                </div>
                <p v-if="$page.props.status" class="adm-ok" role="status">{{ $page.props.status }}</p>
                <slot />
            </main>
            <AdminSensitivePrompt />
        </div>
    </div>
</template>
