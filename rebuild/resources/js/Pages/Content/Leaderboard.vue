<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SeoHead from '../../Components/SeoHead.js';
import CustomerShell from '../../Components/CustomerShell.vue';
import { useCustomerPresentation } from '../../Composables/customerPresentation';

const { customerText } = useCustomerPresentation();

const props = defineProps({ entries: Array, period: String });

const money = v => 'Rp' + Number(v || 0).toLocaleString('id-ID');

// Podium hanya memakai entry asli rank 1-3; tidak ada entry pengisi.
// Urutan DOM tetap 1-2-3 (pembaca layar), urutan visual 2-1-3 diatur CSS.
const podium = computed(() => (props.entries || []).filter(e => e.rank <= 3));
const rest = computed(() => (props.entries || []).filter(e => e.rank > 3));
const initial = name => String(name || '?').charAt(0).toUpperCase();
</script>

<template>
    <SeoHead />
    <CustomerShell>
        <main class="lf-container lf-content-page">
            <p class="lf-eyebrow">{{ customerText("pages.content.leaderboard.33d93ba7", "KOMUNITAS LFAMILIA") }}</p>
            <h1 class="lf-title">{{ customerText("pages.content.leaderboard.d54d76bb", "Leaderboard pelanggan") }}</h1>
            <p class="lf-copy">{{ customerText("pages.content.leaderboard.9693f087", "Peringkat memakai alias anonim; email, telepon, dan data game tidak ditampilkan.") }}</p>
            <div class="lf-period-tabs">
                <Link href="/leaderboard?period=month" :class="{ active: period === 'month' }">{{ customerText("pages.content.leaderboard.43e591f9", "Bulan ini") }}</Link>
                <Link href="/leaderboard?period=all" :class="{ active: period === 'all' }">{{ customerText("pages.content.leaderboard.c8930ae0", "Sepanjang waktu") }}</Link>
            </div>

            <ol v-if="podium.length" class="lf-podium">
                <li v-for="e in podium" :key="e.rank" :class="['lf-podium-col', 'is-rank-' + e.rank]">
                    <div class="lf-podium-avatar">
                        <svg v-if="e.rank === 1" class="lf-podium-crown" viewBox="0 0 24 16" aria-hidden="true"><path d="M2 14 0 3l6.5 4.5L12 0l5.5 7.5L24 3l-2 11z" /></svg>
                        <span aria-hidden="true">{{ initial(e.name) }}</span>
                    </div>
                    <strong class="lf-podium-name" :title="e.name">{{ e.name }}</strong>
                    <span class="lf-podium-total">{{ money(e.total_spent) }}</span>
                    <small class="lf-podium-count">{{ e.order_count }} transaksi berhasil</small>
                    <div class="lf-podium-step"><b>{{ e.rank }}</b></div>
                </li>
            </ol>

            <div v-if="rest.length || !entries?.length" class="lf-ranking">
                <article v-for="e in rest" :key="e.rank">
                    <b>{{ e.rank }}</b>
                    <div><strong>{{ e.name }}</strong><small>{{ e.order_count }} transaksi berhasil</small></div>
                    <span>{{ money(e.total_spent) }}</span>
                </article>
                <div v-if="!entries?.length" class="lf-empty">{{ customerText("pages.content.leaderboard.8aacad7d", "Belum ada peringkat.") }}</div>
            </div>
        </main>
    </CustomerShell>
</template>
