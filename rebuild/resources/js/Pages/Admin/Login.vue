<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const turnstile = ref(null);
const security = computed(() => page.props.security || {});
const storefront = computed(() => page.props.storefront || {});
const form = useForm({ email: '', password: '', turnstile_token: '' });

const submit = () => {
    if (form.processing) return;
    form.post('/admin/login', {
        onFinish: () => {
            form.reset('password');
            form.turnstile_token = '';
            turnstile.value?.reset();
        },
    });
};
</script>

<template>
    <Head title="Masuk admin" />
    <main class="auth-page">
        <div class="auth-box">
            <div class="auth-brand">
                <span class="auth-mark" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" /></svg>
                </span>
                <div>
                    <b>{{ storefront.storeName || 'LFAMILIA' }} ADMIN</b>
                    <small>{{ storefront.tagline || 'Top Up Game Solution' }}</small>
                </div>
            </div>

            <form class="auth-card" @submit.prevent="submit">
                <div>
                    <h1>Masuk ke panel admin</h1>
                    <p class="auth-lead">Gunakan akun admin yang sudah didaftarkan oleh Super Admin toko.</p>
                </div>

                <p v-if="page.props.status" role="status" class="auth-note">{{ page.props.status }}</p>

                <label class="auth-field">
                    Email
                    <input v-model="form.email" name="email" type="email" autocomplete="username" required placeholder="email@contoh.com" class="adm-input">
                    <span v-if="form.errors.email" role="alert" class="auth-err">{{ form.errors.email }}</span>
                </label>

                <label class="auth-field">
                    Kata sandi
                    <input v-model="form.password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••" class="adm-input">
                    <span v-if="form.errors.password" role="alert" class="auth-err">{{ form.errors.password }}</span>
                </label>

                <p v-for="(error, key) in form.errors" v-show="key !== 'email' && key !== 'password'" :key="key" role="alert" class="auth-err">{{ error }}</p>

                <div v-if="security.turnstile_required" class="auth-field">
                    <span style="font-weight:400;color:var(--a-muted)">Pemeriksaan keamanan</span>
                    <TurnstileWidget ref="turnstile" :site-key="security.turnstile_site_key" :action="security.turnstile_action" @token="form.turnstile_token = $event" />
                </div>

                <button type="submit" class="adm-btn primary auth-submit" :disabled="form.processing">
                    {{ form.processing ? 'Memeriksa…' : 'Masuk' }}
                </button>

                <p class="auth-alt"><Link href="/admin/forgot-password">Lupa kata sandi?</Link></p>
            </form>
        </div>
    </main>
</template>
