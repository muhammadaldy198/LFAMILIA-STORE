<script setup>
import {Head,Link,router,usePage} from '@inertiajs/vue3';
import {computed,ref} from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';

const p=defineProps({
    categories:Array,products:Object,filters:Object,logoUrl:String,bannerUrl:String,
    mobileBannerUrl:String,popupUrl:String,faviconUrl:String,bannerTarget:String,news:Array,
});
const page=usePage();
const storefront=computed(()=>page.props.storefront||{});
const pop=ref(true);
const search=ref(p.filters.q??'');
const popular=computed(()=>(p.products?.data??[]).slice(0,8));
const q=(x={})=>{const a={...p.filters,...x};Object.keys(a).forEach(k=>{if(!a[k])delete a[k]});const s=new URLSearchParams(a).toString();return s?'/?'+s:'/'};
const submit=()=>router.get('/',{...p.filters,q:search.value},{preserveState:true,preserveScroll:true});
const initial=n=>(n||'L').slice(0,1).toUpperCase();
const label=l=>String(l).includes('Previous')?'‹':String(l).includes('Next')?'›':String(l).replace(/&laquo;|&raquo;/g,'').trim();
const newsDate=v=>v?new Date(v).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'}):'LFAMILIA News';
</script>

<template>
    <Head title="LFAMILIA STORE" />
    <CustomerShell :logo-url="logoUrl">
        <main>
            <section v-if="bannerUrl||mobileBannerUrl" class="lf-home-banner">
                <a v-if="bannerTarget" :href="bannerTarget" class="block">
                    <picture>
                        <source v-if="mobileBannerUrl" media="(max-width:640px)" :srcset="mobileBannerUrl">
                        <img :src="bannerUrl||mobileBannerUrl" alt="Banner LFAMILIA STORE">
                    </picture>
                </a>
                <picture v-else>
                    <source v-if="mobileBannerUrl" media="(max-width:640px)" :srcset="mobileBannerUrl">
                    <img :src="bannerUrl||mobileBannerUrl" alt="Banner LFAMILIA STORE">
                </picture>
            </section>

            <div v-if="popupUrl&&pop" class="fixed inset-0 z-[100] grid place-items-center bg-black/75 p-4">
                <div class="max-w-lg rounded-xl border border-white/10 bg-[#070a10] p-2">
                    <button class="lf-secondary mb-2" @click="pop=false">Tutup</button>
                    <img :src="popupUrl" class="max-h-[70vh] w-full object-contain">
                </div>
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
                        <input v-model="search" maxlength="80" placeholder="Cari game, voucher, pulsa, PLN...">
                    </form>
                </div>

                <nav class="lf-filters">
                    <Link :href="q({category:'',mode:''})" class="lf-chip" :class="{active:!filters.category}">Semua</Link>
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

            <section v-if="news?.length" class="lf-news-home">
                <div class="lf-container lf-section">
                    <p class="lf-eyebrow">LFAMILIA NEWS</p>
                    <h2 class="lf-title">{{storefront.homeNewsTitle||'LFAMILIA NEWS: INFO GAMING & UPDATE TERBARU'}}</h2>
                    <p class="lf-copy">{{storefront.homeNewsIntro||'Info gaming, promo, produk, dan pengumuman layanan.'}}</p>
                    <div class="lf-news-grid mt-5">
                        <Link v-for="a in news" :key="a.id" :href="'/news/'+a.slug" class="lf-news-card">
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
                    </div>
                    <Link href="/news" class="lf-secondary mt-4">Lihat Semua Artikel</Link>
                </div>
            </section>

            <section class="lf-container lf-section">
                <div class="lf-help">
                    <div><p class="lf-eyebrow">BUTUH BANTUAN?</p><h2 class="m-0 text-[22px] font-black">Tim LFAMILIA siap membantu.</h2><p class="lf-copy">Sampaikan pertanyaan mengenai produk, pembayaran, atau status pesanan.</p></div>
                    <Link href="/contact" class="lf-primary">Layanan Pelanggan</Link>
                </div>
            </section>
        </main>
    </CustomerShell>
</template>
