<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminShell from '../../Components/AdminShell.vue';
import { Button } from '../../Components/ui/button';
import { Card } from '../../Components/ui/card';
import { Input } from '../../Components/ui/input';
import { Textarea } from '../../Components/ui/textarea';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    canExport: Boolean,
    summary: { type: Object, default: () => ({}) },
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

function saveStore() {
    storeForm.put('/admin/settings', { preserveScroll: true });
}

</script>

<template>
<Head title="Pengaturan" />
<AdminShell>
    <div class="space-y-5">
        <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">Pengaturan</h1>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Kelola identitas toko, kanal bantuan, informasi merchant, dan ekspor konfigurasi aman.
                </p>
            </div>
            <Button class="w-full sm:w-auto" variant="outline" @click="router.reload({ preserveScroll: true })">Muat ulang</Button>
        </header>

        <div class="lf-admin-summary ">
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

        <Card v-if="canExport" class="p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-2xl">
                        <h2 class="text-lg font-semibold">Unduh pengaturan toko</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Unduh salinan pengaturan toko untuk dokumentasi atau panduan pemulihan. Kata sandi dan data akses layanan tidak disertakan.
                        </p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            File ini tidak mencakup data pesanan, pelanggan, atau seluruh isi toko; bukan pengganti cadangan sistem.
                        </p>
                    </div>
                    <Button as-child>
                        <a href="/admin/configuration/export">Unduh pengaturan</a>
                    </Button>
                </div>
            </Card>
    </div>
</AdminShell>
</template>
