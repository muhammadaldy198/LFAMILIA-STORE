<script setup>
import { Head, Link } from '@inertiajs/vue3';
import CustomerShell from '../../Components/CustomerShell.vue';

const tools = [
    { href:'/tools/win-rate', label:'Kalkulator Win Rate', text:'Hitung jumlah kemenangan untuk mencapai target WR.', icon:'WR' },
    { href:'/tools/zodiac', label:'Kalkulator Zodiac', text:'Estimasi summon dan diamond menuju 100 poin.', icon:'Z' },
    { href:'/tools/magic-wheel', label:'Kalkulator Magic Wheel', text:'Hitung draw dan diamond menuju 200 Magic Point.', icon:'MW' },
];
</script>

<template>
<Head title="LFAMILIA Tools"/>
<CustomerShell>
<main class="lf-tools-index">
    <section class="lf-container">
        <div class="lf-tools-index-copy">
            <p class="lf-eyebrow">LFAMILIA Tools</p>
            <h1 class="lf-title">Alat bantu untuk para gamer.</h1>
            <p class="lf-copy">Hitung kebutuhan game langsung dari satu tempat.</p>
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
