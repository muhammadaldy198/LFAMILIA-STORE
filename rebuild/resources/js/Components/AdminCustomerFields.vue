<script setup>
import { Button } from './ui/button';
import AdminSwitch from './AdminSwitch.vue';
import { Input } from './ui/input';
defineProps({ rows: Array, error: String, saving: Boolean });
const emit = defineEmits(['move','remove','add','save']);
</script>
<template><div class="space-y-3">
                    <section v-for="(field, index) in rows" :key="index" class="space-y-3 border-b py-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong>Kolom {{ index + 1 }}</strong>
                            <div class="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" :disabled="index===0" :aria-label="'Naikkan kolom '+(index+1)" @click="emit('move',index,-1)">Naik</Button>
                                <Button type="button" variant="outline" :disabled="index===rows.length-1" :aria-label="'Turunkan kolom '+(index+1)" @click="emit('move',index,1)">Turun</Button>
                                <Button type="button" variant="destructive" @click="emit('remove',index)">Hapus</Button>
                            </div>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="text-sm">Nama kolom<Input v-model="field.label" maxlength="255" placeholder="Contoh: User ID" class="mt-1" /></label>
                            <label class="text-sm">Kode kolom<Input v-model="field.field_key" maxlength="80" placeholder="Contoh: user_id" class="mt-1" /><span class="mt-1 block text-xs text-slate-500">Huruf kecil, angka, dan garis bawah. Kode dipakai oleh cek nickname dan template pengiriman.</span></label>
                            <label class="text-sm">Contoh isian<Input v-model="field.placeholder" maxlength="255" placeholder="Contoh: 123456789" class="mt-1" /></label>
                            <label class="text-sm">Jenis isian<select v-model="field.type" class="mt-1 block w-full rounded-md bg-slate-800 p-2"><option value="text">Teks / ID</option><option value="tel">Nomor telepon</option><option value="email">Email</option></select></label>
                            <label class="flex items-center gap-2 text-sm"><AdminSwitch v-model="field.is_required" /> Wajib diisi</label>
                        </div>
                    </section>
                    <Button type="button" variant="outline" :disabled="rows.length>=20" @click="emit('add')">Tambah kolom</Button>
                    <section class="space-y-3 border-b py-4">
                        <h3 class="font-semibold">Pratinjau isian pelanggan</h3>
                        <p v-if="!rows.length" class="text-sm text-slate-500">Belum ada kolom.</p>
                        <label v-for="(field,index) in rows" :key="index" class="block text-sm">{{ field.label || 'Label kolom' }}{{ field.is_required ? ' *' : '' }}<Input :type="field.type" :placeholder="field.placeholder" disabled class="mt-1" /></label>
                    </section>
                    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
                    <Button type="button" :disabled="saving" @click="emit('save')">{{ saving ? 'Menyimpan…' : 'Simpan kolom' }}</Button>
</div></template>
