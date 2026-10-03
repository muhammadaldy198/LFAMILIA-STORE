<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import { Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';

const props = defineProps({ accounts: Array, products: Array });
const selectedProductId = ref('');
const label = ref('');
const values = reactive({});
const editingId = ref(null);
const busy = ref(false);
const message = ref('');
const error = ref('');

const selectedProduct = computed(() => (props.products || []).find((item) => String(item.id) === String(selectedProductId.value)) || null);
const fields = computed(() => selectedProduct.value?.fields || []);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
function reset() {
    if (busy.value) return;
    editingId.value = null;
    selectedProductId.value = '';
    label.value = '';
    Object.keys(values).forEach((key) => delete values[key]);
}
function chooseProduct() {
    const keep = new Set(fields.value.map((field) => field.field_key));
    Object.keys(values).forEach((key) => { if (!keep.has(key)) delete values[key]; });
}
function edit(account) {
    if (busy.value) return;
    editingId.value = account.id;
    selectedProductId.value = String(account.product_id);
    label.value = account.label;
    Object.keys(values).forEach((key) => delete values[key]);
    Object.assign(values, account.customer_input || {});
    message.value = '';
    error.value = '';
}
async function save() {
    if (busy.value) return;
    error.value = '';
    message.value = '';
    if (!selectedProduct.value) { error.value = 'Pilih game terlebih dahulu.'; return; }
    if (!label.value.trim()) { error.value = 'Nama akun wajib diisi.'; return; }
    busy.value = true;
    try {
        const url = editingId.value ? '/account/game-accounts/' + editingId.value : '/account/game-accounts';
        const response = await fetch(url, {
            method: editingId.value ? 'PUT' : 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                product_id: Number(selectedProductId.value),
                label: label.value.trim(),
                customer_input: Object.fromEntries(fields.value.map((field) => [field.field_key, String(values[field.field_key] || '').trim()])),
            }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Akun game gagal disimpan.');
        message.value = editingId.value ? 'Akun game diperbarui.' : 'Akun game tersimpan.';
        window.location.reload();
    } catch (reason) {
        error.value = reason instanceof Error ? reason.message : 'Akun game gagal disimpan.';
    } finally {
        busy.value = false;
    }
}
async function remove(account) {
    if (busy.value) return;
    if (!confirm('Hapus akun game tersimpan ini?')) return;
    busy.value = true;
    error.value = '';
    try {
        const response = await fetch('/account/game-accounts/' + account.id, {
            method:'DELETE',
            headers:{ Accept:'application/json', 'X-CSRF-TOKEN':csrf() },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.message || 'Akun game gagal dihapus.');
        }
        window.location.reload();
    } catch (reason) {
        error.value = reason instanceof Error ? reason.message : 'Akun game gagal dihapus.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
<Head :title="customerText('pages.customer.gameaccounts.attribute.title.58d42884', 'Akun Game')" />
<AccountShell>
    <div><p class="lf-eyebrow">{{ customerText("pages.customer.gameaccounts.3fa5187f", "AKUN TERSIMPAN") }}</p><h1 class="lf-account-title">{{ customerText("pages.customer.gameaccounts.d90074a4", "Akun game") }}</h1><p class="lf-account-copy">{{ customerText("pages.customer.gameaccounts.c0867ef5", "Simpan User ID dan server agar dapat dipilih kembali saat checkout.") }}</p></div>

    <p v-if="message" class="rounded-xl border border-lime-300/20 bg-lime-300/[0.06] p-3 text-xs text-lime-200">{{message}}</p>
    <p v-if="error" class="rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{{error}}</p>

    <section class="lf-account-card">
        <div class="lf-account-card-head">
            <div><strong>{{editingId ? 'Edit akun game' : 'Simpan akun game'}}</strong><small>{{ customerText("pages.customer.gameaccounts.62ba1e98", "Data tetap diverifikasi lagi saat checkout bila game mendukung cek nickname.") }}</small></div>
            <button v-if="editingId" type="button" class="lf-danger-link" @click="reset">{{ customerText("pages.customer.gameaccounts.d3f9bfa5", "Batal") }}</button>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-xs">{{ customerText("pages.customer.gameaccounts.c2762327", "Game") }}
                <select v-model="selectedProductId" class="mt-1 block w-full rounded-lg border border-white/10 bg-white/[0.03] p-3" @change="chooseProduct">
                    <option value="">{{ customerText("pages.customer.gameaccounts.582c7da3", "Pilih game") }}</option>
                    <option v-for="product in products" :key="product.id" :value="String(product.id)">{{product.name}}</option>
                </select>
            </label>
            <label class="text-xs">{{ customerText("pages.customer.gameaccounts.9087cd09", "Nama akun") }}<input v-model="label" maxlength="100" :placeholder="customerText('pages.customer.gameaccounts.attribute.placeholder.e8d07a66', 'Contoh: Mobile Legends Utama')" class="mt-1 block w-full rounded-lg border border-white/10 bg-white/[0.03] p-3"></label>
            <label v-for="field in fields" :key="field.field_key" class="text-xs">
                {{field.label}}
                <input v-model="values[field.field_key]" :type="field.type || 'text'" :required="field.is_required" :placeholder="field.placeholder || ''" class="mt-1 block w-full rounded-lg border border-white/10 bg-white/[0.03] p-3">
            </label>
        </div>
        <button type="button" class="lf-primary mt-4" :disabled="busy" @click="save">{{busy?'Menyimpan...':editingId?'Simpan perubahan':'Tambah akun'}}</button>
    </section>

    <section class="space-y-3">
        <h2 class="text-sm font-bold">{{ customerText("pages.customer.gameaccounts.b294433f", "Akun tersimpan") }}</h2>
        <article v-for="account in accounts" :key="account.id" class="lf-account-card">
            <div class="lf-account-card-head">
                <div><strong>{{account.label}}</strong><small>{{account.product_name}}<template v-if="account.nickname"> · {{account.nickname}}</template></small></div>
                <div class="flex gap-2"><button type="button" class="text-xs text-lime-200" @click="edit(account)">{{ customerText("pages.customer.gameaccounts.c2c76cb1", "Edit") }}</button><button type="button" class="lf-danger-link" @click="remove(account)">{{ customerText("pages.customer.gameaccounts.47b55d9e", "Hapus") }}</button></div>
            </div>
            <dl class="lf-account-kv"><div v-for="(value,key) in account.customer_input" :key="key"><dt>{{String(key).replaceAll('_',' ')}}</dt><dd>{{value}}</dd></div></dl>
        </article>
        <div v-if="!accounts?.length" class="lf-account-empty">{{ customerText("pages.customer.gameaccounts.640980a", "Belum ada akun game tersimpan.") }}</div>
    </section>
</AccountShell>
</template>
