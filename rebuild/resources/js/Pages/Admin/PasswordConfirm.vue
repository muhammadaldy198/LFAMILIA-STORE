<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const storefront = computed(() => page.props.storefront || {});
const form = useForm({ password: '' });
const submit = () => form.post('/admin/password/confirm', {
    onFinish: () => form.reset('password'),
});
</script>

<template>
    <Head title="Konfirmasi kata sandi" />
    <main class="auth-page">
        <div class="auth-box">
            <div class="auth-brand">
                <span class="auth-mark" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" /></svg>
                </span>
                <div>
                    <b>{{ storefront.storeName || 'LFAMILIA' }} ADMIN</b>
                    <small>Pengamanan tindakan admin</small>
                </div>
            </div>

            <form class="auth-card" @submit.prevent="submit">
                <div>
                    <h1>Konfirmasi kata sandi</h1>
                    <p class="auth-lead">Tindakan ini mengubah data penting. Masukkan kata sandi akun admin untuk melanjutkan.</p>
                </div>

                <label class="auth-field">
                    Kata sandi
                    <input v-model="form.password" type="password" autocomplete="current-password" required autofocus class="adm-input">
                </label>

                <p v-for="(error, key) in form.errors" :key="key" role="alert" class="auth-err">{{ error }}</p>

                <button type="submit" class="adm-btn primary auth-submit" :disabled="form.processing">
                    {{ form.processing ? 'Memeriksa…' : 'Lanjutkan' }}
                </button>
            </form>
        </div>
    </main>
</template>
