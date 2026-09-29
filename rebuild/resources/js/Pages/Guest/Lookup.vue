<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const turnstile = ref(null);
const security = computed(() => page.props.security || {});
const form = useForm({ order_number: '', access_code: '', turnstile_token: '' });
const submit = () => form.post('/orders/check', { onFinish: () => turnstile.value?.reset() });
</script>

<template>
    <Head title="Cek pesanan" />
    <main class="min-h-screen bg-slate-950 px-4 py-16 text-slate-100">
        <div class="mx-auto max-w-md space-y-5">
            <Link href="/" class="text-cyan-300">← LFAMILIA STORE</Link>
            <h1 class="text-3xl font-semibold">Cek pesanan guest</h1>
            <p class="text-sm text-slate-400">Masukkan nomor pesanan dan kode akses yang diberikan saat checkout.</p>
            <form class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5" @submit.prevent="submit">
                <label class="block">Nomor pesanan
                    <input v-model="form.order_number" required maxlength="80" class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                    <span v-if="form.errors.order_number" class="text-sm text-red-300">{{ form.errors.order_number }}</span>
                </label>
                <label class="block">Kode akses
                    <input v-model="form.access_code" required maxlength="64" autocomplete="off" class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                    <span v-if="form.errors.access_code" class="text-sm text-red-300">{{ form.errors.access_code }}</span>
                </label>
                <TurnstileWidget
                    v-if="security.turnstile_required"
                    ref="turnstile"
                    :site-key="security.turnstile_site_key"
                    :action="security.turnstile_action"
                    @token="form.turnstile_token = $event"
                />
                <span v-if="form.errors.turnstile_token" class="text-sm text-red-300">{{ form.errors.turnstile_token }}</span>
                <button :disabled="form.processing" class="w-full rounded-md bg-cyan-400 p-3 font-semibold text-slate-950 disabled:opacity-50">Periksa</button>
            </form>
        </div>
    </main>
</template>
