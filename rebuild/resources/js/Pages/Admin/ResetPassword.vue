<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, required: true },
});

const form = useForm({
    email: props.email,
    token: props.token,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    if (form.processing) return;
    form.post('/admin/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Kata sandi baru admin" />
    <main class="auth-page">
        <div class="auth-box">
            <form class="auth-card" @submit.prevent="submit">
                <div>
                    <h1>Buat kata sandi baru</h1>
                    <p class="auth-lead">Gunakan minimal 12 karakter. Sandi yang panjang dan tidak dipakai di tempat lain paling aman.</p>
                </div>

                <label class="auth-field">
                    Email admin
                    <input v-model="form.email" name="email" type="email" autocomplete="username" required class="adm-input">
                    <span v-if="form.errors.email" role="alert" class="auth-err">{{ form.errors.email }}</span>
                </label>

                <label class="auth-field">
                    Kata sandi baru
                    <input v-model="form.password" name="password" type="password" autocomplete="new-password" required minlength="12" class="adm-input">
                    <span v-if="form.errors.password" role="alert" class="auth-err">{{ form.errors.password }}</span>
                </label>

                <label class="auth-field">
                    Ulangi kata sandi baru
                    <input v-model="form.password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" class="adm-input">
                    <span v-if="form.errors.password_confirmation" role="alert" class="auth-err">{{ form.errors.password_confirmation }}</span>
                </label>

                <p v-for="(error, key) in form.errors" v-show="!['email','password','password_confirmation'].includes(key)" :key="key" role="alert" class="auth-err">{{ error }}</p>

                <button type="submit" class="adm-btn primary auth-submit" :disabled="form.processing">
                    {{ form.processing ? 'Menyimpan…' : 'Simpan kata sandi baru' }}
                </button>

                <p class="auth-alt"><Link href="/admin/forgot-password">Minta tautan baru</Link> · <Link href="/admin/login">Kembali ke halaman masuk</Link></p>
            </form>
        </div>
    </main>
</template>
