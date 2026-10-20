<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
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
    <Head title="Masuk Admin" />
    <main class="lf-admin-auth lf-admin" style="min-height:100svh;display:grid;place-items:center;padding:16px;">
        <div style="width:100%;max-width:400px;">
            <!-- Brand -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
                <span class="lf-admin-logo" style="width:44px;height:44px;flex:0 0 44px;border-radius:10px;display:grid;place-items:center;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                </span>
                <div>
                    <div style="font-size:15px;font-weight:700;line-height:1.2;">{{ storefront.storeName || 'LFAMILIA' }} ADMIN</div>
                    <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px;">{{ storefront.tagline || 'Top Up Game Solution' }}</div>
                </div>
            </div>

            <!-- Card form -->
            <form
                style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:24px;display:grid;gap:16px;"
                @submit.prevent="submit"
            >
                <div>
                    <div style="font-size:17px;font-weight:700;margin-bottom:4px;">Masuk ke Panel Admin</div>
                    <div style="font-size:12px;color:var(--muted-foreground);">Gunakan akun yang telah didaftarkan oleh Super Admin.</div>
                </div>

                <!-- Status -->
                <p v-if="page.props.status" role="status" style="padding:10px 12px;background:var(--accent);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--accent-foreground);margin:0;">
                    {{ page.props.status }}
                </p>

                <!-- Email -->
                <label style="display:grid;gap:6px;font-size:12px;font-weight:600;">
                    Email
                    <Input
                        v-model="form.email"
                        name="email"
                        type="email"
                        autocomplete="username"
                        required
                        placeholder="email@contoh.com"
                    />
                    <span v-if="form.errors.email" role="alert" style="color:var(--destructive);font-weight:400;font-size:11px;">{{ form.errors.email }}</span>
                </label>

                <!-- Password -->
                <label style="display:grid;gap:6px;font-size:12px;font-weight:600;">
                    Kata sandi
                    <Input
                        v-model="form.password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="••••••••"
                    />
                    <span v-if="form.errors.password" role="alert" style="color:var(--destructive);font-weight:400;font-size:11px;">{{ form.errors.password }}</span>
                </label>

                <!-- Error umum -->
                <p v-for="(error, key) in form.errors" v-show="key !== 'email' && key !== 'password'" :key="key" role="alert" style="color:var(--destructive);font-size:12px;margin:0;">{{ error }}</p>

                <!-- Turnstile -->
                <div v-if="security.turnstile_required" style="display:grid;gap:6px;">
                    <span style="font-size:11px;color:var(--muted-foreground);">Verifikasi keamanan Cloudflare</span>
                    <TurnstileWidget ref="turnstile" :site-key="security.turnstile_site_key" :action="security.turnstile_action" @token="form.turnstile_token = $event" />
                </div>

                <!-- Submit -->
                <Button type="submit" :disabled="form.processing" style="width:100%;font-weight:600;">
                    {{ form.processing ? 'Memproses…' : 'Masuk' }}
                </Button>

                <!-- Forgot -->
                <div style="text-align:center;">
                    <Link href="/admin/forgot-password" style="font-size:12px;color:var(--ring);text-decoration:none;">Lupa kata sandi?</Link>
                </div>
            </form>
        </div>
    </main>
</template>
