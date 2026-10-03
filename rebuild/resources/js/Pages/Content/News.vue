<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import {Head,Link} from '@inertiajs/vue3';import CustomerShell from '../../Components/CustomerShell.vue';
defineProps({articles:Array});
const date=v=>v?new Date(v).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'}):'Berita LFAMILIA';
</script>
<template><Head :title="customerText('pages.content.news.attribute.title.5b8f784e', 'Berita')"/><CustomerShell><main class="lf-container lf-content-page"><p class="lf-eyebrow">{{ customerText("pages.content.news.fb34663f", "UPDATE TERBARU") }}</p><h1 class="lf-title">{{ customerText("pages.content.news.3350abb5", "Berita LFAMILIA") }}</h1><p class="lf-copy">{{ customerText("pages.content.news.de2f9a25", "Info produk, jadwal layanan, promo, dan pengumuman toko.") }}</p><div class="lf-news-grid mt-6"><Link v-for="a in articles" :key="a.id" :href="'/news/'+a.slug" class="lf-news-card"><img v-if="a.cover_url" :src="a.cover_url" alt=""><div v-else class="lf-news-placeholder">{{ customerText("pages.content.news.6ae24f17", "LF") }}</div><div class="lf-news-overlay"></div><div class="lf-news-body"><small>{{date(a.published_at)}}</small><h2>{{a.title}}</h2><p>{{a.summary}}</p><strong>{{ customerText("pages.content.news.9baf92e", "Baca artikel →") }}</strong></div></Link></div><div v-if="!articles?.length" class="lf-empty">{{ customerText("pages.content.news.c80f8d8c", "Belum ada berita aktif.") }}</div></main></CustomerShell></template>