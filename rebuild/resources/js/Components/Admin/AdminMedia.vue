<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { mediaRecommendation } from '../../Composables/mediaRecommendations';

// Satu kendali gambar untuk semua menu: pratinjau, unggah, hapus.
const props = defineProps({
    type: { type: String, required: true },
    id: { type: Number, required: true },
    collection: { type: String, default: 'image' },
    assetKey: { type: String, default: '' },
    url: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});

const busy = ref(false);
const error = ref('');
const input = ref(null);

const upload = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    busy.value = true;
    error.value = '';
    router.post(`/admin/catalog/media/${props.type}/${props.id}`, { image: file, collection: props.collection }, {
        forceFormData: true,
        preserveScroll: true,
        onError: bag => { error.value = bag.image || 'Gambar gagal diunggah.'; },
        onFinish: () => { busy.value = false; if (input.value) input.value.value = ''; },
    });
};

const remove = () => {
    busy.value = true;
    router.delete(`/admin/catalog/media/${props.type}/${props.id}`, {
        data: { collection: props.collection },
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
};
</script>

<template>
    <div class="adm-media">
        <div class="adm-media-preview" :class="{ wide }">
            <img v-if="url" :src="url" :alt="'Gambar ' + type">
            <span v-else class="adm-note">Belum ada gambar.</span>
        </div>
        <p class="adm-note">{{ mediaRecommendation(type, collection, assetKey) }}</p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="adm-input" style="padding:4px;max-width:260px" :disabled="busy" @change="upload">
            <button v-if="url" type="button" class="adm-btn sm danger" :disabled="busy" @click="remove">Hapus gambar</button>
        </div>
        <p v-if="error" class="err">{{ error }}</p>
    </div>
</template>
