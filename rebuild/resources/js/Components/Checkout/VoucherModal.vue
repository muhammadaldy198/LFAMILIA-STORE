<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

defineProps({
  vouchers: { type: Array, default: () => [] },
  loading: Boolean,
  error: {type:String, default:''},
});
const emit = defineEmits(['close', 'apply']);
const closeButton = ref(null);
function onKeydown(event) {
  if (event.key === 'Escape') emit('close');
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown);
  closeButton.value?.focus();
});
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
  
  <Teleport to="body">
    <div class="lfx-offer-backdrop" @click.self="emit('close')">
      <section class="lfx-offer-modal" role="dialog" aria-modal="true" aria-labelledby="lfx-offer-heading">
        <header class="lfx-offer-heading">
          <div class="lfx-offer-heading-copy">
            <span>EXCLUSIVE OFFERS</span>
            <h2 id="lfx-offer-heading">Pilih Voucher Diskon</h2>
          </div>
          <button ref="closeButton" type="button" class="lfx-offer-close" aria-label="Tutup voucher" @click="emit('close')">×</button>
        </header>
        <p class="lfx-offer-description">Pilih voucher yang berlaku untuk pesananmu.</p>
        <p v-if="loading">Memuat voucher…</p><p v-else-if="error" role="alert">{{error}}</p><p v-else-if="!vouchers.length">Belum ada voucher yang aktif saat ini.</p><div v-else class="lfx-offer-list">
          <article v-for="item in vouchers" :key="item.code" class="lfx-offer-card">
            <div class="lfx-offer-icon" aria-hidden="true">%</div>
            <div class="lfx-offer-copy">
              <strong>Hemat Rp {{Number(item.discount_idr || 0).toLocaleString('id-ID')}}</strong>
              <span>Minimum Rp {{Number(item.minimum_total_idr || 0).toLocaleString('id-ID')}}<template v-if="item.ends_at"> · Berlaku hingga {{new Date(item.ends_at).toLocaleDateString('id-ID')}}</template></span>
              <code>{{ item.code }}</code>
            </div>
            <button type="button" class="lfx-offer-apply" :aria-label="'Pakai voucher '+item.code" @click="emit('apply',item.code)">Pakai <span aria-hidden="true">→</span></button>
          </article>
        </div>
        <p class="lfx-offer-disclaimer"></p>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.lfx-offer-backdrop, .lfx-offer-backdrop * { box-sizing: border-box; }
.lfx-offer-backdrop {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: grid;
  place-items: center;
  padding: max(16px, env(safe-area-inset-top)) 16px max(16px, env(safe-area-inset-bottom));
  background: rgba(3, 7, 18, .82);
  backdrop-filter: blur(8px);
  color: #f1f5ff;
  font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
}
.lfx-offer-modal {
  width: min(100%, 460px);
  max-height: min(700px, calc(100dvh - 32px));
  overflow-y: auto;
  border: 1px solid rgba(139, 169, 221, .34);
  border-radius: 18px;
  padding: 22px;
  background: linear-gradient(155deg, #202d47 0%, #111b2d 100%);
  box-shadow: 0 20px 60px #000a;
}
.lfx-offer-heading {
  display: flex;
  gap: 12px;
  justify-content: space-between;
  align-items: flex-start;
}
.lfx-offer-heading-copy { min-width: 0; }
.lfx-offer-heading-copy > span {
  display: block;
  color: #9dbaff;
  font-size: 10px;
  font-weight: 900;
  letter-spacing: .15em;
}
.lfx-offer-heading-copy h2 {
  margin: 8px 0 0;
  font-size: clamp(18px, 4.5vw, 22px);
  line-height: 1.3;
  font-weight: 850;
}
.lfx-offer-close {
  display: grid;
  place-items: center;
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  border-radius: 9px;
  border: 1px solid #ffffff27;
  color: #dee7fa;
  background: #ffffff0d;
  font-size: 23px;
  cursor: pointer;
}
.lfx-offer-description {
  font-size: 12px;
  line-height: 1.5;
  margin: 16px 0 18px;
  color: #b6c5dd;
}
.lfx-offer-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lfx-offer-card {
  display: grid;
  grid-template-columns: 42px minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  min-width: 0;
  padding: 13px;
  border: 1px solid #9fbaf043;
  border-radius: 12px;
  background: #2b3b55;
}
.lfx-offer-card:nth-child(even) {
  border-color: #b5a2ed50;
  background: #363154;
}
.lfx-offer-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: #a5bfff20;
  color: #c9d8fb;
  font-size: 23px;
  font-weight: 900;
}
.lfx-offer-copy {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  line-height: 1.35;
}
.lfx-offer-copy strong {
  font-size: 13px;
  color: #f4f7ff;
  font-weight: 850;
  overflow-wrap: anywhere;
}
.lfx-offer-copy > span {
  font-size: 11px;
  color: #b6c6df;
  overflow-wrap: anywhere;
}
.lfx-offer-copy code {
  align-self: flex-start;
  max-width: 100%;
  margin-top: 2px;
  color: #d6e9ff;
  font: 800 10px/1.3 ui-monospace, monospace;
  overflow-wrap: anywhere;
}
.lfx-offer-apply {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  flex: 0 0 auto;
  min-width: 65px;
  min-height: 38px;
  padding: 8px 10px;
  border: 1px solid #9cbefd60;
  border-radius: 9px;
  background: #5475ba;
  color: white;
  font-size: 11px;
  font-weight: 850;
  cursor: pointer;
  white-space: nowrap;
}
.lfx-offer-apply:hover, .lfx-offer-apply:focus-visible { background: #6489d1; }
.lfx-offer-disclaimer {
  margin: 17px 0 0;
  color: #9cabc2;
  text-align: center;
  font-size: 10px;
  line-height: 1.5;
}
@media (max-width: 370px) {
  .lfx-offer-modal { padding: 17px; }
  .lfx-offer-card { grid-template-columns: 34px minmax(0,1fr) auto; gap: 8px; padding: 10px; }
  .lfx-offer-icon { width: 34px; height: 34px; font-size: 19px; }
  .lfx-offer-apply { min-width: 54px; padding: 6px; font-size: 10px; }
}
</style>
