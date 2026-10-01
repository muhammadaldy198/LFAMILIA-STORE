<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useCustomerPresentation } from '../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();
const props = defineProps({ fields: { type: Object, required: true }, validateForm: Boolean });
const page = usePage();
const feedback = ref('');
const manualCopy = ref(false);
const number = computed(() => String(page.props.storefront?.supportWhatsapp || '').replace(/\D/g, '').replace(/^0/, '62'));
const labels = { kind: 'Jenis bantuan', name: 'Nama', phone: 'Nomor WhatsApp', invoice: 'Nomor invoice', subject: 'Subjek', message: 'Pesan' };
const message = computed(() => [customerText('support.messageTitle', 'FORMULIR BANTUAN LFAMILIA STORE'), ...Object.entries(labels).filter(([key]) => String(props.fields[key] || '').trim()).map(([key, label]) => customerText('support.field.' + key, label) + ': ' + String(props.fields[key]).trim())].join('\n'));
const whatsappUrl = computed(() => number.value ? 'https://wa.me/' + number.value + '?text=' + encodeURIComponent(message.value) : '');
function valid(event) {
    feedback.value = '';
    if (props.validateForm && !event.currentTarget.closest('form')?.reportValidity()) return false;
    if (!String(props.fields.message || '').trim()) {
        feedback.value = customerText('support.requiredMessage', 'Isi pesan bantuan terlebih dahulu.');
        return false;
    }
    return true;
}
async function copy(event) {
    if (!valid(event)) return;
    try {
        if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
        await navigator.clipboard.writeText(message.value);
        manualCopy.value = false;
        feedback.value = customerText('support.copied', 'Pesan bantuan berhasil disalin.');
    } catch {
        manualCopy.value = true;
        feedback.value = customerText('support.copyFallback', 'Salin teks pesan di bawah secara manual.');
    }
}
function whatsapp(event) {
    if (!valid(event) || !whatsappUrl.value) return;
    window.open(whatsappUrl.value, '_blank', 'noopener,noreferrer');
}
</script>
<template>
 <div class="lf-support-message-actions">
  <button type="button" class="lf-primary" :disabled="!number" @click="whatsapp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>{{ customerText('support.whatsapp', 'Kirim lewat WhatsApp') }}</button>
  <button type="button" class="lf-secondary" @click="copy"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="5" rx="1"/></svg>{{ customerText('support.copy', 'Salin') }}</button>
  <p v-if="!number" class="lf-copy">{{ customerText('support.whatsappUnavailable', 'Kanal WhatsApp belum tersedia. Kamu tetap bisa menyalin pesan atau membuat tiket bantuan.') }}</p>
  <p v-if="feedback" role="status" aria-live="polite">{{ feedback }}</p>
  <textarea v-if="manualCopy" readonly :value="message" rows="8" :aria-label="customerText('support.copyPreview', 'Pesan bantuan untuk disalin')" @focus="$event.target.select()"></textarea>
 </div>
</template>
<style scoped>
.lf-support-message-actions{display:grid;gap:10px;margin-top:16px;min-width:0}
.lf-support-message-actions button{display:flex;width:100%;align-items:center;justify-content:center;gap:10px}
.lf-support-message-actions svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.lf-support-message-actions textarea{width:100%}
.lf-support-message-actions button:disabled{opacity:.5;cursor:not-allowed}
</style>
