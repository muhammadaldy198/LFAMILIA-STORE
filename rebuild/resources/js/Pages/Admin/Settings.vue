<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import { Badge } from '../../Components/ui/badge';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    tiers: { type: Array, default: () => [] },
    canExport: Boolean,
    summary: { type: Object, default: () => ({}) },
});

const tab = ref('store');
const tiers = reactive((props.tiers || []).map((tier) => ({ ...tier })));
watch(() => props.tiers, (value) => {
    tiers.splice(0, tiers.length, ...(value || []).map((tier) => ({ ...tier })));
});

const storeForm = useForm({
    store_name: props.settings?.['store.name'] || 'LFAMILIA STORE',
    tagline: props.settings?.['store.tagline'] || 'Top up favoritmu, sat set tanpa ribet.',
    support_whatsapp: props.settings?.['store.support_whatsapp'] || '',
    instagram_url: props.settings?.['store.instagram_url'] || '',
    email: props.settings?.['store.email'] || '',
    discord_url: props.settings?.['store.discord_url'] || '',
    support_url: props.settings?.['store.support_url'] || '/contact',
    business_hours: props.settings?.['store.business_hours'] || 'Setiap hari, 08.00–23.00 WIB',
    legal_name: props.settings?.['store.legal_name'] || '',
    registration_id: props.settings?.['store.registration_id'] || '',
    address: props.settings?.['store.address'] || '',
});

const money = (value) => 'Rp' + Number(value || 0).toLocaleString('id-ID');
const percent = (value) => Number(value || 0).toLocaleString('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
}) + '%';

function saveStore() {
    storeForm.put('/admin/settings', { preserveScroll: true });
}

function saveTier(tier) {
    router.put('/admin/settings/membership/' + encodeURIComponent(tier.code), {
        is_active: Boolean(tier.is_active),
        minimum_spend_idr: Number(tier.minimum_spend_idr || 0),
        discount_percent: Number(tier.discount_percent || 0),
        benefit_notes: tier.benefit_notes || '',
    }, { preserveScroll: true });
}
</script>

<template>
<Head title="Pengaturan" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pengaturan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Kelola identitas toko, kanal bantuan, informasi merchant, membership pelanggan, dan ekspor konfigurasi aman.
                </p>
            </div>
            <Button variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Tier aktif</p>
                <p class="mt-2 text-2xl font-semibold">{{ summary.active_tiers || 0 }}/{{ summary.total_tiers || 0 }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Kanal kontak</p>
                <p class="mt-2 text-2xl font-semibold">{{ summary.contacts_configured || 0 }}/4</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Identitas merchant</p>
                <p class="mt-2 text-lg font-semibold">{{ summary.merchant_identity_complete ? 'Lengkap' : 'Belum lengkap' }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Ekspor konfigurasi</p>
                <p class="mt-2 text-lg font-semibold">{{ canExport ? 'Super Admin' : 'Tidak tersedia' }}</p>
            </Card>
        </div>

        <nav class="flex max-w-full gap-1 overflow-x-auto rounded-lg border p-1">
            <Button type="button" :variant="tab === 'store' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'store'">Toko & Kontak</Button>
            <Button type="button" :variant="tab === 'membership' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'membership'">Membership</Button>
            <Button v-if="canExport" type="button" :variant="tab === 'export' ? 'secondary' : 'ghost'" class="shrink-0" @click="tab = 'export'">Ekspor</Button>
        </nav>

        <template v-if="tab === 'store'">
            <form class="space-y-4" @submit.prevent="saveStore">
                <Card class="p-4">
                    <div>
                        <h2 class="text-lg font-semibold">Identitas Toko</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Nama dan tagline digunakan langsung oleh frontend customer pada area yang terhubung ke konfigurasi toko.</p>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Nama toko</span>
                            <Input v-model="storeForm.store_name" required minlength="2" maxlength="80" />
                            <span v-if="storeForm.errors.store_name" class="text-xs text-destructive">{{ storeForm.errors.store_name }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Tagline</span>
                            <Input v-model="storeForm.tagline" required minlength="3" maxlength="160" />
                            <span v-if="storeForm.errors.tagline" class="text-xs text-destructive">{{ storeForm.errors.tagline }}</span>
                        </label>
                    </div>
                    <div class="mt-4 rounded-md border p-3 text-sm text-muted-foreground">
                        Logo, favicon, banner homepage, gambar footer, widget bantuan, dan teks presentasi tetap dikelola di
                        <Link href="/admin/content" class="font-medium text-foreground underline underline-offset-2">Banner & Konten</Link>
                        agar tidak ada pengaturan ganda.
                    </div>
                </Card>

                <Card class="p-4">
                    <div>
                        <h2 class="text-lg font-semibold">Kontak & Jam Layanan</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Data ini terhubung ke footer, tombol bantuan, halaman kontak, dan kanal customer yang relevan.</p>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <label class="space-y-1">
                            <span class="text-sm font-medium">WhatsApp bantuan</span>
                            <Input v-model="storeForm.support_whatsapp" maxlength="32" placeholder="+6281234567890" />
                            <span v-if="storeForm.errors.support_whatsapp" class="text-xs text-destructive">{{ storeForm.errors.support_whatsapp }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Email bantuan</span>
                            <Input v-model="storeForm.email" type="email" maxlength="255" placeholder="support@lfamiliastore.my.id" />
                            <span v-if="storeForm.errors.email" class="text-xs text-destructive">{{ storeForm.errors.email }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Jam layanan</span>
                            <Input v-model="storeForm.business_hours" required minlength="3" maxlength="160" />
                            <span v-if="storeForm.errors.business_hours" class="text-xs text-destructive">{{ storeForm.errors.business_hours }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Instagram</span>
                            <Input v-model="storeForm.instagram_url" maxlength="500" placeholder="https://instagram.com/..." />
                            <span v-if="storeForm.errors.instagram_url" class="text-xs text-destructive">{{ storeForm.errors.instagram_url }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Discord</span>
                            <Input v-model="storeForm.discord_url" maxlength="500" placeholder="https://discord.gg/..." />
                            <span v-if="storeForm.errors.discord_url" class="text-xs text-destructive">{{ storeForm.errors.discord_url }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Tautan bantuan utama</span>
                            <Input v-model="storeForm.support_url" maxlength="500" placeholder="/contact atau https://..." />
                            <span v-if="storeForm.errors.support_url" class="text-xs text-destructive">{{ storeForm.errors.support_url }}</span>
                        </label>
                    </div>
                </Card>

                <Card class="p-4">
                    <div>
                        <h2 class="text-lg font-semibold">Identitas Merchant</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Digunakan pada halaman status publik untuk informasi identitas bisnis. Kosongkan field yang memang belum tersedia.</p>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Nama legal / badan usaha</span>
                            <Input v-model="storeForm.legal_name" maxlength="255" placeholder="Nama legal toko atau badan usaha" />
                            <span v-if="storeForm.errors.legal_name" class="text-xs text-destructive">{{ storeForm.errors.legal_name }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Nomor registrasi</span>
                            <Input v-model="storeForm.registration_id" maxlength="120" placeholder="NIB / nomor registrasi bila ada" />
                            <span v-if="storeForm.errors.registration_id" class="text-xs text-destructive">{{ storeForm.errors.registration_id }}</span>
                        </label>
                        <label class="space-y-1 md:col-span-2">
                            <span class="text-sm font-medium">Alamat</span>
                            <Textarea v-model="storeForm.address" rows="3" maxlength="1000" placeholder="Alamat merchant yang ingin ditampilkan" />
                            <span v-if="storeForm.errors.address" class="text-xs text-destructive">{{ storeForm.errors.address }}</span>
                        </label>
                    </div>
                </Card>

                <div class="flex justify-end">
                    <Button :disabled="storeForm.processing">{{ storeForm.processing ? 'Menyimpan…' : 'Simpan Pengaturan Toko' }}</Button>
                </div>
            </form>
        </template>

        <template v-else-if="tab === 'membership'">
            <Card class="p-4">
                <div>
                    <h2 class="text-lg font-semibold">Tier Membership Pelanggan</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Urutan tier tetap BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA. Syarat tier otomatis memakai total transaksi berhasil; diskon diterapkan server-side saat checkout.
                    </p>
                </div>
            </Card>

            <div class="grid gap-4 xl:grid-cols-2">
                <Card v-for="tier in tiers" :key="tier.code" class="p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-semibold">{{ tier.rank }}. {{ tier.code }}</h3>
                                <Badge :variant="tier.is_active ? 'secondary' : 'outline'">{{ tier.is_active ? 'Aktif' : 'Nonaktif' }}</Badge>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Syarat saat ini {{ money(tier.minimum_spend_idr) }} · diskon {{ percent(tier.discount_percent) }}
                            </p>
                        </div>
                        <label class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <input v-model="tier.is_active" type="checkbox" class="size-4">
                            Aktif
                        </label>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Minimum total transaksi berhasil</span>
                            <Input v-model.number="tier.minimum_spend_idr" type="number" min="0" max="1000000000000" step="1" />
                            <span class="text-xs text-muted-foreground">Rp0 berarti tier dapat dicapai tanpa minimum belanja.</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-sm font-medium">Diskon member (%)</span>
                            <Input v-model.number="tier.discount_percent" type="number" min="0" max="100" step="0.01" />
                            <span class="text-xs text-muted-foreground">Maksimum 100%. Perhitungan tetap dilakukan backend.</span>
                        </label>
                        <label class="space-y-1 md:col-span-2">
                            <span class="text-sm font-medium">Manfaat tambahan</span>
                            <Textarea v-model="tier.benefit_notes" rows="3" maxlength="1000" placeholder="Contoh: Prioritas layanan&#10;Promo khusus member" />
                            <span class="text-xs text-muted-foreground">Tulis dengan bahasa customer, satu manfaat per baris bila perlu.</span>
                        </label>
                    </div>

                    <div v-if="tier.has_legacy_requirements || tier.has_legacy_benefits" class="mt-3 rounded-md border p-3 text-xs text-muted-foreground">
                        Tier ini memiliki metadata lama tambahan. Data tersebut tetap dipertahankan saat menyimpan agar tidak hilang.
                    </div>

                    <div class="mt-4 flex justify-end">
                        <Button size="sm" @click="saveTier(tier)">Simpan {{ tier.code }}</Button>
                    </div>
                </Card>
            </div>
        </template>

        <template v-else>
            <Card v-if="canExport" class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-2xl">
                        <h2 class="text-lg font-semibold">Ekspor Konfigurasi Aman</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Unduh snapshot konfigurasi aplikasi untuk dokumentasi atau pemulihan manual. Credential, password, API key, token, private key, signature, dan ciphertext tidak ikut diekspor.
                        </p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Ini bukan backup database penuh dan tidak menggantikan backup server.
                        </p>
                    </div>
                    <Button as-child>
                        <a href="/admin/configuration/export">Unduh JSON</a>
                    </Button>
                </div>
            </Card>
        </template>
    </div>
</AdminShell>
</template>
