<script setup>
import {Head,Link,router,usePage} from '@inertiajs/vue3';
import {computed,onMounted,onUnmounted,ref} from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';

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
    <Head title="LFAMILIA STORE" />
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
                        <button type="button" class="lf-banner-nav prev" aria-label="Banner sebelumnya" @click="bannerPrev">‹</button>
                        <button type="button" class="lf-banner-nav next" aria-label="Banner berikutnya" @click="bannerNext">›</button>
                        <div class="lf-banner-dots">
                            <button v-for="(_,i) in visibleBanners" :key="i" type="button" :class="{active:i===bannerIndex}" :aria-label="'Banner '+(i+1)" @click="bannerIndex=i"></button>
                        </div>
                    </template>
                </div>
            </section>

            <div v-if="popupOpen&&activePopup" class="lf-home-popup-backdrop" @click.self="closePopup">
                <section class="lf-home-popup" role="dialog" aria-modal="true" :aria-label="activePopup.title">
                    <button type="button" class="lf-home-popup-close" aria-label="Tutup pop-up" @click="closePopup">×</button>
                    <img v-if="activePopup.image_url" :src="activePopup.image_url" :alt="activePopup.title" class="lf-home-popup-image">
                    <div class="lf-home-popup-body">
                        <h2>{{activePopup.title}}</h2>
                        <p>{{activePopup.body}}</p>
                    </div>
                    <label class="lf-home-popup-dismiss">
                        <input v-model="popupHideAgain" type="checkbox">
                        <span>Jangan tampilkan lagi</span>
                    </label>
                </section>
            </div>

            <section v-if="popular.length" class="lf-container lf-section pb-2">
                <p class="lf-eyebrow">🔥 POPULER SEKARANG!</p>
                <p class="lf-copy !mt-0">Berikut adalah beberapa produk yang paling populer saat ini.</p>
                <div class="lf-popular">
                    <Link v-for="(x,i) in popular" :key="x.slug" :href="'/catalog/'+x.slug" class="lf-popular-card" :class="'tone-'+(i%8)">
                        <span class="lf-popular-art">
                            <img v-if="x.image_url" :src="x.image_url" :alt="x.name">
                            <span v-else>{{initial(x.name)}}</span>
                        </span>
                        <span class="min-w-0"><strong>{{x.name}}</strong><small>{{x.category_name}}</small></span>
                    </Link>
                </div>
            </section>

            <section id="produk" class="lf-container lf-section">
                <div class="lf-browser-head">
                    <div>
                        <p class="lf-eyebrow">OTOMATIS & MANUAL</p>
                        <h1 class="lf-title">Pilih produk favoritmu</h1>
                        <p class="lf-copy">Top up game, voucher, hiburan, pulsa, PLN, dan produk digital langsung dari halaman utama.</p>
                    </div>
                    <form class="lf-search" @submit.prevent="submit">
                        <span aria-hidden="true">⌕</span>
                        <input v-model="search" maxlength="80" placeholder="Cari game, voucher, pulsa, atau PLN">
                    </form>
                </div>

                <nav class="lf-filters">
                    <Link :href="q({category:'',mode:''})" class="lf-chip" :class="{active:!filters.category&&!filters.mode}">Semua</Link>
                    <Link :href="q({category:'',mode:'manual'})" class="lf-chip" :class="{active:filters.mode==='manual'}">Produk Manual</Link>
                    <Link v-for="c in categories" :key="c.slug" :href="q({category:c.slug,mode:''})" class="lf-chip" :class="{active:filters.category===c.slug}">
                        <img v-if="c.image_url" :src="c.image_url" alt="">{{c.name}}
                    </Link>
                </nav>

                <div v-if="products.data.length" class="lf-products">
                    <Link v-for="x in products.data" :key="x.slug" :href="'/catalog/'+x.slug" class="lf-product">
                        <img v-if="x.image_url" :src="x.image_url" :alt="x.name">
                        <span v-else class="lf-product-fallback"><strong>{{x.name}}</strong><small>{{x.category_name}}</small></span>
                    </Link>
                </div>
                <p v-else class="mt-6 text-center text-sm text-white/40">Produk tidak ditemukan.</p>

                <nav v-if="products.links?.length>3" class="lf-pages">
                    <template v-for="x in products.links" :key="x.label">
                        <Link v-if="x.url" :href="x.url" :class="{active:x.active}">{{label(x.label)}}</Link>
                        <span v-else>{{label(x.label)}}</span>
                    </template>
                </nav>
            </section>

            <section class="lf-steps">
                <div class="lf-container lf-section">
                    <p class="lf-eyebrow">CARA TOP UP</p>
                    <h2 class="lf-title">Empat langkah sederhana</h2>
                    <div class="lf-step-grid">
                        <article v-for="s in [['01','Pilih produk','Cari game atau produk digital yang kamu inginkan.'],['02','Isi data','Masukkan ID dan pilih nominal top up.'],['03','Bayar aman','Selesaikan pembayaran sesuai total pesanan.'],['04','Pesanan diproses','Pantau status menggunakan nomor pesanan.']]" :key="s[0]" class="lf-step">
                            <span>{{s[0]}}</span><h3>{{s[1]}}</h3><p>{{s[2]}}</p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="lf-news-home">
                <div class="lf-container lf-section">
                    <p class="lf-eyebrow">LFAMILIA NEWS</p>
                    <h2 class="lf-title">{{storefront.homeNewsTitle||'LFAMILIA NEWS: INFO GAMING & UPDATE TERBARU'}}</h2>
                    <p class="lf-copy">{{storefront.homeNewsIntro||'Info gaming, promo, produk, dan pengumuman layanan.'}}</p>
                    <div class="lf-news-grid mt-5">
                        <template v-for="a in homeNews" :key="a.id">
                            <article v-if="a.is_preview" class="lf-news-card">
                                <div class="lf-news-placeholder">LF</div>
                                <div class="lf-news-overlay"></div>
                                <div class="lf-news-body">
                                    <small>PREVIEW</small>
                                    <h3>{{a.title}}</h3>
                                    <p>{{a.summary}}</p>
                                    <strong>{{a.source_label}}</strong>
                                </div>
                            </article>
                            <Link v-else :href="'/news/'+a.slug" class="lf-news-card">
                                <img v-if="a.cover_url" :src="a.cover_url" alt="">
                                <div v-else class="lf-news-placeholder">LF</div>
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
                    <Link href="/news" class="lf-secondary mt-4">Lihat Semua Artikel</Link>
                </div>
            </section>

            <section v-if="reviews?.length" class="lf-home-reviews">
                <div class="lf-container lf-section">
                    <div class="lf-home-review-head">
                        <div><p class="lf-eyebrow">ULASAN PELANGGAN</p><h2 class="lf-title">Dipercaya oleh pembeli LFAMILIA</h2><p class="lf-copy">Ulasan terverifikasi dari transaksi yang sudah berhasil.</p></div>
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
