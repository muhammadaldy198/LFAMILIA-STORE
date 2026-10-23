<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const security = computed(() => page.props.security || {});
const storefront = computed(() => page.props.storefront || {});
const turnstile = ref(null);
const form = useForm({ email: '', turnstile_token: '' });

const submit = () => {
    if (form.processing) return;
    form.post('/admin/forgot-password', {
        onFinish: () => {
            form.turnstile_token = '';
            turnstile.value?.reset();
        },
    });
};
</script>

<template>
    <Head title="Lupa kata sandi admin" />
    <main class="auth-page">
        <div class="auth-box">
            <div class="auth-brand">
                <span class="auth-mark" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" /></svg>
                </span>
                <div>
                    <b>{{ storefront.storeName || 'LFAMILIA' }} ADMIN</b>
                    <small>Pemulihan akses admin</small>
                </div>
            </div>

            <form class="auth-card" @submit.prevent="submit">
                <div>
                    <h1>Pulihkan kata sandi</h1>
                    <p class="auth-lead">Masukkan email admin yang terdaftar. Bila akunnya aktif, kami kirimkan tautan untuk membuat kata sandi baru yang berlaku 30 menit.</p>
                </div>

                <p v-if="page.props.status" role="status" class="auth-note">{{ page.props.status }}</p>

                <label class="auth-field">
                    Email admin
                    <input v-model="form.email" name="email" type="email" autocomplete="email" required placeholder="email@contoh.com" class="adm-input">
                    <span v-if="form.errors.email" role="alert" class="auth-err">{{ form.errors.email }}</span>
                </label>

                <div v-if="security.turnstile_required" class="auth-field">
                    <span style="font-weight:400;color:var(--a-muted)">Pemeriksaan keamanan</span>
                    <TurnstileWidget ref="turnstile" :site-key="security.turnstile_site_key" :action="security.turnstile_action" @token="form.turnstile_token = $event" />
                    <span v-if="form.errors.turnstile_token" role="alert" class="auth-err">{{ form.errors.turnstile_token }}</span>
                </div>

                <button type="submit" class="adm-btn primary auth-submit" :disabled="form.processing">
                    {{ form.processing ? 'Mengirim…' : 'Kirim tautan pemulihan' }}
                </button>

                <p class="auth-alt"><Link href="/admin/login">Kembali ke halaman masuk</Link></p>
            </form>
        </div>
    </main>
</template>
