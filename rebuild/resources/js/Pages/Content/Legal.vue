<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';
const props = defineProps({ page: Object });
const blocks = computed(() => {
    const result = [];
    for (const paragraph of String(props.page?.body || '').split(/\n\s*\n/).filter(Boolean)) {
        if (paragraph.startsWith('## ')) result.push({ type: 'heading', text: paragraph.slice(3), id: 'bagian-' + result.filter(block => block.type === 'heading').length });
        else if (paragraph.startsWith('- ')) {
            const last = result[result.length - 1];
            if (last?.type === 'list') last.items.push(paragraph.slice(2));
            else result.push({ type: 'list', items: [paragraph.slice(2)] });
        } else result.push({ type: 'paragraph', text: paragraph });
    }
    return result;
});
const contents = computed(() => blocks.value.filter(block => block.type === 'heading' && /^\d+\./.test(block.text)));
const headingNumber = text => text.match(/^(\d+)\./)?.[1] || '';
const headingTitle = text => text.replace(/^\d+\.\s*/, '');
</script>
<template>
<Head :title="page.title" />
<CustomerShell>
<main class="lf-container lf-content-page lf-legal-page">
 <Link href="/" class="lf-legal-back">← {{ customerText("legal.back", "Kembali ke beranda") }}</Link>
 <header class="lf-legal-header">
  <p class="lf-eyebrow">{{ customerText("legal.documentLabel", "Dokumen hukum") }}</p>
  <h1 class="lf-title">{{ page.title }}</h1>
  <p class="lf-article-summary">{{ page.intro }}</p>
 </header>
 <div class="lf-legal-layout">
  <article class="lf-legal lf-prose lf-legal-content">
   <template v-for="(block, i) in blocks" :key="i">
    <h2 v-if="block.type === 'heading'" :id="block.id" class="lf-legal-heading"><span v-if="headingNumber(block.text)" class="lf-legal-number">{{ headingNumber(block.text) }}</span><span>{{ headingTitle(block.text) }}</span></h2>
    <ul v-else-if="block.type === 'list'"><li v-for="(item, j) in block.items" :key="j">{{ item }}</li></ul>
    <p v-else class="whitespace-pre-line">{{ block.text }}</p>
   </template>
  </article>
  <aside class="lf-legal-sidebar">
   <nav class="lf-legal-toc" :aria-label="customerText('legal.contents', 'Di halaman ini')">
    <h2>{{ customerText("legal.contents", "Di halaman ini") }}</h2>
    <a v-for="section in contents" :key="section.id" :href="'#' + section.id"><span>{{ headingNumber(section.text) }}</span><span>{{ headingTitle(section.text) }}</span></a>
   </nav>
   <section class="lf-legal-help">
    <h2>{{ customerText("legal.helpTitle", "Masih ada pertanyaan?") }}</h2>
    <p>{{ customerText("legal.helpBody", "Hubungi tim bantuan melalui kanal resmi LFAMILIA.") }}</p>
    <Link href="/contact" class="lf-primary">{{ customerText("legal.helpAction", "Hubungi kami") }}</Link>
   </section>
  </aside>
 </div>
</main>
</CustomerShell>
</template>
<style scoped>
.lf-legal-back{display:inline-flex;gap:8px;margin-bottom:24px;color:#a3aab8;font-weight:500}
.lf-legal-header{max-width:850px;margin-bottom:28px}
.lf-legal-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:32px;align-items:start}
.lf-legal-content{min-width:0}
.lf-legal-content h2{margin-top:28px;margin-bottom:12px;scroll-margin-top:100px}
.lf-legal-heading{display:flex;align-items:baseline;gap:12px}
.lf-legal-number{color:#a3e635;font-size:.8em;font-weight:700;flex:none}
.lf-legal-content ul{list-style:disc;padding-left:22px;margin:12px 0 22px}
.lf-legal-content li{margin:8px 0;line-height:1.7}
.lf-legal-sidebar{position:sticky;top:96px;display:grid;gap:20px;min-width:0}
.lf-legal-toc,.lf-legal-help{padding:20px;background:#0b111b;border:1px solid #233044;border-radius:8px}
.lf-legal-toc h2,.lf-legal-help h2{margin:0 0 16px;font-size:18px;font-weight:700}
.lf-legal-toc a{display:flex;gap:12px;align-items:baseline;padding:8px 0;font-weight:500;line-height:1.5;color:#c8d1de}
.lf-legal-toc a span:first-child{color:#a3e635;flex:none}
.lf-legal-toc a:hover{color:#a3e635}
.lf-legal-help p{margin-bottom:16px;line-height:1.7}
.lf-legal-help .lf-primary{display:flex;width:100%;justify-content:center}
@media(max-width:900px){.lf-legal-layout{grid-template-columns:minmax(0,1fr);gap:24px}.lf-legal-sidebar{position:static}.lf-legal-toc h2,.lf-legal-help h2{font-size:16px}}
</style>
