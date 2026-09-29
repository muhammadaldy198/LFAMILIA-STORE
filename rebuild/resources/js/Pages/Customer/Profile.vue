<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AccountShell from '../../Components/AccountShell.vue';

const props = defineProps({ customer: Object, hasPassword: Boolean });
const page = usePage();
const profile = useForm({ name: props.customer.name, email: props.customer.email, phone: props.customer.phone });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const removal = useForm({ confirmation: '', password: '' });
const updatePassword = () => password.put('/account/password', { onFinish: () => password.reset() });
const remove = () => {
    if (window.confirm('Hapus akun ini? Tindakan ini tidak dapat dibatalkan.')) {
        removal.delete('/account');
    }
};
</script>

<template>
    <Head title="Profil" />
    <AccountShell>
        <h1 class="text-3xl font-semibold">Profil</h1>
        <p v-if="page.props.status" role="status" class="text-cyan-300">{{ page.props.status }}</p>
        <form class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5" @submit.prevent="profile.put('/account/profile')">
            <h2 class="text-lg font-semibold">Data akun</h2>
            <label v-for="field in [{ key: 'name', label: 'Nama', type: 'text' }, { key: 'email', label: 'Email', type: 'email' }, { key: 'phone', label: 'Nomor HP', type: 'tel' }]" :key="field.key" class="block">{{ field.label }}
                <input v-model="profile[field.key]" :type="field.type" required class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="profile.errors[field.key]" class="text-sm text-red-300">{{ profile.errors[field.key] }}</span>
            </label>
            <button :disabled="profile.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50">Simpan profil</button>
        </form>
        <form class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5" @submit.prevent="updatePassword">
            <h2 class="text-lg font-semibold">{{ hasPassword ? 'Ubah kata sandi' : 'Buat kata sandi' }}</h2>
            <label v-if="hasPassword" class="block">Kata sandi saat ini
                <input v-model="password.current_password" type="password" autocomplete="current-password" required class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="password.errors.current_password" class="text-sm text-red-300">{{ password.errors.current_password }}</span>
            </label>
            <label class="block">Kata sandi baru (minimal 12 karakter)
                <input v-model="password.password" type="password" autocomplete="new-password" minlength="12" required class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="password.errors.password" class="text-sm text-red-300">{{ password.errors.password }}</span>
            </label>
            <label class="block">Ulangi kata sandi
                <input v-model="password.password_confirmation" type="password" autocomplete="new-password" required class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
            </label>
            <button :disabled="password.processing" class="rounded-md bg-cyan-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50">Simpan kata sandi</button>
        </form>
        <form class="space-y-4 rounded-xl border border-red-900 bg-slate-900 p-5" @submit.prevent="remove">
            <h2 class="text-lg font-semibold">Hapus akun</h2>
            <p class="text-sm text-slate-400">Akun dengan saldo, pesanan, top-up, atau tiket tidak dapat dihapus otomatis. Ketik HAPUS untuk melanjutkan.</p>
            <label class="block">Konfirmasi
                <input v-model="removal.confirmation" required class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="removal.errors.confirmation" class="text-sm text-red-300">{{ removal.errors.confirmation }}</span>
            </label>
            <label v-if="hasPassword" class="block">Kata sandi
                <input v-model="removal.password" type="password" autocomplete="current-password" required class="mt-1 block w-full rounded-md bg-slate-800 p-3 focus:outline-cyan-300">
                <span v-if="removal.errors.password" class="text-sm text-red-300">{{ removal.errors.password }}</span>
            </label>
            <p v-if="removal.errors.account" class="text-sm text-red-300">{{ removal.errors.account }}</p>
            <button :disabled="removal.processing" class="rounded-md bg-red-500 px-4 py-2 font-semibold text-white disabled:opacity-50">Hapus akun</button>
        </form>
    </AccountShell>
</template>
