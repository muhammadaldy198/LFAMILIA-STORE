<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';

const props = defineProps({
    services: { type: Array, default: () => [] },
    merchant: { type: Object, default: () => ({}) },
    updatedAt: String,
});

const allOperational = computed(() => props.services.every((item) => item.state === 'operational'));

function formatDate(value) {
    if (!value) return '';
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}
</script>

<template>
<Head title="Status Layanan" />
<CustomerShell>
<main class="lf-container min-h-[70vh] py-10 sm:py-14">
    <p class="lf-eyebrow">TRANSPARANSI LAYANAN</p>
    <h1 class="lf-title">Status layanan LFAMILIA</h1>
    <p class="lf-copy max-w-2xl">Lihat kondisi katalog, pembayaran, pemrosesan pesanan, dan layanan pelanggan sebelum atau sesudah bertransaksi.</p>

    <section class="mt-8 flex items-center justify-between gap-4 rounded-xl border p-4" :class="allOperational ? 'border-emerald-400/25 bg-emerald-400/[0.06]' : 'border-amber-300/25 bg-amber-300/[0.06]'">
        <div>
            <strong class="text-sm">{{allOperational ? 'Seluruh layanan beroperasi normal' : 'Sebagian layanan sedang ditinjau'}}</strong>
            <p class="mt-1 text-xs text-white/45">Status diambil dari pemeriksaan aplikasi saat halaman dimuat.</p>
        </div>
        <button class="lf-secondary shrink-0" type="button" @click="router.reload({ preserveScroll: true })">Perbarui</button>
    </section>

    <section class="mt-4 grid gap-3 sm:grid-cols-2">
        <article v-for="service in services" :key="service.id" class="rounded-xl border border-white/[0.08] bg-white/[0.03] p-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-bold">{{service.name}}</h2>
                <span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide" :class="service.state==='operational' ? 'bg-emerald-300/15 text-emerald-200' : 'bg-amber-300/15 text-amber-200'">{{service.state==='operational' ? 'Normal' : 'Ditinjau'}}</span>
            </div>
            <p class="mt-3 text-sm leading-6 text-white/45">{{service.detail}}</p>
        </article>
    </section>

    <section v-if="merchant.legalName || merchant.registrationId || merchant.address" class="mt-4 rounded-xl border border-white/[0.08] bg-white/[0.03] p-5">
        <h2 class="font-bold">Identitas merchant</h2>
        <div class="mt-3 grid gap-2 text-sm text-white/45 sm:grid-cols-3">
            <p v-if="merchant.legalName">{{merchant.legalName}}</p>
            <p v-if="merchant.registrationId">Nomor usaha: {{merchant.registrationId}}</p>
            <p v-if="merchant.address">{{merchant.address}}</p>
        </div>
    </section>

    <p class="mt-5 text-center text-xs text-white/30">Pembaruan terakhir: {{formatDate(updatedAt)}}</p>
</main>
</CustomerShell>
</template>
