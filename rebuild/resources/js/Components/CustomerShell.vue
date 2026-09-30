<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    logoUrl: { type: String, default: '' },
    compactFooter: { type: Boolean, default: false },
});

const page = usePage();
const open = ref(false);
const supportOpen = ref(false);
const logged = computed(() => Boolean(page.props.auth?.user));
const storefront = computed(() => page.props.storefront || {});
const assets = computed(() => storefront.value.assets || {});
const logo = computed(() => props.logoUrl || assets.value.logo?.url || '');
const favicon = computed(() => assets.value.favicon?.url || '');
const footerDesktop = computed(() => assets.value.footer_banner_desktop?.url || '');
const footerMobile = computed(() => assets.value.footer_banner_mobile?.url || '');
const accountHref = computed(() => logged.value ? '/account' : '/login');
const accountLabel = computed(() => logged.value ? 'Akun' : 'Masuk');
const whatsapp = computed(() => {
    const value = String(storefront.value.supportWhatsapp || '').replace(/\D/g, '').replace(/^0/, '62');
    return value ? 'https://wa.me/' + value : '';
});
const ticketHref = computed(() => logged.value ? '/account/tickets' : '/login');

const drawerLinks = [
    { href: '/', label: 'Beranda', icon: '⌂' },
    { href: '/orders/check', label: 'Cek Pesanan', icon: '▤' },
    { href: '/leaderboard', label: 'Leaderboard', icon: '♜' },
    { href: '/tools/win-rate', label: 'Kalkulator', icon: '▦' },
    { href: '/contact', label: 'Hubungi Kami', icon: '◉' },
    { href: '/news', label: 'Berita', icon: '▧' },
];

const footerGroups = [
    {
        title: 'Layanan',
        items: [
            ['Top Up Game', '/#produk'],
            ['Promo', '/promo'],
            ['Cek Transaksi', '/orders/check'],
            ['Hubungi Kami', '/contact'],
        ],
    },
    {
        title: 'Kalkulator',
        items: [
            ['Win Rate', '/tools/win-rate'],
            ['Zodiac', '/tools/zodiac'],
            ['Magic Wheel', '/tools/magic-wheel'],
            ['Semua Alat', '/tools/win-rate'],
        ],
    },
    {
        title: 'Informasi',
        items: [
            ['Pertanyaan umum', '/faq'],
            ['Syarat & ketentuan', '/terms'],
            ['Kebijakan pengembalian dana', '/refund'],
            ['Kebijakan privasi', '/privacy'],
        ],
    },
];

function closeDrawer() {
    open.value = false;
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
                    <img v-if="logo" :src="logo" alt="LFAMILIA STORE" class="lf-brand-logo">
                    <span v-else class="lf-brand-mark">LF</span>
                    <span>
                        <strong class="lf-brand-name">LFAMILIA</strong>
                        <span class="lf-brand-sub">STORE</span>
                    </span>
                </Link>

                <Link href="/#produk" class="lf-header-search">
                    <span class="lf-search-icon">⌕</span>
                    <span>Cari game atau voucher...</span>
                </Link>

                <nav class="lf-nav">
                    <Link href="/#produk">Top Up</Link>
                    <Link href="/orders/check">Cek Pesanan</Link>
                    <Link href="/leaderboard">Leaderboard</Link>
                </nav>

                <div class="ml-auto flex items-center gap-1.5">
                    <Link :href="accountHref" class="lf-action">{{ logged ? '◉' : '↪' }} {{ accountLabel }}</Link>
                    <Link href="/#produk" class="lf-search-mobile" aria-label="Cari produk">⌕</Link>
                    <button type="button" class="lf-menu" aria-label="Buka menu" @click="open=true">☰</button>
                </div>
            </div>
        </header>

        <div v-if="open" class="lf-overlay" @click="closeDrawer"></div>
        <aside class="lf-drawer" :class="{ open }" aria-label="Menu customer">
            <div class="lf-drawer-head">
                <div>
                    <strong>LFAMILIA STORE</strong>
                    <p>Top up, kalkulator game, dan bantuan.</p>
                </div>
                <button type="button" class="lf-drawer-close" @click="closeDrawer">×</button>
            </div>

            <section class="lf-drawer-account">
                <p class="lf-eyebrow">{{ logged ? 'AKUN AKTIF' : 'SELAMAT DATANG' }}</p>
                <h2>{{ logged ? 'Kelola akun LFAMILIA' : 'Masuk untuk pengalaman lebih cepat' }}</h2>
                <p>{{ logged ? 'Cek saldo, pesanan, membership, tiket, dan profil.' : 'Simpan akun top up favorit, cek saldo, dan riwayat transaksi.' }}</p>
                <div class="lf-drawer-account-actions">
                    <Link :href="accountHref" class="lf-primary" @click="closeDrawer">{{ logged ? 'Buka Akun' : 'Masuk' }}</Link>
                    <Link v-if="!logged" href="/register" class="lf-secondary" @click="closeDrawer">Daftar</Link>
                </div>
            </section>

            <nav class="lf-drawer-nav">
                <Link v-for="item in drawerLinks" :key="item.href" :href="item.href" @click="closeDrawer">
                    <span>{{ item.icon }}</span>{{ item.label }}
                </Link>
            </nav>
        </aside>

        <slot />

        <footer v-if="!compactFooter" class="lf-footer">
            <div v-if="footerDesktop || footerMobile" class="lf-footer-banner">
                <picture>
                    <source v-if="footerMobile" media="(max-width:640px)" :srcset="footerMobile">
                    <img :src="footerDesktop || footerMobile" alt="LFAMILIA STORE">
                </picture>
            </div>

            <div class="lf-container lf-footer-grid">
                <div class="lf-footer-brand-column">
                    <Link href="/" class="lf-brand">
                        <img v-if="logo" :src="logo" alt="LFAMILIA STORE" class="lf-brand-logo">
                        <span v-else class="lf-brand-mark">LF</span>
                        <span>
                            <strong class="lf-brand-name">LFAMILIA</strong>
                            <span class="lf-brand-sub">STORE</span>
                        </span>
                    </Link>
                    <p>{{ storefront.footerDescription || storefront.tagline }}</p>
                    <div class="lf-socials">
                        <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noreferrer" aria-label="WhatsApp">WA</a>
                        <a v-if="storefront.instagramUrl" :href="storefront.instagramUrl" target="_blank" rel="noreferrer" aria-label="Instagram">IG</a>
                        <a v-if="storefront.supportEmail" :href="'mailto:'+storefront.supportEmail" aria-label="Email">✉</a>
                        <a v-if="storefront.discordUrl" :href="storefront.discordUrl" target="_blank" rel="noreferrer" aria-label="Discord">DC</a>
                    </div>
                </div>
                <div v-for="group in footerGroups" :key="group.title">
                    <h2>{{ group.title }}</h2>
                    <ul>
                        <li v-for="[label, href] in group.items" :key="href"><Link :href="href">{{ label }}</Link></li>
                    </ul>
                </div>
            </div>
            <div class="lf-footer-copy">© 2026 {{ storefront.storeName || 'LFAMILIA STORE' }}. Produk dan merek dagang adalah milik pemegang hak masing-masing.</div>
        </footer>

        <div v-if="storefront.supportWidgetEnabled" class="lf-live-support">
            <div v-if="supportOpen" class="lf-support-panel">
                <header>
                    <div><strong>Butuh bantuan?</strong><small>{{ storefront.supportHours || 'Setiap hari, 08.00–23.00 WIB' }}</small></div>
                    <button type="button" @click="supportOpen=false">×</button>
                </header>
                <div>
                    <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noreferrer"><span class="support-wa">◯</span>WhatsApp</a>
                    <a v-if="storefront.discordUrl" :href="storefront.discordUrl" target="_blank" rel="noreferrer"><span class="support-dc">▱</span>Discord</a>
                    <Link :href="ticketHref"><span class="support-ticket">♧</span>Support Ticket</Link>
                </div>
            </div>
            <button type="button" class="lf-support-trigger" :aria-label="supportOpen?'Tutup bantuan':'Buka bantuan'" @click="supportOpen=!supportOpen">◯</button>
        </div>
    </div>
</template>
