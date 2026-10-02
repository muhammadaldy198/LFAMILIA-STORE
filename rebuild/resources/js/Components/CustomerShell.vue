<script setup>
import { useCustomerPresentation } from '../Composables/customerPresentation';
const { customerText, sectionEnabled, presentationStyle } = useCustomerPresentation();

import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

defineProps({
    logoUrl: { type: String, default: '' },
    compactFooter: { type: Boolean, default: false },
});

const page = usePage();
const open = ref(false);
const supportOpen = ref(false);
const supportPosition = ref(null);
let supportDrag = null;
const logged = computed(() => Boolean(page.props.auth?.user));
const balanceIdr = computed(() => Number(page.props.auth?.user?.balance_idr || 0));
const membershipTier = computed(() => page.props.auth?.user?.membership_tier_code || 'BASIC');
const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const currentYear = new Date().getFullYear();
const storefront = computed(() => page.props.storefront || {});
const assets = computed(() => storefront.value.assets || {});
const logo = computed(() => assets.value.logo?.url || '');
const favicon = computed(() => assets.value.favicon?.url || '');
const footerDesktop = computed(() => assets.value.footer_banner_desktop?.url || '/brand/lfamilia-footer-desktop-wordmark.jpg');
const footerMobile = computed(() => assets.value.footer_banner_mobile?.url || '/brand/lfamilia-footer-mobile-wordmark.jpg');
const accountHref = computed(() => logged.value ? '/account' : '/login');
const accountLabel = computed(() => logged.value ? (page.props.auth?.user?.name?.split(' ')?.[0] || 'Akun') : 'Akun');
const whatsapp = computed(() => {
    const value = String(storefront.value.supportWhatsapp || '').replace(/\D/g, '').replace(/^0/, '62');
    return value ? 'https://wa.me/' + value : '';
});
const emailHref = computed(() => storefront.value.supportEmail ? 'mailto:' + storefront.value.supportEmail : '');
const ticketHref = computed(() => logged.value ? '/account/tickets' : '/support');

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

const services = [
    ['Top Up Game', '/#produk'],
    ['Promo', '/promo'],
    ['Cek Transaksi', '/orders/check'],
    ['Hubungi Kami', '/contact'],
];

const calculators = [
    ['Win Rate', '/tools/win-rate'],
    ['Zodiac', '/tools/zodiac'],
    ['Magic Wheel', '/tools/magic-wheel'],
    ['Semua Alat', '/tools'],
];

const legal = [
    ['Pertanyaan Umum', '/faq'],
    ['Kebijakan Privasi', '/privacy'],
    ['Syarat & Ketentuan', '/terms'],
    ['Kebijakan Pengembalian Dana', '/refund'],
];

function openDrawer() {
    supportOpen.value = false;
    open.value = true;
}
function closeDrawer() { open.value = false; }

watch(open, (value) => {
    document.documentElement.style.overflow = value ? 'hidden' : '';
});
onUnmounted(() => {
    document.documentElement.style.overflow = '';
    window.removeEventListener('pointermove', moveSupportDrag);
});

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function startSupportDrag(event) {
    if (window.innerWidth > 640) {
        supportOpen.value = !supportOpen.value;
        return;
    }

    const trigger = event.currentTarget;
    const rect = trigger.getBoundingClientRect();
    supportDrag = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        originX: rect.left,
        originY: rect.top,
        width: rect.width,
        height: rect.height,
        moved: false,
    };

    trigger.setPointerCapture?.(event.pointerId);
    window.addEventListener('pointermove', moveSupportDrag, { passive: false });
    window.addEventListener('pointerup', endSupportDrag, { once: true });
    event.preventDefault();
}

function moveSupportDrag(event) {
    if (!supportDrag || event.pointerId !== supportDrag.pointerId) return;

    const dx = event.clientX - supportDrag.startX;
    const dy = event.clientY - supportDrag.startY;
    if (Math.hypot(dx, dy) > 5) supportDrag.moved = true;

    const x = clamp(supportDrag.originX + dx, 8, window.innerWidth - supportDrag.width - 8);
    const y = clamp(supportDrag.originY + dy, 68, window.innerHeight - supportDrag.height - 14);
    supportPosition.value = {
        x,
        y,
        side: x < window.innerWidth / 2 ? 'right' : 'left',
        vertical: y < window.innerHeight / 2 ? 'down' : 'up',
    };
    event.preventDefault();
}

function endSupportDrag(event) {
    if (!supportDrag || event.pointerId !== supportDrag.pointerId) return;

    const moved = supportDrag.moved;
    supportDrag = null;
    window.removeEventListener('pointermove', moveSupportDrag);

    if (!moved) supportOpen.value = !supportOpen.value;
}

function supportHref(kind) {
    if (kind === 'wa') return whatsapp.value || '/contact';
    if (kind === 'ig') return storefront.value.instagramUrl || '/contact';
    if (kind === 'email') return emailHref.value || '/contact';
    if (kind === 'discord') return storefront.value.discordUrl || '/contact';
    return ticketHref.value;
}
</script>

<template>
<div class="lf-customer" :style="presentationStyle">
    <Head>
        <link v-if="favicon" rel="icon" :href="favicon">
        <link v-if="favicon" rel="shortcut icon" :href="favicon">
        <link v-if="favicon" rel="apple-touch-icon" :href="favicon">
    </Head>

    <header class="lf-header">
        <div class="lf-container lf-header-inner">
            <Link href="/" class="lf-brand" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.adae0b8b&quot;, &quot;LFAMILIA STORE&quot;)">
                <img v-if="logo || logoUrl" :src="logo || logoUrl" :alt="customerText(&quot;components.customershell.attribute.alt.adae0b8b&quot;, &quot;LFAMILIA STORE&quot;)" class="lf-brand-logo">
                <span v-else class="lf-brand-mark">{{ customerText("components.customershell.6ae24f17", "LF") }}</span>
                <span class="lf-brand-copy">
                    <strong class="lf-brand-name">{{ customerText("components.customershell.497f244e", "LFAMILIA") }}</strong>
                    <span class="lf-brand-sub">{{ customerText("components.customershell.70515d8e", "STORE") }}</span>
                </span>
            </Link>

            <Link href="/#produk" class="lf-header-search" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.4e933d91&quot;, &quot;Cari produk&quot;)">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
                <span>{{ customerText("components.customershell.d0853d5f", "Cari game atau voucher...") }}</span>
            </Link>

            <nav class="lf-nav" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.70783293&quot;, &quot;Navigasi utama&quot;)">
                <Link v-for="[href,label] in desktopNav" :key="href" :href="href">{{ customerText('navigation.desktopNav.' + href, label) }}</Link>
            </nav>

            <div class="lf-header-actions">
                <Link :href="accountHref" class="lf-action">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg>
                    {{accountLabel}}
                </Link>
                <Link href="/#produk" class="lf-search-mobile" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.4e933d91&quot;, &quot;Cari produk&quot;)">
                    <svg viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
                </Link>
                <button type="button" class="lf-menu" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.9fa7b867&quot;, &quot;Buka menu&quot;)" :aria-expanded="open" @click="openDrawer">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
    </header>

    <div v-if="open" class="lf-overlay" @click="closeDrawer"></div>
    <aside class="lf-drawer" :class="{open}" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.980d1dd6&quot;, &quot;Menu customer&quot;)">
        <div class="lf-drawer-head">
            <div><strong>{{ customerText("components.customershell.adae0b8b", "LFAMILIA STORE") }}</strong><p>{{ customerText("components.customershell.c36f2f11", "Top up, kalkulator game, dan bantuan.") }}</p></div>
            <button type="button" class="lf-drawer-close" @click="closeDrawer">{{ customerText("components.customershell.520b4356", "×") }}</button>
        </div>

        <section class="lf-drawer-account">
            <template v-if="logged">
                <div class="lf-drawer-profile">
                    <span>{{String(page.props.auth?.user?.name||'L').slice(0,1).toUpperCase()}}</span>
                    <div><strong>{{page.props.auth?.user?.name}}</strong><small>{{membershipTier}} · Akun aktif</small></div>
                </div>
                <div class="lf-drawer-balance">
                    <span>{{ customerText("components.customershell.2e5a0e59", "Saldo LFAMILIA") }}</span>
                    <strong>{{money(balanceIdr)}}</strong>
                </div>
                <div class="lf-drawer-account-links">
                    <Link href="/account/wallet" @click="closeDrawer"><span>{{ customerText("components.customershell.32d4363a", "Saldo") }}</span><b>›</b></Link>
                    <Link href="/account/orders" @click="closeDrawer"><span>{{ customerText("components.customershell.517546c3", "Pesanan") }}</span><b>›</b></Link>
                    <Link href="/account/tickets" @click="closeDrawer"><span>{{ customerText("components.customershell.d26d34be", "Tiket") }}</span><b>›</b></Link>
                    <Link href="/account/profile" @click="closeDrawer"><span>{{ customerText("components.customershell.8b01539f", "Profil") }}</span><b>›</b></Link>
                </div>
            </template>
            <template v-else>
                <p class="lf-eyebrow">{{ customerText("components.customershell.bd76070b", "SELAMAT DATANG") }}</p>
                <h2>{{ customerText("components.customershell.8e3fd344", "Masuk untuk pengalaman lebih cepat") }}</h2>
                <p>{{ customerText("components.customershell.30cf56e3", "Simpan akun top up favorit, cek saldo, dan riwayat transaksi.") }}</p>
                <div class="lf-drawer-account-actions">
                    <Link href="/login" class="lf-primary" @click="closeDrawer">{{ customerText("components.customershell.5f295534", "Masuk") }}</Link>
                    <Link href="/register" class="lf-secondary" @click="closeDrawer">{{ customerText("components.customershell.5e0b087f", "Daftar") }}</Link>
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
                <span>{{ customerText('navigation.drawerLinks.' + href, label) }}</span>
            </Link>
        </nav>
    </aside>

    <slot />

    <section v-if="!compactFooter && storefront.supportCtaEnabled !== false" class="lf-container lf-section">
        <div class="lf-help">
            <div>
                <p class="lf-eyebrow">{{ storefront.supportCtaLabel || 'BUTUH BANTUAN?' }}</p>
                <h2 class="lf-title">{{ storefront.supportCtaTitle || 'Tim LFAMILIA siap membantu.' }}</h2>
                <p class="lf-copy">{{ storefront.supportCtaBody || 'Butuh bantuan memilih produk, pembayaran, atau mengecek status pesanan? Hubungi tim kami.' }}</p>
            </div>
            <Link :href="storefront.supportUrl || '/contact'" class="lf-primary">{{ storefront.supportCtaButton || 'Hubungi Kami' }}</Link>
        </div>
    </section>

    <footer v-if="!compactFooter" class="lf-footer">
        <div v-if="(footerDesktop || footerMobile) && sectionEnabled('footerBanner')" class="lf-footer-banner">
            <picture>
                <source v-if="footerMobile" media="(max-width:639px)" :srcset="footerMobile">
                <img :src="footerDesktop || footerMobile" :alt="customerText(&quot;components.customershell.attribute.alt.adae0b8b&quot;, &quot;LFAMILIA STORE&quot;)">
            </picture>
        </div>

        <div class="lf-container lf-footer-main">
            <div class="lf-footer-brand-column">
                <Link href="/" class="lf-brand">
                    <img v-if="logo || logoUrl" :src="logo || logoUrl" :alt="customerText(&quot;components.customershell.attribute.alt.adae0b8b&quot;, &quot;LFAMILIA STORE&quot;)" class="lf-footer-logo">
                    <span v-else class="lf-brand-mark">{{ customerText("components.customershell.6ae24f17", "LF") }}</span>
                    <span class="lf-brand-copy"><strong class="lf-brand-name">{{ customerText("components.customershell.497f244e", "LFAMILIA") }}</strong><span class="lf-brand-sub">{{ customerText("components.customershell.70515d8e", "STORE") }}</span></span>
                </Link>
                <p>{{storefront.footerDescription || storefront.tagline || 'Top up favoritmu, sat set tanpa ribet.'}}</p>
                <div class="lf-socials">
                    <a v-if="storefront.instagramUrl" class="social-instagram" :href="storefront.instagramUrl" target="_blank" rel="noreferrer" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.f95701b&quot;, &quot;Instagram&quot;)">
                        <svg class="lf-brand-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.75 2h8.5A5.75 5.75 0 0 1 22 7.75v8.5A5.75 5.75 0 0 1 16.25 22h-8.5A5.75 5.75 0 0 1 2 16.25v-8.5A5.75 5.75 0 0 1 7.75 2Zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5A3.75 3.75 0 0 0 20 16.25v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5Zm8.96 1.5a1.29 1.29 0 1 1 0 2.58 1.29 1.29 0 0 1 0-2.58ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/></svg>
                    </a>
                    <a v-if="whatsapp" class="social-whatsapp" :href="whatsapp" target="_blank" rel="noreferrer" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.b1332787&quot;, &quot;WhatsApp&quot;)">
                        <svg class="lf-brand-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                    </a>
                    <a v-if="emailHref" class="social-email" :href="emailHref" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.43352167&quot;, &quot;Email&quot;)">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                    </a>
                    <a v-if="storefront.discordUrl" class="social-discord" :href="storefront.discordUrl" target="_blank" rel="noreferrer" :aria-label="customerText(&quot;components.customershell.attribute.aria-label.c839636f&quot;, &quot;Discord&quot;)">
                        <svg class="lf-brand-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.317 4.37a19.8 19.8 0 00-4.885-1.515.074.074 0 00-.079.037c-.211.375-.445.865-.608 1.25a15.4 15.4 0 00-5.487 0c-.164-.394-.406-.875-.618-1.25a.077.077 0 00-.078-.037A19.74 19.74 0 003.677 4.37a.07.07 0 00-.032.028C.533 9.046-.319 13.58.1 18.058a.082.082 0 00.031.056c2.053 1.507 4.041 2.422 5.993 3.029a.077.077 0 00.084-.028c.462-.63.873-1.295 1.226-1.994a.077.077 0 00-.042-.106 12.3 12.3 0 01-1.872-.892.077.077 0 01-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 01.078-.01c3.927 1.793 8.18 1.793 12.061 0a.073.073 0 01.079.01c.12.099.246.198.373.292a.077.077 0 01-.007.128c-.598.343-1.22.644-1.873.891a.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.077.077 0 00.084.029c1.961-.607 3.95-1.522 6.002-3.03a.082.082 0 00.032-.055c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.029ZM8.02 15.331c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.211 0 2.176 1.095 2.157 2.419 0 1.333-.956 2.419-2.157 2.419Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.211 0 2.176 1.095 2.157 2.419 0 1.333-.946 2.419-2.157 2.419Z"/></svg>
                    </a>
                </div>
            </div>

            <div class="lf-footer-column">
                <h2>{{ customerText("components.customershell.3a0625b1", "Layanan") }}</h2>
                <ul><li v-for="[label,href] in services" :key="href"><Link :href="href">{{ customerText('navigation.services.' + href, label) }}</Link></li></ul>
            </div>
            <div class="lf-footer-column">
                <h2>{{ customerText("components.customershell.277be53d", "Kalkulator") }}</h2>
                <ul><li v-for="[label,href] in calculators" :key="href"><Link :href="href">{{ customerText('navigation.calculators.' + href, label) }}</Link></li></ul>
            </div>
            <div class="lf-footer-column">
                <h2>{{ customerText("components.customershell.56c07293", "Informasi") }}</h2>
                <ul><li v-for="[label,href] in legal" :key="href"><Link :href="href">{{ customerText('navigation.legal.' + href, label) }}</Link></li></ul>
            </div>
        </div>
        <div class="lf-footer-copy">© {{currentYear}} {{storefront.storeName || 'LFAMILIA STORE'}}. {{customerText('layout.footer.copyright','Produk dan merek dagang adalah milik pemegang hak masing-masing.')}}</div>
    </footer>

    <div
        v-if="storefront.supportWidgetEnabled !== false"
        class="lf-live-support"
        :class="{
            'is-dragged': supportPosition,
            'open-right': supportPosition?.side === 'right',
            'open-down': supportPosition?.vertical === 'down',
        }"
        :style="supportPosition ? { left: supportPosition.x + 'px', top: supportPosition.y + 'px', right: 'auto', bottom: 'auto' } : undefined"
    >
        <div v-if="supportOpen" class="lf-support-panel">
            <header>
                <div><strong>{{ customerText("components.customershell.6c564ecd", "Butuh bantuan?") }}</strong><small>{{storefront.supportHours || 'Setiap hari, 08.00–23.00 WIB'}}</small></div>
                <button type="button" @click="supportOpen=false">{{ customerText("components.customershell.520b4356", "×") }}</button>
            </header>
            <div>
                <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noreferrer">
                    <svg viewBox="0 0 24 24" class="support-wa lf-brand-social-icon" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                    <span><strong>{{ customerText("components.customershell.b1332787", "WhatsApp") }}</strong><small>{{storefront.supportWhatsapp || 'Hubungi tim bantuan'}}</small></span>
                </a>
                <a v-if="storefront.discordUrl" :href="storefront.discordUrl" target="_blank" rel="noreferrer">
                    <svg viewBox="0 0 24 24" class="support-dc lf-brand-social-icon" aria-hidden="true"><path d="M20.317 4.37a19.8 19.8 0 00-4.885-1.515.074.074 0 00-.079.037c-.211.375-.445.865-.608 1.25a15.4 15.4 0 00-5.487 0c-.164-.394-.406-.875-.618-1.25a.077.077 0 00-.078-.037A19.74 19.74 0 003.677 4.37a.07.07 0 00-.032.028C.533 9.046-.319 13.58.1 18.058a.082.082 0 00.031.056c2.053 1.507 4.041 2.422 5.993 3.029a.077.077 0 00.084-.028c.462-.63.873-1.295 1.226-1.994a.077.077 0 00-.042-.106 12.3 12.3 0 01-1.872-.892.077.077 0 01-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 01.078-.01c3.927 1.793 8.18 1.793 12.061 0a.073.073 0 01.079.01c.12.099.246.198.373.292a.077.077 0 01-.007.128c-.598.343-1.22.644-1.873.891a.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.077.077 0 00.084.029c1.961-.607 3.95-1.522 6.002-3.03a.082.082 0 00.032-.055c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.029ZM8.02 15.331c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.211 0 2.176 1.095 2.157 2.419 0 1.333-.956 2.419-2.157 2.419Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.211 0 2.176 1.095 2.157 2.419 0 1.333-.946 2.419-2.157 2.419Z"/></svg>
                    <span><strong>{{ customerText("components.customershell.c839636f", "Discord") }}</strong><small>{{ customerText("components.customershell.909407ff", "Komunitas & bantuan") }}</small></span>
                </a>
                <a v-if="emailHref" :href="emailHref">
                    <svg viewBox="0 0 24 24" class="support-mail"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                    <span><strong>{{ customerText("components.customershell.43352167", "Email") }}</strong><small>{{storefront.supportEmail || 'Kirim pertanyaan'}}</small></span>
                </a>
                <Link href="/contact">
                    <svg viewBox="0 0 24 24" class="support-ticket"><path d="M4 12a8 8 0 0 1 16 0v5a3 3 0 0 1-3 3h-2"/><path d="M4 13h3v5H4ZM17 13h3v5h-3Z"/></svg>
                    <span><strong>{{ customerText("components.customershell.a8b3bfd3", "Hubungi Kami") }}</strong><small>{{ customerText("components.customershell.933a9618", "Lihat semua kanal bantuan") }}</small></span>
                </Link>
                <Link :href="ticketHref">
                    <svg viewBox="0 0 24 24" class="support-ticket"><path d="M4 12a8 8 0 0 1 16 0v5a3 3 0 0 1-3 3h-2"/><path d="M4 13h3v5H4ZM17 13h3v5h-3Z"/></svg>
                    <span><strong>{{ customerText("components.customershell.2e15e52c", "Support Ticket") }}</strong><small>{{logged?'Buka tiket bantuan':'Buat tiket bantuan tamu'}}</small></span>
                </Link>
            </div>
        </div>
        <button
            type="button"
            class="lf-support-trigger"
            :aria-label="supportOpen?'Tutup bantuan':'Buka bantuan'"
            @pointerdown="startSupportDrag"
            @keydown.enter.space.prevent="supportOpen=!supportOpen"
        >
            <svg viewBox="0 0 24 24"><path d="M5 5h14v11H9l-4 3Z"/><path d="M8 9h8M8 12h5"/></svg>
        </button>
    </div>
</div>
</template>
