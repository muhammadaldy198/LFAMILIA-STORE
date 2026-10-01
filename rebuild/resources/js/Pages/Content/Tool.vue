<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import {Head,Link} from '@inertiajs/vue3';
import {computed,ref} from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';
const props=defineProps({tool:String});
const played=ref(100),current=ref(50),desired=ref(60);
const zodiacPoints=ref(40),zodiacCost=ref(20),zodiacAvg=ref(2);
const magicPoints=ref(80),magicSingle=ref(60),magicFive=ref(270);
const tools=[
 {key:'win-rate',name:'Win Rate',desc:'Hitung kemenangan beruntun untuk mencapai target win rate.',icon:'WR'},
 {key:'zodiac',name:'Zodiac',desc:'Perkirakan summon dan diamond menuju 100 poin Zodiac.',icon:'Z'},
 {key:'magic-wheel',name:'Magic Wheel',desc:'Cari kombinasi draw hemat menuju 200 Magic Point.',icon:'MW'},
];
const active=computed(()=>tools.find(x=>x.key===props.tool)||tools[0]);
const winNeeded=computed(()=>{const p=Math.max(0,Number(played.value)||0),c=Math.min(100,Math.max(0,Number(current.value)||0))/100,d=Math.min(99.99,Math.max(0,Number(desired.value)||0))/100;if(!p||c>=d)return 0;return Math.max(0,Math.ceil(((d-c)*p)/(1-d)))});
const zodiac=computed(()=>{const points=Math.min(100,Math.max(0,Number(zodiacPoints.value)||0)),remaining=100-points,per=Math.min(5,Math.max(1,Number(zodiacAvg.value)||1)),cost=Math.max(0,Number(zodiacCost.value)||0);return{remaining,draws:Math.ceil(remaining/per),diamonds:Math.ceil(remaining/per)*cost,best:Math.ceil(remaining/5)*cost,worst:remaining*cost}});
const magic=computed(()=>{const points=Math.min(200,Math.max(0,Number(magicPoints.value)||0)),remaining=200-points,single=Math.max(0,Number(magicSingle.value)||0),five=Math.max(0,Number(magicFive.value)||0);let best=Infinity,sets=0,singles=0;for(let s=0;s<=Math.ceil(remaining/5);s++){const one=Math.max(0,remaining-s*5),total=s*five+one*single;if(total<best){best=total;sets=s;singles=one}}return{remaining,best:Number.isFinite(best)?best:0,sets,singles}});
</script>
<template>
<Head :title="active.name+' Calculator'"/>
<CustomerShell>
<main class="lf-tools-page">
 <section class="lf-tools-hero"><div class="lf-container"><p class="lf-eyebrow">{{ customerText("pages.content.tool.4b6166cb", "TOOLS LFAMILIA") }}</p><h1>{{ customerText("pages.content.tool.10394bed", "Kalkulator Game") }}</h1><p>{{ customerText("pages.content.tool.5ccd5c20", "Hitung kebutuhan game dengan rumus yang sama seperti tools LFAMILIA sebelumnya.") }}</p></div></section>
 <div class="lf-container lf-tools-layout">
  <aside class="lf-tools-nav">
   <p>{{ customerText("pages.content.tool.e2b6ac16", "Semua Kalkulator") }}</p>
   <Link v-for="t in tools" :key="t.key" :href="'/tools/'+t.key" :class="{active:t.key===tool}"><span>{{t.icon}}</span><div><strong>{{t.name}}</strong><small>{{t.desc}}</small></div></Link>
  </aside>

  <section class="lf-calculator">
   <header><span>{{active.icon}}</span><div><h2>{{active.name}}</h2><p>{{active.desc}}</p></div></header>

   <template v-if="tool==='win-rate'">
    <div class="lf-calc-fields">
     <label>{{ customerText("pages.content.tool.f03aca72", "Total Pertandingan") }}<input v-model.number="played" type="number" min="0"><small>{{ customerText("pages.content.tool.ed79a277", "Jumlah match yang sudah dimainkan.") }}</small></label>
     <label>{{ customerText("pages.content.tool.d617b96f", "Win Rate Sekarang (%)") }}<input v-model.number="current" type="number" min="0" max="100" step=".01"></label>
     <label>{{ customerText("pages.content.tool.a30d035a", "Target Win Rate (%)") }}<input v-model.number="desired" type="number" min="0" max="99.99" step=".01"></label>
    </div>
    <div class="lf-calc-result"><small>{{ customerText("pages.content.tool.7428ed33", "Kemenangan beruntun yang dibutuhkan") }}</small><strong>{{winNeeded}} Win</strong><p>Perkiraan total pertandingan setelah mencapai target: {{Number(played||0)+winNeeded}} match.</p></div>
   </template>

   <template v-else-if="tool==='zodiac'">
    <div class="lf-calc-fields">
     <label>{{ customerText("pages.content.tool.4e4e8f7d", "Poin Zodiac Sekarang") }}<input v-model.number="zodiacPoints" type="number" min="0" max="100"></label>
     <label>{{ customerText("pages.content.tool.b6f90e0f", "Diamond per Summon") }}<input v-model.number="zodiacCost" type="number" min="0"></label>
     <label>{{ customerText("pages.content.tool.8e98b0e6", "Rata-rata Poin / Summon") }}<input v-model.number="zodiacAvg" type="number" min="1" max="5" step=".1"><small>{{ customerText("pages.content.tool.49d859fe", "Gunakan 1–5 sesuai estimasi kamu.") }}</small></label>
    </div>
    <div class="lf-calc-result-grid"><div><small>{{ customerText("pages.content.tool.3096b639", "Sisa poin") }}</small><strong>{{zodiac.remaining}}</strong></div><div><small>{{ customerText("pages.content.tool.e2b2d1a7", "Estimasi summon") }}</small><strong>{{zodiac.draws}}</strong></div><div><small>{{ customerText("pages.content.tool.d72c86f0", "Estimasi diamond") }}</small><strong>{{zodiac.diamonds.toLocaleString('id-ID')}}</strong></div></div>
    <div class="lf-calc-range"><span>{{ customerText("pages.content.tool.9cd1fd1", "Best case:") }} <b>{{zodiac.best.toLocaleString('id-ID')}} diamond</b></span><span>{{ customerText("pages.content.tool.7aadb11e", "Worst case:") }} <b>{{zodiac.worst.toLocaleString('id-ID')}} diamond</b></span></div>
   </template>

   <template v-else>
    <div class="lf-calc-fields">
     <label>{{ customerText("pages.content.tool.8a862850", "Magic Point Sekarang") }}<input v-model.number="magicPoints" type="number" min="0" max="200"></label>
     <label>{{ customerText("pages.content.tool.c11c5f7b", "Harga 1 Draw") }}<input v-model.number="magicSingle" type="number" min="0"></label>
     <label>{{ customerText("pages.content.tool.6a4010b7", "Harga 5 Draw") }}<input v-model.number="magicFive" type="number" min="0"></label>
    </div>
    <div class="lf-calc-result"><small>{{ customerText("pages.content.tool.8c1c864f", "Estimasi kombinasi termurah") }}</small><strong>{{magic.best.toLocaleString('id-ID')}} Diamond</strong><p>{{magic.sets}}× paket 5 draw + {{magic.singles}}× single draw untuk menutup {{magic.remaining}} Magic Point.</p></div>
   </template>

   <p class="lf-calc-note">{{ customerText("pages.content.tool.b9cda763", "Kalkulator ini hanya alat bantu estimasi. Harga, event, bonus, dan mekanisme game dapat berubah.") }}</p>
  </section>
 </div>
</main>
</CustomerShell>
</template>
