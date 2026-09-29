<script setup>
import { Head, router } from '@inertiajs/vue3';

defineProps({ admin: Object });
</script>

<template>
    <Head title="Panel Admin" />
    <main class="min-h-screen bg-slate-950 px-6 py-16 text-slate-100">
        <div class="mx-auto max-w-2xl space-y-5">
            <h1 class="text-3xl font-semibold">Panel Admin</h1>
            <p>{{ admin.name }} · {{ admin.role }}</p>
            <p>Fitur operasional panel dibangun pada M9.</p>
            <button type="button" class="rounded-md bg-slate-700 p-3 focus:outline-cyan-300" @click="router.post('/admin/logout')">Keluar</button>
        </div>
    </main>
</template>
