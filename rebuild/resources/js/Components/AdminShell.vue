<script setup>
import { DropdownMenuItem } from 'reka-ui';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from './ui/dropdown-menu';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from "./ui/sheet";
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AdminIcon from './AdminIcon.vue';
const page = usePage(), open = ref(false), query = ref(''), results = ref([]), searching = ref(false), searchError = ref(''), searchInput = ref(null), accountOpen = ref(false), notificationOpen = ref(false);
const panel = computed(() => page.props.adminPanel || { menu: [] });
const base = computed(() => panel.value.base_path || '/admin');
const initial = computed(() => String(panel.value.admin?.name || 'A').slice(0,1).toUpperCase());
const favicon = computed(() => page.props.storefront?.assets?.favicon?.url || '');
const active = href => {
 const [path, query] = href.split('?');
 if (query) return page.url === href;
 if (path === '/admin/providers') return page.url === path;
 return page.url.split('?')[0] === path || page.url.startsWith(path + '/');
};
const icon = href => {
 const path = href.split('?')[0].split('/')[2];
 return { panel:'dashboard', content:'content', 'nickname-tools':'nickname', payments:'payments', customers:'customers', vouchers:'vouchers', support:'support', reports:'reports', access:'access', settings:'settings', integrations:'integrations', health:'health', audit:'audit', fulfillment:'fulfillment', catalog:'catalog', orders:'orders', providers:'providers' }[path] || 'dashboard';
};
let controller;
let searchRequest = 0;
const dismissSearch = () => { ++searchRequest;controller?.abort();results.value=[];searchError.value='';searching.value=false; };
const outsideSearch = event => { if(event.target instanceof Element && !event.target.closest('.lf-admin-search')) dismissSearch(); };
watch([open,accountOpen,notificationOpen], values => { if(values.some(Boolean)) dismissSearch(); });
const search = async () => {
 const request = ++searchRequest;
 controller?.abort();
 const q = query.value.trim();
 if (q.length < 2) { results.value = []; searching.value = false; return; }
 controller = new AbortController();
 searching.value = true; searchError.value = '';
 try {
  const response = await fetch(base.value + '/search?q=' + encodeURIComponent(q), { credentials:'same-origin', headers:{Accept:'application/json'}, signal:controller.signal });
  if (!response.ok) throw new Error('Pencarian belum dapat dimuat.');
  const data = await response.json();
  if (request === searchRequest) results.value = data.results || [];
 } catch (error) { if(request === searchRequest && error.name !== 'AbortError') searchError.value = error.message; }
 finally { if (request === searchRequest) searching.value = false; }
};
const shortcut = event => { if((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k'){event.preventDefault();searchInput.value?.focus();} if(event.key === 'Escape'){open.value=false;dismissSearch();accountOpen.value=false;notificationOpen.value=false;} };
watch(() => page.url, () => { open.value=false;dismissSearch();accountOpen.value=false;notificationOpen.value=false; });
onMounted(() => {window.addEventListener('keydown', shortcut);window.addEventListener('click', outsideSearch);});
onUnmounted(() => { window.removeEventListener('keydown', shortcut);window.removeEventListener('click', outsideSearch);controller?.abort(); });
</script>
<template>
<Head>
 <link v-if="favicon" rel="icon" :href="favicon">
 <link v-if="favicon" rel="shortcut icon" :href="favicon">
 <link v-if="favicon" rel="apple-touch-icon" :href="favicon">
</Head>
<div class="lf-admin">
 <a href="#admin-main" class="lf-admin-skip">Langsung ke konten</a>
 <Sheet v-model:open="open"><SheetContent side="left" class="lf-admin-mobile-navigation"><SheetTitle class="sr-only">Navigasi Admin</SheetTitle><SheetDescription class="sr-only">Pilih menu pengelolaan toko.</SheetDescription>
  <Link :href="base + '/panel'" class="lf-admin-brand"><span class="lf-admin-logo"><AdminIcon name="nickname" /></span><span><strong>{{ $page.props.storefront?.storeName || 'LFAMILIA' }} ADMIN</strong><small>{{ $page.props.storefront?.tagline || 'Top Up Game Solution' }}</small></span></Link>
  
  <nav class="lf-admin-menu"><Link v-for="item in panel.menu" :key="item.href" :href="item.href" :class="{active:active(item.href)}" :aria-current="active(item.href) ? 'page' : undefined" @click="open=false"><AdminIcon :name="icon(item.href)" /><span>{{ item.label }}</span></Link></nav>
  <div v-if="panel.menu.some(x => x.href === '/admin/support')" class="lf-admin-help"><strong>Butuh bantuan?</strong><p>Buka Layanan Pelanggan untuk melihat dan menangani tiket.</p><Link :href="base + '/support'">Pusat Bantuan</Link></div>
  <div class="lf-admin-sidebar-footer">© {{ new Date().getFullYear() }} {{ $page.props.storefront?.storeName || 'LFAMILIA' }}</div>
 </SheetContent></Sheet>
 <aside class="lf-admin-sidebar" aria-label="Navigasi Admin">
  <Link :href="base + '/panel'" class="lf-admin-brand"><span class="lf-admin-logo"><AdminIcon name="nickname" /></span><span><strong>{{ $page.props.storefront?.storeName || 'LFAMILIA' }} ADMIN</strong><small>{{ $page.props.storefront?.tagline || 'Top Up Game Solution' }}</small></span></Link>
  <button type="button" class="lf-admin-drawer-close" aria-label="Tutup menu" @click="open=false"><AdminIcon name="close" /></button>
  <nav class="lf-admin-menu"><Link v-for="item in panel.menu" :key="item.href" :href="item.href" :class="{active:active(item.href)}" :aria-current="active(item.href) ? 'page' : undefined" @click="open=false"><AdminIcon :name="icon(item.href)" /><span>{{ item.label }}</span></Link></nav>
  <div v-if="panel.menu.some(x => x.href === '/admin/support')" class="lf-admin-help"><strong>Butuh bantuan?</strong><p>Buka Layanan Pelanggan untuk melihat dan menangani tiket.</p><Link :href="base + '/support'">Pusat Bantuan</Link></div>
  <div class="lf-admin-sidebar-footer">© {{ new Date().getFullYear() }} {{ $page.props.storefront?.storeName || 'LFAMILIA' }}</div>
 </aside>
 <div class="lf-admin-body">
  <header class="lf-admin-top">
   <button type="button" class="lf-admin-toggle" aria-label="Buka menu Admin" :aria-expanded="open" @click="open=true"><AdminIcon name="menu" /></button>
   <form class="lf-admin-search" @submit.prevent="search"><AdminIcon name="search" /><input ref="searchInput" v-model="query" placeholder="Cari di Admin…" aria-label="Pencarian Admin" @input="dismissSearch"><button type="submit" :disabled="searching">Cari</button><kbd>Ctrl K</kbd>
    <div v-if="results.length || searchError" class="lf-admin-search-results"><p v-if="searchError" role="alert">{{ searchError }}</p><Link v-for="(result, i) in results" :key="i" :href="result.href"><strong>{{result.title}}</strong><small>{{result.detail}}</small></Link></div>
   </form>
   <div class="lf-admin-top-actions">
    <DropdownMenu v-if="panel.can_notifications" v-model:open="notificationOpen">
     <DropdownMenuTrigger as-child><button type="button" class="lf-admin-dropdown lf-admin-notification-button" aria-label="Notifikasi"><AdminIcon name="bell"/><span v-if="panel.unread_notifications" class="lf-admin-notification-count">{{panel.unread_notifications}}</span></button></DropdownMenuTrigger>
     <DropdownMenuContent align="end" class="lf-admin-popover"><div class="px-2 py-2"><strong>Notifikasi</strong><p>{{ panel.unread_notifications || 0 }} notifikasi belum dibaca.</p></div><DropdownMenuItem as-child><Link :href="base + '/notifications'">Lihat semua notifikasi</Link></DropdownMenuItem></DropdownMenuContent>
    </DropdownMenu>
    <DropdownMenu v-model:open="accountOpen">
     <DropdownMenuTrigger as-child><button type="button" class="lf-admin-account-button" aria-label="Menu akun Admin"><span class="lf-admin-avatar">{{initial}}</span><span><strong>{{panel.admin?.name || 'Administrator'}}</strong><small>{{panel.admin?.role}}</small></span></button></DropdownMenuTrigger>
     <DropdownMenuContent align="end" class="lf-admin-popover"><div class="border-b px-2 py-2"><strong>{{panel.admin?.name}}</strong><p>{{panel.admin?.email}}</p></div><DropdownMenuItem as-child><Link href="/"><AdminIcon name="store"/>Lihat toko</Link></DropdownMenuItem><DropdownMenuItem v-if="panel.menu.some(x => x.href === '/admin/settings')" as-child><Link :href="base + '/settings'"><AdminIcon name="settings"/>Pengaturan</Link></DropdownMenuItem><DropdownMenuItem @select="router.post(base + '/logout')"><AdminIcon name="logout"/>Keluar</DropdownMenuItem></DropdownMenuContent>
    </DropdownMenu>
   </div>
  </header>
  <main id="admin-main" tabindex="-1" class="lf-admin-content"><div v-if="Object.keys($page.props.errors || {}).length" role="alert" class="lf-admin-error"><p v-for="(error,key) in $page.props.errors" :key="key">{{error}}</p></div><p v-if="$page.props.status" role="status" class="lf-admin-success">{{$page.props.status}}</p><slot/></main>
 </div>
</div>
</template>
