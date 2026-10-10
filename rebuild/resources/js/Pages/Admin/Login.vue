<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import TurnstileWidget from '../../Components/TurnstileWidget.vue';

const page = usePage();
const turnstile = ref(null);
const security = computed(() => page.props.security || {});
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
    <main class="lf-admin-auth min-h-screen bg-[#07172d] p-4 text-slate-900">
        <div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-[980px] place-items-center">
            <form class="w-full max-w-[420px] rounded-xl border border-slate-200 bg-white p-6 shadow-lg" @submit.prevent="submit">
                <div class="mb-6 flex items-center gap-3">
                    <span class="lf-admin-logo">LF</span>
                    <div>
                        <h1 class="m-0 text-xl font-bold text-[#0c1c3b]">LFAMILIA ADMIN</h1>
                        <span class="sr-only">Admin LFAMILIA</span>
                        <p class="m-0 text-xs text-slate-500">Top Up Game Solution</p>
                    </div>
                </div>

                <p v-if="page.props.status" role="status" class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">
                    {{ page.props.status }}
                </p>

                <div class="grid gap-4">
                    <label class="text-sm font-semibold text-slate-700">
                        Email
                        <Input v-model="form.email" name="email" type="email" autocomplete="username" required
                            class="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-base focus:border-[#1769e8]" />
                    </label>

                    <label class="text-sm font-semibold text-slate-700">
                        Kata sandi
                        <Input v-model="form.password" name="password" type="password" autocomplete="current-password" required
                            class="mt-1 h-10 w-full rounded-md border border-slate-300 px-3 text-base focus:border-[#1769e8]" />
                    </label>

                    <div class="flex justify-end">
                        <Link href="/admin/forgot-password" class="text-sm font-medium text-[#1769e8] hover:underline">
                            Lupa kata sandi?
                        </Link>
                    </div>

                    <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-600">
                        {{ error }}
                    </p>

                    <div v-if="security.turnstile_required">
                        <p class="mb-2 text-xs text-slate-600">Verifikasi keamanan Cloudflare</p>
                        <TurnstileWidget ref="turnstile" :site-key="security.turnstile_site_key"
                            :action="security.turnstile_action" @token="form.turnstile_token = $event" />
                    </div>

                    <Button type="submit" class="h-10 rounded-md bg-[#1769e8] text-base font-semibold text-white"
                        :disabled="form.processing">
                        {{ form.processing ? 'Memproses…' : 'Masuk' }}
                    </Button>
                </div>
            </form>
        </div>
    </main>
</template>
