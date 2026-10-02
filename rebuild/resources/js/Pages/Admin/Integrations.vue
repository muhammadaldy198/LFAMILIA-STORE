<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';

import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({ integrations: Array });
const items = reactive(props.integrations.map((item) => ({
    ...item,
    result: null,
    reveal_password: '',
    config: Object.fromEntries(item.fields.map((field) => [field.key, field.value ?? (field.type === 'boolean' ? false : '')])),
})));

function save(item) {
    router.put('/admin/integrations/' + item.code, {
        is_active: item.is_active,
        config: item.config,
    }, { preserveScroll: true });
}

async function reveal(item, field) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('/admin/integrations/' + encodeURIComponent(item.code) + '/reveal/' + encodeURIComponent(field.key), {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ password: item.reveal_password }),
    });
    const data = await response.json().catch(() => ({}));
    if (response.ok) {
        item.config[field.key] = data.value || '';
        field.revealed = true;
        item.reveal_password = '';
        item.result = { status: 'OK', message: 'Secret ditampilkan setelah re-authentication.' };
    } else {
        item.result = { status: 'DOWN', message: data.errors?.password?.[0] || data.message || 'Reveal ditolak.' };
    }
}

async function testConnection(item) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('/admin/integrations/' + encodeURIComponent(item.code) + '/test', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
    });
    item.result = await response.json().catch(() => ({ status: 'DOWN', message: 'Tes gagal.' }));
}
</script>

<template>
    <Head title="Integrasi" />
    <AdminShell>
        <div class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-3xl font-semibold">Integrasi</h1><p class="text-sm text-slate-400">Secret terenkripsi di database dan tidak disimpan di GitHub.</p></div><a href="/admin/nickname-tools" class="rounded bg-slate-700 px-4 py-2 text-sm">Game Code & Test Nickname</a></div>

            <section v-for="item in items" :key="item.code" class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 class="text-xl font-semibold">{{ item.name }}</h2><p v-if="item.note" class="mt-1 text-xs text-amber-200">{{ item.note }}</p></div>
                    <label class="flex gap-2 text-sm"><input v-model="item.is_active" type="checkbox">Aktif</label>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label v-for="field in item.fields" :key="field.key" class="text-sm">
                        {{ field.label }}
                        <div class="mt-1 flex gap-2">
                            <Input
                                v-if="field.type !== 'boolean'"
                                v-model="item.config[field.key]"
                                :type="field.secret && !field.revealed ? 'password' : 'text'"
                                :placeholder="field.secret && field.configured ? 'Tersimpan — kosongkan untuk mempertahankan' : ''"
                                class="min-w-0 flex-1 rounded bg-slate-800 p-2"
                             />
                            <input v-else v-model="item.config[field.key]" type="checkbox" class="mt-2">
                            <Button v-if="field.secret && field.configured" type="button" :disabled="!item.reveal_password" class="rounded bg-slate-700 px-3 py-2 text-xs disabled:opacity-40" @click="reveal(item, field)">Reveal</Button>
                        </div>
                    </label>
                </div>
                <label v-if="item.fields.some((field) => field.secret && field.configured)" class="mt-4 block max-w-md text-sm">
                    Password Super Admin untuk Reveal
                    <Input v-model="item.reveal_password" type="password" autocomplete="current-password" class="mt-1 block w-full rounded bg-slate-800 p-2" placeholder="Masukkan ulang password" />
                </label>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button class="rounded bg-cyan-300 px-4 py-2 font-semibold text-slate-950" @click="save(item)">Simpan</Button>
                    <Button class="rounded bg-slate-700 px-4 py-2 text-sm" @click="testConnection(item)">Tes Koneksi</Button>
                </div>
                <p v-if="item.result" class="mt-3 text-sm" :class="item.result.status === 'HEALTHY' ? 'text-emerald-300' : 'text-amber-200'">{{ item.result.status }} · {{ item.result.message }}</p>
            </section>
        </div>
    </AdminShell>
</template>
