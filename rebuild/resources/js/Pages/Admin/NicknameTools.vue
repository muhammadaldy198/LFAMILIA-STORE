<script setup>
import { Button } from '../../Components/ui/button';
import { Input } from '../../Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';

import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';

const props = defineProps({
    gameCodes: { type: Array, default: () => [] },
});

const tab = ref('game');
const gameCode = ref('');
const userId = ref('');
const server = ref('');
const customerNumber = ref('');
const query = ref('');
const result = ref(null);
const error = ref('');
const busy = ref(false);
const copied = ref('');

const filteredCodes = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return props.gameCodes;
    return props.gameCodes.filter((item) =>
        item.name.toLowerCase().includes(needle) || item.code.includes(needle)
    );
});
const selectedGame = computed(() => props.gameCodes.find((item) => item.code === gameCode.value) || null);
const requiresServer = computed(() => tab.value === 'region' || Boolean(selectedGame.value?.requires_server));

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
function resetResult() {
    result.value = null;
    error.value = '';
}
function clearForm() {
    gameCode.value = '';
    userId.value = '';
    server.value = '';
    customerNumber.value = '';
    resetResult();
}
async function runCheck() {
    resetResult();
    busy.value = true;
    try {
        const body = tab.value === 'game'
            ? { action: 'game', game_code: gameCode.value.trim(), user_id: userId.value.trim(), server: server.value.trim() || null }
            : tab.value === 'region'
                ? { action: 'region', user_id: userId.value.trim(), server: server.value.trim() }
                : { action: 'pln', customer_number: customerNumber.value.replace(/\D/g, '') };

        const response = await fetch('/admin/nickname-tools/check', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            throw new Error(validation || payload.message || 'Pemeriksaan tidak berhasil.');
        }
        result.value = payload;
    } catch (reason) {
        error.value = reason instanceof Error ? reason.message : 'Pemeriksaan tidak berhasil.';
    } finally {
        busy.value = false;
    }
}
async function copyCode(code) {
    await navigator.clipboard.writeText(code);
    copied.value = code;
    window.setTimeout(() => { if (copied.value === code) copied.value = ''; }, 1200);
}
</script>

<template>
<Head title="Validasi Akun" />
<AdminShell>
<div class="mx-auto max-w-[1540px]">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold tracking-[-0.025em] text-[#20324c]">Validasi Akun</h1>
            <p class="mt-1 text-[11px] text-[#7a899e]">Cek nickname game, region Mobile Legends, dan nama pelanggan PLN melalui backend. API key tidak dikirim ke browser.</p>
        </div>
        <span class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-[10px] font-bold text-blue-700">Server-side</span>
    </div>

    <section class="overflow-hidden rounded-lg border border-[#e1e6ed] bg-white">
        <header class="border-b border-[#edf0f4] px-4 pt-3">
            <div class="flex gap-1 overflow-x-auto">
                <Button v-for="item in [
                    ['game','Cek Game'],
                    ['region','Region MLBB'],
                    ['pln','PLN'],
                    ['codes','Kode Game'],
                ]" :key="item[0]" type="button"
                    class="h-9 shrink-0 rounded-t-md px-3 text-[10px] font-bold transition"
                    :class="tab===item[0] ? 'bg-[#1769e8] text-white' : 'text-[#62728a] hover:bg-[#f5f7fa]'"
                    @click="tab=item[0];resetResult()">
                    {{item[1]}}
                </Button>
            </div>
        </header>

        <div v-if="tab!=='codes'" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="grid content-start gap-4 sm:grid-cols-2">
                <label v-if="tab==='game'" class="text-[10px] font-bold text-[#52627a]">
                    Game Code
                    <Input v-model="gameCode" list="lf-game-codes" maxlength="100" placeholder="mobile-legends"
                        class="mt-1 block h-10 w-full rounded-md border border-[#dfe5ed] bg-[#f8fafc] px-3 text-xs text-[#243653] outline-none focus:border-[#8cb6ef]" />
                    <datalist id="lf-game-codes"><option v-for="item in gameCodes" :key="item.code" :value="item.code">{{item.name}}</option></datalist>
                </label>

                <label v-if="tab!=='pln'" class="text-[10px] font-bold text-[#52627a]">
                    User ID
                    <Input v-model="userId" maxlength="80" placeholder="Masukkan User ID"
                        class="mt-1 block h-10 w-full rounded-md border border-[#dfe5ed] bg-[#f8fafc] px-3 text-xs text-[#243653] outline-none focus:border-[#8cb6ef]" />
                </label>

                <label v-if="tab!=='pln'" class="text-[10px] font-bold text-[#52627a]">
                    Server / Zone
                    <Input v-model="server" maxlength="40" :placeholder="requiresServer ? 'Wajib diisi' : 'Opsional'"
                        class="mt-1 block h-10 w-full rounded-md border border-[#dfe5ed] bg-[#f8fafc] px-3 text-xs text-[#243653] outline-none focus:border-[#8cb6ef]" />
                    <small class="mt-1 block font-normal text-[#8b98aa]">{{requiresServer ? 'Wajib untuk pemeriksaan ini.' : 'Isi jika game memerlukan Server / Zone ID.'}}</small>
                </label>

                <label v-if="tab==='pln'" class="text-[10px] font-bold text-[#52627a] sm:col-span-2">
                    Nomor Meter / ID Pelanggan PLN
                    <Input :value="customerNumber" inputmode="numeric" maxlength="12" placeholder="11–12 angka"
                        class="mt-1 block h-10 w-full rounded-md border border-[#dfe5ed] bg-[#f8fafc] px-3 text-xs text-[#243653] outline-none focus:border-[#8cb6ef]"
                        @input="customerNumber=$event.target.value.replace(/\D/g,'').slice(0,12)" />
                </label>

                <div v-if="tab==='region' || gameCode==='mobile-legends'" class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-[9px] leading-4 text-blue-800 sm:col-span-2">
                    Mobile Legends divalidasi melalui nickname dan region. Keduanya harus mengembalikan data valid.
                </div>

                <div class="flex flex-wrap gap-2 border-t border-[#edf0f4] pt-4 sm:col-span-2">
                    <Button type="button" :disabled="busy" class="h-9 rounded-md bg-[#1769e8] px-4 text-[10px] font-bold text-white disabled:opacity-50" @click="runCheck">
                        {{busy ? 'Memeriksa...' : 'Cek Data'}}
                    </Button>
                    <Button type="button" :disabled="busy" class="h-9 rounded-md border border-[#dfe5ed] bg-white px-4 text-[10px] font-bold text-[#52627a]" @click="clearForm">
                        Bersihkan
                    </Button>
                </div>
            </div>

            <aside class="space-y-3">
                <div class="rounded-md border border-[#e1e6ed] bg-[#f8fafc] p-3">
                    <div class="flex items-center justify-between gap-2">
                        <strong class="text-[10px] text-[#34445f]">Endpoint aktif</strong>
                        <span class="rounded-full border border-slate-200 bg-white px-2 py-1 text-[8px] font-bold text-slate-500">Backend only</span>
                    </div>
                    <code class="mt-2 block break-all rounded border border-[#e5eaf1] bg-white px-2.5 py-2 text-[9px] text-[#1769e8]">
                        {{tab==='game' ? (gameCode==='mobile-legends' ? '/v1/check-nickname + /v1/check-region' : '/v1/check-nickname') : tab==='region' ? '/v1/check-nickname + /v1/check-region' : '/v1/check-pln'}}
                    </code>
                </div>
                <div v-if="error" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-[9px] font-semibold leading-4 text-rose-700">{{error}}</div>
                <div v-if="result" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-[9px] font-semibold leading-4 text-emerald-700">
                    <template v-if="tab==='pln'">Nama pelanggan: {{result.customer_name || '-'}}</template>
                    <template v-else>Nickname: {{result.nickname || '-'}}<span v-if="result.region"> · Region: {{result.region}}</span></template>
                </div>
                <div class="rounded-md border border-[#e1e6ed] bg-white p-3 text-[9px] leading-4 text-[#52627a]">
                    Credential tetap terenkripsi di backend dan tidak dikirim ke frontend pelanggan maupun halaman ini.
                </div>
            </aside>
        </div>

        <div v-else class="p-4">
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-[11px] font-extrabold text-[#243653]">Daftar Kode Game</h2>
                    <p class="mt-0.5 text-[9px] text-[#8190a5]">Kode ini digunakan pada konfigurasi cek nickname produk.</p>
                </div>
                <Input v-model="query" placeholder="Cari game atau kode..." class="h-9 w-full rounded-md border border-[#dfe5ed] bg-[#f8fafc] px-3 text-[10px] sm:w-[280px]" />
            </div>
            <div class="overflow-x-auto rounded-md border border-[#e1e6ed]">
                <Table class="min-w-[620px] w-full text-left text-[9px]">
                    <TableHeader class="bg-[#f8fafc] text-[#607089]">
                        <TableRow><TableHead class="px-3 py-2.5">Game</TableHead><TableHead class="px-3 py-2.5">Game Code</TableHead><TableHead class="px-3 py-2.5">Server / Zone</TableHead><TableHead class="px-3 py-2.5 text-right">Aksi</TableHead></TableRow>
                    </TableHeader>
                    <TableBody class="divide-y divide-[#edf0f4] bg-white">
                        <TableRow v-for="item in filteredCodes" :key="item.code">
                            <TableCell class="px-3 py-2.5 font-semibold text-[#34445f]">{{item.name}}</TableCell>
                            <TableCell class="px-3 py-2.5 font-mono text-[#1769e8]">{{item.code}}</TableCell>
                            <TableCell class="px-3 py-2.5"><span class="rounded-full px-2 py-1 text-[8px] font-bold" :class="item.requires_server ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'">{{item.requires_server ? 'Perlu' : 'Tidak'}}</span></TableCell>
                            <TableCell class="px-3 py-2.5 text-right"><Button type="button" class="rounded border border-[#dfe5ed] px-2.5 py-1.5 text-[9px] font-bold text-[#52627a]" @click="copyCode(item.code)">{{copied===item.code ? 'Tersalin' : 'Salin'}}</Button></TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </section>
</div>
</AdminShell>
</template>
