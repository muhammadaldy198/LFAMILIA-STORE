<script setup>
import {Head,Link} from '@inertiajs/vue3';import CustomerShell from '../../Components/CustomerShell.vue';
const props=defineProps({article:Object});const paras=String(props.article.body||'').split(/\n\s*\n/).filter(Boolean);
</script>
<template><Head :title="article.title"/><CustomerShell><main class="lf-container lf-content-page"><Link href="/news" class="lf-back">← Semua berita</Link><article class="lf-article"><img v-if="article.cover_url" :src="article.cover_url" alt="" class="lf-article-cover"><p class="lf-eyebrow">{{article.source_label||'LFAMILIA NEWS'}}</p><h1>{{article.title}}</h1><p class="lf-article-summary">{{article.summary}}</p><div class="lf-prose"><p v-for="(p,i) in paras" :key="i">{{p}}</p></div></article></main></CustomerShell></template>