<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';

import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({ admins: Array, permissions: Object });
const admins = reactive(props.admins.map((item) => ({ ...item, password: '' })));
watch(()=>props.admins,value=>admins.splice(0,admins.length,...value.map(item=>({...item,password:''}))));
const form = useForm({ name: '', email: '', password: '', role: 'ADMIN', permissions: [], is_active: true });

function save(admin) {
    router.put('/admin/access/' + admin.id, {
        name: admin.name,
        email: admin.email,
        password: admin.password || null,
        role: admin.role,
        permissions: admin.role === 'ADMIN' ? admin.permissions : [],
        is_active: admin.is_active,
    }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin & Akses" />
    <AdminShell>
        <div class="space-y-6">
            <div><h1 class="text-3xl font-semibold">Admin & Akses</h1><p class="text-sm text-slate-400">Role v1: SUPER_ADMIN dan ADMIN. Membership customer tetap terpisah dari role panel.</p></div>

            <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 class="text-xl font-semibold">Tambah Admin</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="form.post('/admin/access',{onSuccess:()=>form.reset()})">
                    <Input v-model="form.name" required placeholder="Nama" class="rounded bg-slate-800 p-2" />
                    <Input v-model="form.email" required type="email" placeholder="Email" class="rounded bg-slate-800 p-2" />
                    <Input v-model="form.password" required type="password" minlength="12" placeholder="Password minimal 12 karakter" class="rounded bg-slate-800 p-2" />
                    <select v-model="form.role" class="rounded bg-slate-800 p-2"><option>ADMIN</option><option>SUPER_ADMIN</option></select>
                    <div v-if="form.role === 'ADMIN'" class="grid gap-2 md:col-span-2 md:grid-cols-3">
                        <label v-for="(label, key) in permissions" :key="key" class="flex gap-2 text-sm">
                            <input v-model="form.permissions" :value="key" type="checkbox">{{ label }}
                        </label>
                    </div>
                    <label class="flex gap-2 text-sm"><input v-model="form.is_active" type="checkbox">Aktif</label>
                    <Button class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950">Tambah</Button>
                    <p v-if="Object.keys(form.errors).length" class="text-sm text-red-300 md:col-span-2">{{ Object.values(form.errors).join(' · ') }}</p>
                </form>
            </section>

            <section v-for="admin in admins" :key="admin.id" class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div class="grid gap-3 md:grid-cols-4">
                    <Input v-model="admin.name" class="rounded bg-slate-800 p-2" />
                    <Input v-model="admin.email" type="email" class="rounded bg-slate-800 p-2" />
                    <select v-model="admin.role" class="rounded bg-slate-800 p-2"><option>ADMIN</option><option>SUPER_ADMIN</option></select>
                    <Input v-model="admin.password" type="password" placeholder="Password baru (opsional)" class="rounded bg-slate-800 p-2" />
                </div>
                <div v-if="admin.role === 'ADMIN'" class="mt-4 grid gap-2 md:grid-cols-3">
                    <label v-for="(label, key) in permissions" :key="key" class="flex gap-2 text-sm">
                        <input v-model="admin.permissions" :value="key" type="checkbox">{{ label }}
                    </label>
                </div>
                <div class="mt-4 flex items-center gap-3"><label class="flex gap-2 text-sm"><input v-model="admin.is_active" type="checkbox">Aktif</label><Button class="rounded bg-slate-700 px-4 py-2 text-sm" @click="save(admin)">Simpan</Button></div>
            </section>
        </div>
    </AdminShell>
</template>
