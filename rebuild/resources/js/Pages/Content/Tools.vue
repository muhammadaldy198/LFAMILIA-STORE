<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head, Link } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';

const tools = [
    { href:'/tools/win-rate', label:'Kalkulator Win Rate', text:'Hitung jumlah kemenangan untuk mencapai target WR.', icon:'WR' },
    { href:'/tools/zodiac', label:'Kalkulator Zodiac', text:'Estimasi summon dan diamond menuju 100 poin.', icon:'Z' },
    { href:'/tools/magic-wheel', label:'Kalkulator Magic Wheel', text:'Hitung draw dan diamond menuju 200 Magic Point.', icon:'MW' },
];
</script>

<template>
<Head :title="customerText('pages.content.tools.attribute.title.57d99b55', 'LFAMILIA Tools')"/>
<CustomerShell>
<main class="lf-tools-index">
    <section class="lf-container">
        <div class="lf-tools-index-copy">
            <p class="lf-eyebrow">{{ customerText("pages.content.tools.57d99b55", "LFAMILIA Tools") }}</p>
            <h1 class="lf-title">{{ customerText("pages.content.tools.69c38ec2", "Alat bantu untuk para gamer.") }}</h1>
            <p class="lf-copy">{{ customerText("pages.content.tools.81a7f514", "Hitung kebutuhan game langsung dari satu tempat.") }}</p>
        </div>

        <div class="lf-tools-index-grid">
            <Link v-for="tool in tools" :key="tool.href" :href="tool.href" class="lf-tools-index-card">
                <div class="lf-tools-index-card-head">
                    <span class="lf-tools-index-icon">{{tool.icon}}</span>
                    <span class="lf-tools-index-arrow">→</span>
                </div>
                <h2>{{tool.label}}</h2>
                <p>{{tool.text}}</p>
            </Link>
        </div>
    </section>
</main>
</CustomerShell>
</template>
