<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';
const props = defineProps({ page: Object });
const blocks = computed(() => {
    const result = [];
    for (const paragraph of String(props.page?.body || '').split(/\n\s*\n/).filter(Boolean)) {
        if (paragraph.startsWith('## ')) result.push({ type: 'heading', text: paragraph.slice(3) });
        else if (paragraph.startsWith('- ')) {
            const last = result[result.length - 1];
            if (last?.type === 'list') last.items.push(paragraph.slice(2));
            else result.push({ type: 'list', items: [paragraph.slice(2)] });
        } else result.push({ type: 'paragraph', text: paragraph });
    }
    return result;
});
</script>
<template>
<Head :title="page.title" />
<CustomerShell>
<main class="lf-container lf-content-page">
 <article class="lf-legal">
  <p class="lf-eyebrow">INFORMASI LFAMILIA</p><h1 class="lf-title">{{ page.title }}</h1>
  <p class="lf-article-summary">{{ page.intro }}</p>
  <div class="lf-prose lf-legal-content">
   <template v-for="(block, i) in blocks" :key="i">
    <h2 v-if="block.type === 'heading'">{{ block.text }}</h2>
    <ul v-else-if="block.type === 'list'"><li v-for="(item, j) in block.items" :key="j">{{ item }}</li></ul>
    <p v-else class="whitespace-pre-line">{{ block.text }}</p>
   </template>
  </div>
 </article>
</main>
</CustomerShell>
</template>
<style scoped>
.lf-legal-content h2{margin-top:28px;margin-bottom:12px}
.lf-legal-content ul{list-style:disc;padding-left:22px;margin:12px 0 22px}
.lf-legal-content li{margin:8px 0;line-height:1.7;font-weight:400}
</style>
