<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { mediaRecommendation } from '../Composables/mediaRecommendations';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Button } from './ui/button';
import AdminConfirmDialog from './AdminConfirmDialog.vue';
import AdminIcon from './AdminIcon.vue';
const props = defineProps({ type: String, assetKey: String, id: Number, collection: { type: String, default: 'image' }, url: String });
const page = usePage();
const base = page.props.adminPanel?.base_path || '/admin';
const form = useForm({ image: null, collection: props.collection });
const fileInput = ref(null), preview = ref('');
const confirmingRemove = ref(false);
const wide = computed(() => ['banner','news','popup'].includes(props.type) || ['banner','desktop','mobile'].includes(props.collection) || props.assetKey?.includes('banner'));
const selectImage = event => {
    if (preview.value) URL.revokeObjectURL(preview.value);
    form.image = event.target.files?.[0] || null;
    preview.value = form.image ? URL.createObjectURL(form.image) : '';
};
const resetImage = () => { form.reset('image'); if (fileInput.value) fileInput.value.value = ''; if (preview.value) URL.revokeObjectURL(preview.value); preview.value = ''; };
const upload = () => form.post(base + '/catalog/media/' + props.type + '/' + props.id, { forceFormData: true, preserveScroll: true, onSuccess: resetImage });
// Konfirmasi memakai dialog bertema, bukan window.confirm bawaan browser.
const removeImage = () => router.delete(base + '/catalog/media/' + props.type + '/' + props.id, { data: { collection: props.collection }, preserveScroll: true, onFinish: () => { confirmingRemove.value = false; } });
onUnmounted(() => { if (preview.value) URL.revokeObjectURL(preview.value); });
</script>
<template>
    <div class="space-y-3">
        <div class="lf-admin-media-preview" :data-wide="wide"><img v-if="preview || url" :src="preview || url" alt="Pratinjau gambar"><AdminIcon v-else name="content" class="size-6 text-muted-foreground" /></div>
        <p class="lf-admin-note">{{ mediaRecommendation(type, collection, assetKey || '') }}</p>
        <div class="flex flex-wrap items-end gap-2">
            <label class="lf-admin-field min-w-0 flex-1"><span>Gambar JPEG/PNG/WebP · maks. 5 MB</span><input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" required :disabled="form.processing" class="block w-full min-w-0 text-sm" @change="selectImage"></label>
            <Button type="button" :disabled="form.processing || !form.image" @click="upload">{{form.processing?'Mengunggah…':'Unggah gambar'}}</Button>
        </div>
        <p v-if="form.errors.image" role="alert" class="text-sm text-destructive">{{ form.errors.image }}</p>
        <Button v-if="url" type="button" variant="outline" :disabled="form.processing" @click="confirmingRemove = true">Hapus gambar</Button>
        <AdminConfirmDialog :open="confirmingRemove" title="Hapus gambar" message="Gambar ini akan dihapus dari toko. Tindakan ini tidak dapat dibatalkan." confirm-label="Hapus gambar" destructive @update:open="value => { if (!value) confirmingRemove = false; }" @confirm="removeImage" />
    </div>
</template>
