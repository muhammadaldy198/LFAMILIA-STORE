<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const security = computed(() => page.props.security || {});
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
    <main class="lf-admin-auth lf-admin" style="min-height:100svh;display:grid;place-items:center;padding:16px;">
        <div style="width:100%;max-width:400px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
                <span class="lf-admin-logo" style="width:44px;height:44px;flex:0 0 44px;border-radius:10px;display:grid;place-items:center;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                </span>
                <div>
                    <div style="font-size:15px;font-weight:700;line-height:1.2;">{{ (page.props.storefront || {}).storeName || 'LFAMILIA' }} ADMIN</div>
                    <div style="font-size:11px;color:var(--muted-foreground);margin-top:2px;">Pemulihan akses admin</div>
                </div>
            </div>

            <form
                style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:24px;display:grid;gap:16px;"
                @submit.prevent="submit"
            >
                <div>
                    <div style="font-size:17px;font-weight:700;margin-bottom:4px;">Pulihkan kata sandi</div>
                    <div style="font-size:12px;color:var(--muted-foreground);">Masukkan email admin yang terdaftar. Bila akunnya aktif, kami kirimkan tautan untuk membuat kata sandi baru yang berlaku 30 menit.</div>
                </div>

                <p v-if="page.props.status" role="status" style="padding:10px 12px;background:var(--accent);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--accent-foreground);margin:0;">
                    {{ page.props.status }}
                </p>

                <label style="display:grid;gap:6px;font-size:12px;font-weight:600;">
                    Email admin
                    <Input v-model="form.email" name="email" type="email" autocomplete="email" required placeholder="email@contoh.com" />
                    <span v-if="form.errors.email" role="alert" style="color:var(--destructive);font-weight:400;font-size:11px;">{{ form.errors.email }}</span>
                </label>

                <div v-if="security.turnstile_required" style="display:grid;gap:6px;">
                    <span style="font-size:11px;color:var(--muted-foreground);">Pemeriksaan keamanan</span>
                    <TurnstileWidget ref="turnstile" :site-key="security.turnstile_site_key" :action="security.turnstile_action" @token="form.turnstile_token = $event" />
                    <span v-if="form.errors.turnstile_token" role="alert" style="color:var(--destructive);font-size:11px;font-weight:400;">{{ form.errors.turnstile_token }}</span>
                </div>

                <Button type="submit" :disabled="form.processing" style="width:100%;font-weight:600;">
                    {{ form.processing ? 'Mengirim…' : 'Kirim tautan pemulihan' }}
                </Button>

                <div style="text-align:center;">
                    <Link href="/admin/login" style="font-size:12px;color:var(--ring);text-decoration:none;">Kembali ke halaman masuk</Link>
                </div>
            </form>
        </div>
    </main>
</template>
