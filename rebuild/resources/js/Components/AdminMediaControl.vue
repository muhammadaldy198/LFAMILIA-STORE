<script setup>
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({ type: String, id: Number, collection: { type: String, default: 'image' }, url: String });
const form = useForm({ image: null, collection: props.collection });
const upload = () => form.post('/admin/catalog/media/' + props.type + '/' + props.id, { forceFormData: true, onSuccess: () => form.reset('image') });
const remove = () => router.delete('/admin/catalog/media/' + props.type + '/' + props.id, { data: { collection: props.collection } });
</script>

<template>
    <div class="space-y-2 rounded-md border border-slate-800 p-3">
        <p class="text-sm text-slate-400">Gambar {{ collection === 'banner' ? 'banner' : '' }}</p>
        <img v-if="url" :src="url" alt="Gambar saat ini" class="h-20 max-w-full rounded object-contain">
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="upload">
            <label class="text-sm">Unggah JPEG/PNG/WebP (maks. 5 MB)
                <input type="file" accept="image/jpeg,image/png,image/webp" required class="mt-1 block max-w-56 text-xs" @input="form.image = $event.target.files[0]">
            </label>
            <button :disabled="form.processing" class="rounded bg-cyan-400 px-3 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50">Unggah</button>
        </form>
        <span v-if="form.errors.image" class="text-sm text-red-300">{{ form.errors.image }}</span>
        <button v-if="url" type="button" class="text-sm text-red-300 underline" @click="remove">Hapus gambar</button>
    </div>
</template>
