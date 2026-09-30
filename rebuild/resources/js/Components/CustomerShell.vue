<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    logoUrl: { type: String, default: '' },
    compactFooter: { type: Boolean, default: false },
});

const page = usePage();
const open = ref(false);
const supportOpen = ref(false);
const logged = computed(() => Boolean(page.props.auth?.user));
const storefront = computed(() => page.props.storefront || {});
const assets = computed(() => storefront.value.assets || {});
const logo = computed(() => assets.value.logo?.url || '');
const favicon = computed(() => assets.value.favicon?.url || '');
const footerDesktop = computed(() => assets.value.footer_banner_desktop?.url || '');
const footerMobile = computed(() => assets.value.footer_banner_mobile?.url || '');
const accountHref = computed(() => logged.value ? '/account' : '/login');
const accountLabel = computed(() => logged.value ? (page.props.auth?.user?.name?.split(' ')?.[0] || 'Akun') : 'Akun');
const whatsapp = computed(() => {
    const value = String(storefront.value.supportWhatsapp || '').replace(/\D/g, '').replace(/^0/, '62');
    return value ? 'https://wa.me/' + value : '';
});
const emailHref = computed(() => storefront.value.supportEmail ? 'mailto:' + storefront.value.supportEmail : '');
const ticketHref = computed(() => logged.value ? '/account/tickets' : '/login');

const desktopNav = [
    ['/\#produk', 'Top Up'],
    ['/promo', 'Voucher'],
    ['/news', 'Berita'],
    ['/leaderboard', 'Leaderboard'],
    ['/tools/win-rate', 'Kalkulator'],
    ['/orders/check', 'Transaksi'],
];

const drawerLinks = [
    ['/', 'Beranda', 'home'],
    ['/orders/check', 'Cek Pesanan', 'receipt'],
    ['/leaderboard', 'Leaderboard', 'trophy'],
    ['/tools/win-rate', 'Kalkulator', 'calculator'],
    ['/contact', 'Hubungi Kami', 'headset'],
    ['/news', 'Berita', 'news'],
];

const siteMap = [
    ['Beranda', '/'],
    ['Top Up Game', '/#produk'],
    ['Promo', '/promo'],
    ['Cek Transaksi', '/orders/check'],
    ['Hubungi Kami', '/contact'],
    ['Berita', '/news'],
];

const calculators = [
    ['Win Rate', '/tools/win-rate'],
    ['Zodiac', '/tools/zodiac'],
    ['Magic Wheel', '/tools/magic-wheel'],
];

const legal = [
    ['Pertanyaan Umum', '/faq'],
    ['Kebijakan Privasi', '/privacy'],
    ['Syarat & Ketentuan', '/terms'],
    ['Kebijakan Pengembalian Dana', '/refund'],
];

function closeDrawer() { open.value = false; }
function supportHref(kind) {
    if (kind === 'wa') return whatsapp.value || '/contact';
    if (kind === 'ig') return storefront.value.instagramUrl || '/contact';
    if (kind === 'email') return emailHref.value || '/contact';
    if (kind === 'discord') return storefront.value.discordUrl || '/contact';
    return ticketHref.value;
}
</script>

<template>
<div class="lf-customer">
    <Head>
        <link v-if="favicon" rel="icon" :href="favicon">
        <link v-if="favicon" rel="shortcut icon" :href="favicon">
        <link v-if="favicon" rel="apple-touch-icon" :href="favicon">
    </Head>

    <header class="lf-header">
        <div class="lf-container lf-header-inner">
            <Link href="/" class="lf-brand" aria-label="LFAMILIA STORE">
                <img v-if="logo || logoUrl" :src="logo || logoUrl" alt="LFAMILIA STORE" class="lf-brand-logo">
                <span v-else class="lf-brand-mark">LF</span>
                <span class="lf-brand-copy">
                    <strong class="lf-brand-name">LFAMILIA</strong>
                    <span class="lf-brand-sub">STORE</span>
                </span>
            </Link>

            <Link href="/#produk" class="lf-header-search" aria-label="Cari produk">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
                <span>Cari game atau voucher...</span>
            </Link>

            <nav class="lf-nav" aria-label="Navigasi utama">
                <Link v-for="[href,label] in desktopNav" :key="href" :href="href">{{label}}</Link>
            </nav>

            <div class="lf-header-actions">
                <Link :href="accountHref" class="lf-action">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg>
                    {{accountLabel}}
                </Link>
                <Link href="/#produk" class="lf-search-mobile" aria-label="Cari produk">
                    <svg viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
                </Link>
                <button type="button" class="lf-menu" aria-label="Buka menu" @click="open=true">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
    </header>

    <div v-if="open" class="lf-overlay" @click="closeDrawer"></div>
    <aside class="lf-drawer" :class="{open}" aria-label="Menu customer">
        <div class="lf-drawer-head">
            <div><strong>LFAMILIA STORE</strong><p>Top up, kalkulator game, dan bantuan.</p></div>
            <button type="button" class="lf-drawer-close" @click="closeDrawer">×</button>
        </div>

        <section class="lf-drawer-account">
            <template v-if="logged">
                <div class="lf-drawer-profile">
                    <span>{{String(page.props.auth?.user?.name||'L').slice(0,1).toUpperCase()}}</span>
                    <div><strong>{{page.props.auth?.user?.name}}</strong><small>Akun aktif</small></div>
                </div>
                <Link href="/account" class="lf-primary" @click="closeDrawer">Akun & Saldo</Link>
            </template>
            <template v-else>
                <p class="lf-eyebrow">SELAMAT DATANG</p>
                <h2>Masuk untuk pengalaman lebih cepat</h2>
                <p>Simpan akun top up favorit, cek saldo, dan riwayat transaksi.</p>
                <div class="lf-drawer-account-actions">
                    <Link href="/login" class="lf-primary" @click="closeDrawer">Masuk</Link>
                    <Link href="/register" class="lf-secondary" @click="closeDrawer">Daftar</Link>
                </div>
            </template>
        </section>

        <nav class="lf-drawer-nav">
            <Link v-for="[href,label,icon] in drawerLinks" :key="href" :href="href" @click="closeDrawer">
                <svg v-if="icon==='home'" viewBox="0 0 24 24"><path d="m3 11 9-8 9 8v10h-6v-6H9v6H3Z"/></svg>
                <svg v-else-if="icon==='receipt'" viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6"/></svg>
                <svg v-else-if="icon==='trophy'" viewBox="0 0 24 24"><path d="M8 4h8v5a4 4 0 0 1-8 0Z"/><path d="M8 6H4v2a4 4 0 0 0 4 4M16 6h4v2a4 4 0 0 1-4 4M12 13v4M8 21h8M9 17h6"/></svg>
                <svg v-else-if="icon==='calculator'" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M16 11h0M8 15h2M12 15h2M16 15h0"/></svg>
                <svg v-else-if="icon==='headset'" viewBox="0 0 24 24"><path d="M4 13v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="13" width="4" height="6" rx="2"/><rect x="17" y="13" width="4" height="6" rx="2"/><path d="M17 19c-1 2-3 2-5 2"/></svg>
                <svg v-else viewBox="0 0 24 24"><path d="M5 4h14v16H5Z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                <span>{{label}}</span>
            </Link>
        </nav>
    </aside>

    <slot />

    <footer v-if="!compactFooter" class="lf-footer">
        <div v-if="footerDesktop || footerMobile" class="lf-footer-banner">
            <picture>
                <source v-if="footerMobile" media="(max-width:639px)" :srcset="footerMobile">
                <img :src="footerDesktop || footerMobile" alt="LFAMILIA STORE">
            </picture>
        </div>

        <div class="lf-container lf-footer-main">
            <div class="lf-footer-brand-column">
                <Link href="/" class="lf-brand">
                    <img v-if="logo || logoUrl" :src="logo || logoUrl" alt="LFAMILIA STORE" class="lf-footer-logo">
                    <span v-else class="lf-brand-mark">LF</span>
                    <span class="lf-brand-copy"><strong class="lf-brand-name">LFAMILIA</strong><span class="lf-brand-sub">STORE</span></span>
                </Link>
                <p>{{storefront.footerDescription || storefront.tagline || 'Top up favoritmu, sat set tanpa ribet.'}}</p>
                <div class="lf-socials">
                    <a :href="supportHref('ig')" :target="storefront.instagramUrl?'_blank':undefined" rel="noreferrer" aria-label="Instagram">
                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg>
                    </a>
                    <a :href="supportHref('wa')" :target="whatsapp?'_blank':undefined" rel="noreferrer" aria-label="WhatsApp">
                        <svg viewBox="0 0 24 24"><path d="M20 11.5a8 8 0 0 1-11.7 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z"/><path d="M8.5 8.2c.7 2.5 2.6 4.4 5.2 5.3l1.3-1.2"/></svg>
                    </a>
                    <a :href="supportHref('email')" aria-label="Email">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                    </a>
                    <a :href="supportHref('discord')" :target="storefront.discordUrl?'_blank':undefined" rel="noreferrer" aria-label="Discord">
                        <svg viewBox="0 0 24 24"><path d="M7 7c3-2 7-2 10 0l2 9c-2 2-4 3-6 3l-1-2-1 2c-2 0-4-1-6-3Z"/><circle cx="9.5" cy="12.5" r="1"/><circle cx="14.5" cy="12.5" r="1"/></svg>
                    </a>
                </div>
            </div>

            <div class="lf-footer-column">
                <h2>Peta Situs</h2>
                <ul><li v-for="[label,href] in siteMap" :key="href"><Link :href="href">{{label}}</Link></li></ul>
            </div>
            <div class="lf-footer-column">
                <h2>Kalkulator</h2>
                <ul><li v-for="[label,href] in calculators" :key="href"><Link :href="href">{{label}}</Link></li></ul>
                <h2 class="lf-footer-subhead">Legalitas</h2>
                <ul><li v-for="[label,href] in legal" :key="href"><Link :href="href">{{label}}</Link></li></ul>
            </div>
            <div class="lf-footer-column">
                <h2>Dukungan</h2>
                <ul>
                    <li><a :href="supportHref('wa')" :target="whatsapp?'_blank':undefined" rel="noreferrer">WhatsApp</a></li>
                    <li><a :href="supportHref('ig')" :target="storefront.instagramUrl?'_blank':undefined" rel="noreferrer">Instagram</a></li>
                    <li><a :href="supportHref('email')">Email</a></li>
                    <li><a :href="supportHref('discord')" :target="storefront.discordUrl?'_blank':undefined" rel="noreferrer">Discord</a></li>
                    <li><Link :href="ticketHref">Support Ticket</Link></li>
                </ul>
            </div>
        </div>
        <div class="lf-footer-copy">© 2026 {{storefront.storeName || 'LFAMILIA STORE'}}. Produk dan merek dagang adalah milik pemegang hak masing-masing.</div>
    </footer>

    <div v-if="storefront.supportWidgetEnabled !== false" class="lf-live-support">
        <div v-if="supportOpen" class="lf-support-panel">
            <header>
                <div><strong>Butuh bantuan?</strong><small>{{storefront.supportHours || 'Setiap hari, 08.00–23.00 WIB'}}</small></div>
                <button type="button" @click="supportOpen=false">×</button>
            </header>
            <div>
                <a :href="supportHref('wa')" :target="whatsapp?'_blank':undefined" rel="noreferrer">
                    <svg viewBox="0 0 24 24" class="support-wa"><path d="M20 11.5a8 8 0 0 1-11.7 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z"/><path d="M8.5 8.2c.7 2.5 2.6 4.4 5.2 5.3l1.3-1.2"/></svg>
                    <span><strong>WhatsApp</strong><small>{{storefront.supportWhatsapp || 'Hubungi tim bantuan'}}</small></span>
                </a>
                <a :href="supportHref('discord')" :target="storefront.discordUrl?'_blank':undefined" rel="noreferrer">
                    <svg viewBox="0 0 24 24" class="support-dc"><path d="M7 7c3-2 7-2 10 0l2 9c-2 2-4 3-6 3l-1-2-1 2c-2 0-4-1-6-3Z"/><circle cx="9.5" cy="12.5" r="1"/><circle cx="14.5" cy="12.5" r="1"/></svg>
                    <span><strong>Discord</strong><small>Komunitas & bantuan</small></span>
                </a>
                <a :href="supportHref('email')">
                    <svg viewBox="0 0 24 24" class="support-mail"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                    <span><strong>Email</strong><small>{{storefront.supportEmail || 'Kirim pertanyaan'}}</small></span>
                </a>
                <Link :href="ticketHref">
                    <svg viewBox="0 0 24 24" class="support-ticket"><path d="M4 12a8 8 0 0 1 16 0v5a3 3 0 0 1-3 3h-2"/><path d="M4 13h3v5H4ZM17 13h3v5h-3Z"/></svg>
                    <span><strong>Support Ticket</strong><small>{{logged?'Buka tiket bantuan':'Masuk untuk membuat tiket'}}</small></span>
                </Link>
            </div>
        </div>
        <button type="button" class="lf-support-trigger" :aria-label="supportOpen?'Tutup bantuan':'Buka bantuan'" @click="supportOpen=!supportOpen">
            <svg viewBox="0 0 24 24"><path d="M5 5h14v11H9l-4 3Z"/><path d="M8 9h8M8 12h5"/></svg>
        </button>
    </div>
</div>
</template>
