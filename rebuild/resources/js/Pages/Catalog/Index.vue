<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText, sectionEnabled } = useCustomerPresentation();

import {Head,Link,router,usePage} from '@inertiajs/vue3';
import {computed,onMounted,onUnmounted,ref} from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';
import CategoryIcon from '../../Components/CategoryIcon.vue';

const p=defineProps({
    categories:Array,products:Object,popularProducts:Array,filters:Object,logoUrl:String,faviconUrl:String,
    banners:Array,popups:Array,news:Array,reviews:Array,
});
const page=usePage();
const storefront=computed(()=>page.props.storefront||{});
const search=ref(p.filters.q??'');
const mobile=ref(false);
const bannerIndex=ref(0);
const popupOpen=ref(false);
const popupHideAgain=ref(false);
const popupItem=ref(null);
let bannerTimer=null;
let mediaQuery=null;
const visibleBanners=computed(()=>(p.banners||[]).filter((item)=>mobile.value ? item.show_mobile!==false : item.show_desktop!==false));
const activeBanner=computed(()=>visibleBanners.value[Math.min(bannerIndex.value,Math.max(visibleBanners.value.length-1,0))]||null);
const activePopup=computed(()=>popupItem.value);
const popular=computed(()=>p.popularProducts??[]);
const fallbackNews=[
    {id:'preview-game',title:'Info gaming terbaru',summary:'Update game dan informasi produk terbaru akan tampil di sini.',source_label:'Contoh Berita',is_preview:true},
    {id:'preview-promo',title:'Promo & produk LFAMILIA',summary:'Promo dan informasi produk terbaru akan tampil setelah diterbitkan.',source_label:'Contoh Berita',is_preview:true},
    {id:'preview-service',title:'Pengumuman layanan',summary:'Informasi layanan dan pembaruan toko akan tampil di sini.',source_label:'Contoh Berita',is_preview:true},
];
const homeNews=computed(()=>p.news?.length?p.news:fallbackNews);
const q=(x={})=>{const a={...p.filters,...x};Object.keys(a).forEach(k=>{if(!a[k])delete a[k]});const s=new URLSearchParams(a).toString();return s?'/?'+s:'/'};
const submit=()=>router.get('/',{...p.filters,q:search.value},{preserveState:true,preserveScroll:true});
const initial=n=>(n||'L').slice(0,1).toUpperCase();
const label=l=>String(l).includes('Previous')?'‹':String(l).includes('Next')?'›':String(l).replace(/&laquo;|&raquo;/g,'').trim();
const newsDate=v=>v?new Date(v).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'}):'LFAMILIA News';
const bannerPrev=()=>{const n=visibleBanners.value.length;if(n)bannerIndex.value=(bannerIndex.value-1+n)%n};
const bannerNext=()=>{const n=visibleBanners.value.length;if(n)bannerIndex.value=(bannerIndex.value+1)%n};
const popupKey=(item)=>'lfamilia-home-popup:'+String(item.id??'fallback')+':'+String(item.title||'').slice(0,24);
const closePopup=()=>{
    if(popupHideAgain.value && activePopup.value){
        const days=Math.max(1,Number(activePopup.value.dismiss_days||0));
        window.localStorage.setItem(popupKey(activePopup.value),String(Date.now()+days*86400000));
    }
    popupOpen.value=false;
};
onMounted(()=>{
    mediaQuery=window.matchMedia('(max-width:639px)');
    const updateMobile=()=>{mobile.value=mediaQuery.matches;bannerIndex.value=0};
    updateMobile();
    mediaQuery.addEventListener?.('change',updateMobile);

    const firstPopup=(p.popups||[])[0]||null;
    if(firstPopup && Number(window.localStorage.getItem(popupKey(firstPopup))||0)<Date.now()){
        popupItem.value=firstPopup;
        popupOpen.value=true;
    }

    bannerTimer=window.setInterval(()=>{
        if(visibleBanners.value.length>1)bannerNext();
    },6500);
});
onUnmounted(()=>{
    if(bannerTimer)window.clearInterval(bannerTimer);
    if(mediaQuery)mediaQuery.onchange=null;
});
</script>

<template>
    <Head :title="customerText(&quot;pages.catalog.index.attribute.title.adae0b8b&quot;, &quot;LFAMILIA STORE&quot;)" />
    <CustomerShell :logo-url="logoUrl">
        <main>
            <section v-if="activeBanner" class="lf-container lf-home-banner-wrap">
                <div class="lf-home-banner">
                    <a v-if="activeBanner.cta_href" :href="activeBanner.cta_href" class="block">
                        <picture>
                            <source v-if="activeBanner.mobile_url" media="(max-width:639px)" :srcset="activeBanner.mobile_url">
                            <img :src="activeBanner.desktop_url||activeBanner.mobile_url" :alt="activeBanner.title||'Banner LFAMILIA STORE'">
                        </picture>
                    </a>
                    <picture v-else>
                        <source v-if="activeBanner.mobile_url" media="(max-width:639px)" :srcset="activeBanner.mobile_url">
                        <img :src="activeBanner.desktop_url||activeBanner.mobile_url" :alt="activeBanner.title||'Banner LFAMILIA STORE'">
                    </picture>
                    <template v-if="visibleBanners.length>1">
                        <button type="button" class="lf-banner-nav prev" :aria-label="customerText(&quot;pages.catalog.index.attribute.aria-label.d47450e2&quot;, &quot;Banner sebelumnya&quot;)" @click="bannerPrev">‹</button>
                        <button type="button" class="lf-banner-nav next" :aria-label="customerText(&quot;pages.catalog.index.attribute.aria-label.ebfecd89&quot;, &quot;Banner berikutnya&quot;)" @click="bannerNext">›</button>
                        <div class="lf-banner-dots">
                            <button v-for="(_,i) in visibleBanners" :key="i" type="button" :class="{active:i===bannerIndex}" :aria-label="'Banner '+(i+1)" @click="bannerIndex=i"></button>
                        </div>
                    </template>
                </div>
            </section>

            <div v-if="popupOpen&&activePopup" class="lf-home-popup-backdrop" @click.self="closePopup">
                <section class="lf-home-popup" role="dialog" aria-modal="true" :aria-label="activePopup.title">
                    <button type="button" class="lf-home-popup-close" :aria-label="customerText(&quot;pages.catalog.index.attribute.aria-label.7b2bdb3a&quot;, &quot;Tutup pop-up&quot;)" @click="closePopup">{{ customerText("pages.catalog.index.520b4356", "×") }}</button>
                    <img v-if="activePopup.image_url" :src="activePopup.image_url" :alt="activePopup.title" class="lf-home-popup-image">
                    <div class="lf-home-popup-body">
                        <h2>{{activePopup.title}}</h2>
                        <p>{{activePopup.body}}</p>
                    </div>
                    <label class="lf-home-popup-dismiss">
                        <input v-model="popupHideAgain" type="checkbox">
                        <span>{{ customerText("pages.catalog.index.a3e6a372", "Jangan tampilkan lagi") }}</span>
                    </label>
                </section>
            </div>

            <section v-if="popular.length && sectionEnabled('popular')" class="lf-container lf-section pb-2">
                <p class="lf-eyebrow">{{ customerText("pages.catalog.index.105b8350", "🔥 POPULER SEKARANG!") }}</p>
                <p class="lf-copy !mt-0">{{ customerText("pages.catalog.index.74dab8e1", "Berikut adalah beberapa produk yang paling populer saat ini.") }}</p>
                <div class="lf-popular">
                    <Link v-for="(x,i) in popular" :key="x.slug" :href="'/catalog/'+x.slug" class="lf-popular-card" :class="'tone-'+(i%8)">
                        <span class="lf-popular-art">
                            <img v-if="x.image_url" :src="x.image_url" :alt="x.name">
                            <span v-else :style="x.accent_color?{background:x.accent_color}:undefined">{{x.initials||initial(x.name)}}</span>
                        </span>
                        <span class="min-w-0"><strong>{{x.name}}</strong><small>{{x.category_name}}</small></span>
                    </Link>
                </div>
            </section>

            <section id="produk" class="lf-container lf-section">
                <div class="lf-browser-head">
                    <div>
                        <p class="lf-eyebrow">{{ customerText("pages.catalog.index.50c425f9", "OTOMATIS & MANUAL") }}</p>
                        <h1 class="lf-title">{{ customerText("pages.catalog.index.c79e09c7", "Pilih produk favoritmu") }}</h1>
                        <p class="lf-copy">{{ customerText("pages.catalog.index.37d8d356", "Top up game, voucher, hiburan, pulsa, PLN, dan produk digital langsung dari halaman utama.") }}</p>
                    </div>
                    <form class="lf-search" @submit.prevent="submit">
                        <span aria-hidden="true">⌕</span>
                        <input v-model="search" maxlength="80" :placeholder="customerText(&quot;pages.catalog.index.attribute.placeholder.6c55bcac&quot;, &quot;Cari game, voucher, pulsa, atau PLN&quot;)">
                    </form>
                </div>

                <nav class="lf-filters">
                    <Link :href="q({category:'',mode:''})" class="lf-chip" :class="{active:!filters.category&&!filters.mode}">{{ customerText("pages.catalog.index.4b93aea2", "Semua") }}</Link>
                    <Link :href="q({category:'',mode:'manual'})" class="lf-chip" :class="{active:filters.mode==='manual'}">{{ customerText("pages.catalog.index.a182b386", "Produk Manual") }}</Link>
                    <Link v-for="c in categories" :key="c.slug" :href="q({category:c.slug,mode:''})" class="lf-chip" :class="{active:filters.category===c.slug}">
                        <img v-if="c.image_url" :src="c.image_url" alt="">
                        <CategoryIcon v-else :name="c.icon" class="h-3.5 w-3.5 shrink-0" />
                        {{c.name}}
                    </Link>
                </nav>

                <div v-if="products.data.length" class="lf-products">
                    <Link v-for="x in products.data" :key="x.slug" :href="'/catalog/'+x.slug" class="lf-product">
                        <img v-if="x.image_url" :src="x.image_url" :alt="x.name">
                        <span v-else class="lf-product-fallback" :style="x.accent_color?{background:x.accent_color}:undefined"><strong>{{x.initials||x.name}}</strong><small>{{x.category_name}}</small></span>
                        <span v-if="x.instant" class="absolute right-2 top-2 z-10 rounded-full bg-black/70 px-2 py-1 text-[10px] font-semibold text-white">Instan</span>
                    </Link>
                </div>
                <p v-else class="mt-6 text-center text-sm text-white/40">{{ customerText("pages.catalog.index.3b98f425", "Produk tidak ditemukan.") }}</p>

                <nav v-if="products.links?.length>3" class="lf-pages">
                    <template v-for="x in products.links" :key="x.label">
                        <Link v-if="x.url" :href="x.url" :class="{active:x.active}">{{label(x.label)}}</Link>
                        <span v-else>{{label(x.label)}}</span>
                    </template>
                </nav>
            </section>

            <section v-if="sectionEnabled('tutorial')" class="lf-steps">
                <div class="lf-container lf-section">
                    <p class="lf-eyebrow">{{ customerText("pages.catalog.index.cc62e9c4", "CARA TOP UP") }}</p>
                    <h2 class="lf-title">{{ customerText("pages.catalog.index.f1a2a671", "Empat langkah sederhana") }}</h2>
                    <div class="lf-step-grid">
                        <article v-for="s in [['01','Pilih produk','Cari game atau produk digital yang kamu inginkan.'],['02','Isi data','Masukkan ID dan pilih nominal top up.'],['03','Bayar aman','Selesaikan pembayaran sesuai total pesanan.'],['04','Pesanan diproses','Pantau status menggunakan nomor pesanan.']]" :key="s[0]" class="lf-step">
                            <span>{{s[0]}}</span><h3>{{customerText('tutorial.' + s[0] + '.title',s[1])}}</h3><p>{{customerText('tutorial.' + s[0] + '.body',s[2])}}</p>
                        </article>
                    </div>
                </div>
            </section>

            <section v-if="sectionEnabled('news')" class="lf-news-home">
                <div class="lf-container lf-section">
                    <p class="lf-eyebrow">{{ customerText("pages.catalog.index.8422e105", "LFAMILIA NEWS") }}</p>
                    <h2 class="lf-title">{{storefront.homeNewsTitle||'LFAMILIA NEWS: INFO GAMING & UPDATE TERBARU'}}</h2>
                    <p class="lf-copy">{{storefront.homeNewsIntro||'Info gaming, promo, produk, dan pengumuman layanan.'}}</p>
                    <div class="lf-news-grid mt-5">
                        <template v-for="a in homeNews" :key="a.id">
                            <article v-if="a.is_preview" class="lf-news-card">
                                <div class="lf-news-placeholder">{{ customerText("pages.catalog.index.6ae24f17", "LF") }}</div>
                                <div class="lf-news-overlay"></div>
                                <div class="lf-news-body">
                                    <small>{{ customerText("pages.catalog.index.9c238977", "PREVIEW") }}</small>
                                    <h3>{{a.title}}</h3>
                                    <p>{{a.summary}}</p>
                                    <strong>{{a.source_label}}</strong>
                                </div>
                            </article>
                            <Link v-else :href="'/news/'+a.slug" class="lf-news-card">
                                <img v-if="a.cover_url" :src="a.cover_url" alt="">
                                <div v-else class="lf-news-placeholder">{{ customerText("pages.catalog.index.6ae24f17", "LF") }}</div>
                                <div class="lf-news-overlay"></div>
                                <div class="lf-news-body">
                                    <small>{{newsDate(a.published_at)}}</small>
                                    <h3>{{a.title}}</h3>
                                    <p>{{a.summary}}</p>
                                    <strong>{{a.source_label||'LFAMILIA News'}}</strong>
                                </div>
                            </Link>
                        </template>
                    </div>
                    <Link href="/news" class="lf-secondary mt-4">{{ customerText("pages.catalog.index.82d0793e", "Lihat Semua Artikel") }}</Link>
                </div>
            </section>

            <section v-if="reviews?.length && sectionEnabled('reviews')" class="lf-home-reviews">
                <div class="lf-container lf-section">
                    <div class="lf-home-review-head">
                        <div><p class="lf-eyebrow">{{ customerText("pages.catalog.index.edb1fc98", "ULASAN PELANGGAN") }}</p><h2 class="lf-title">{{ customerText("pages.catalog.index.a62dff1a", "Dipercaya oleh pembeli LFAMILIA") }}</h2><p class="lf-copy">{{ customerText("pages.catalog.index.d6cfd5b1", "Ulasan terverifikasi dari transaksi yang sudah berhasil.") }}</p></div>
                    </div>
                    <div class="lf-home-review-grid">
                        <article v-for="review in reviews" :key="review.id">
                            <div><strong>{{review.display_name}}</strong><span>{{'★'.repeat(review.rating)}}{{'☆'.repeat(5-review.rating)}}</span></div>
                            <p>{{review.body}}</p>
                            <Link :href="'/catalog/'+review.product_slug">{{review.product_name}}</Link>
                        </article>
                    </div>
                </div>
            </section>

        </main>
    </CustomerShell>
</template>
