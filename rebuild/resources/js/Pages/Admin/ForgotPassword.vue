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
    <Head title="Lupa Kata Sandi Admin" />
    <main class="lf-admin-auth min-h-screen bg-[#07172d] p-4 text-slate-900">
        <div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-[980px] place-items-center">
            <form class="w-full max-w-[420px] rounded-xl border border-slate-200 bg-white p-6 shadow-lg" @submit.prevent="submit">
                <h1 class="mb-2 text-xl font-bold text-[#0c1c3b]">Pulihkan kata sandi admin</h1>
                <p class="mb-5 text-sm leading-6 text-slate-600">
                    Masukkan email admin yang terdaftar. Jika akun aktif, kami akan mengirim tautan reset yang berlaku selama 30 menit.
                </p>

                <p v-if="page.props.status" role="status" class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">
                    {{ page.props.status }}
                </p>

                <label class="text-sm font-semibold text-slate-700">
                    Email admin
                    <Input v-model="form.email" name="email" type="email" autocomplete="email" required
                        class="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-base focus:border-[#1769e8]" />
                </label>
                <p v-if="form.errors.email" role="alert" class="mt-2 text-sm text-red-600">{{ form.errors.email }}</p>

                <div v-if="security.turnstile_required" class="mt-4">
                    <p class="mb-2 text-xs text-slate-600">Verifikasi keamanan Cloudflare</p>
                    <TurnstileWidget ref="turnstile" :site-key="security.turnstile_site_key"
                        :action="security.turnstile_action" @token="form.turnstile_token = $event" />
                </div>
                <p v-if="form.errors.turnstile_token" role="alert" class="mt-2 text-sm text-red-600">
                    {{ form.errors.turnstile_token }}
                </p>

                <Button type="submit" class="mt-5 h-10 w-full rounded-md bg-[#1769e8] text-base font-semibold text-white"
                    :disabled="form.processing">
                    {{ form.processing ? 'Mengirim…' : 'Kirim tautan pemulihan' }}
                </Button>

                <div class="mt-4 text-center">
                    <Link href="/admin/login" class="text-sm font-medium text-[#1769e8] hover:underline">
                        Kembali ke login admin
                    </Link>
                </div>
            </form>
        </div>
    </main>
</template>
