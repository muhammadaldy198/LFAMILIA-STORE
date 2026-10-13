<script setup>
import { computed } from 'vue';
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const {customerText} = useCustomerPresentation();

const props = defineProps({
  paymentChannels: { type: Array, default: () => [] },
  selectedCode: { type: String, default: '' },
  quotes: {type:Object, default:()=>({})},
  currentQuote: {type:Object, default:null},
  hasPackage: {type:Boolean, default:false},
  customer: { type: Object, default: null },
});
const emit = defineEmits(['select']);
const paymentChannels = computed(() => props.paymentChannels || []);
const paymentChannelCode = computed(() => props.selectedCode);
const customer = computed(() => props.customer);
const formatIdr = value => 'Rp '+Number(value || 0).toLocaleString('id-ID');
const amount = channel => {
  if (!props.hasPackage) return 'Pilih nominal';
  const value = props.currentQuote?.payment_channel_code === channel?.code ? props.currentQuote : props.quotes[channel?.code];
  return value?.total_idr != null ? formatIdr(value.total_idr) : value?.error ? 'Tidak tersedia' : 'Menghitung…';
};
const groupChannel = group => group.items.find(channel => channel.code === paymentChannelCode.value)
  || group.items.find(channel => channel.available !== false) || group.items[0];


// Grouping, ordering, and selection semantics from LFAMILIA-STORE main.
const paymentGroups = computed(() => {
    const labels = {
        wallet: ['LFAMILIA Coin', 'Bayar memakai LFAMILIA Coin'],
        qris: ['QRIS', 'Scan dari semua aplikasi yang mendukung QRIS'],
        ewallet: ['E-Wallet', 'Bayar melalui aplikasi e-wallet yang tersedia'],
        va: ['Virtual Account', 'Transfer bank dengan nomor VA unik'],
        retail: ['Retail', 'Bayar melalui gerai retail yang tersedia'],
        other: ['Metode Lainnya', 'Metode pembayaran tersedia'],
    };
    const map = new Map();
    for (const channel of props.paymentChannels || []) {
        const key = channel.group || 'other';
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(channel);
    }
    const order = ['wallet', 'qris', 'ewallet', 'va', 'retail', 'other'];
    return [...map.entries()]
        .map(([key, items]) => ({
            key,
            title: labels[key]?.[0] || labels.other[0],
            description: labels[key]?.[1] || labels.other[1],
            items,
        }))
        .sort((left, right) => order.indexOf(left.key) - order.indexOf(right.key));
});
const hasExternalPaymentOption = computed(() => paymentGroups.value.some(group =>
  group.key !== 'wallet' && group.items.some(item => item.available !== false)));
const hasAvailableWalletPaymentOption = computed(() => paymentGroups.value.some(group =>
  group.key === 'wallet' && group.items.some(item => item.available !== false)));

function choosePayment(code) {
    const channel = (props.paymentChannels || []).find(item => item.code === code && item.available !== false);
    if (!channel) return;
    emit('select', channel.code);
}
</script>

<template>
<div class="lf-panel-body lf-payment-groups">
                        <div v-if="!paymentChannels?.length" class="lf-payment-unavailable">
                            {{ customerText("pages.catalog.show.51de2747", "Belum ada metode pembayaran aktif.") }}
                        </div>
                        <div v-else-if="!hasExternalPaymentOption" class="lf-payment-unavailable">
                            <template v-if="customer && hasAvailableWalletPaymentOption">{{ customerText("pages.catalog.show.4bfa0eb", "Pembayaran otomatis belum tersedia. Kamu masih bisa memakai LFAMILIA Coin bila saldo mencukupi.") }}</template>
                            <template v-else>{{ customerText("pages.catalog.show.f4a728b6", "Belum ada metode pembayaran yang dapat digunakan saat ini.") }}</template>
                        </div>
                        <div
                            v-for="group in paymentGroups"
                            :key="group.key"
                            class="lf-payment-group-card"
                            :class="{selected:group.items.some(x=>x.code===paymentChannelCode)}"
                        >
                            <button
                                type="button"
                                class="lf-payment-group-main"
                                :disabled="group.items.every(x=>x.available===false)"
                                @click="choosePayment(group.items.find(x=>x.code===paymentChannelCode)?.code || group.items.find(x=>x.available!==false)?.code)"
                            >
                                <span v-if="group.key==='wallet'" class="lf-payment-wallet-art">
                                    <img :src="groupChannel(group)?.logo_url || '/assets/payment/lfamilia-cash.webp'" :alt="'LFAMILIA Coin'">
                                </span>
                                <span class="lf-payment-group-copy">
                                    <strong>{{group.title}}</strong>
                                    <small v-if="group.key==='wallet' && customer">Saldo {{formatIdr(customer.balance_idr)}}</small>
                                    <small v-else-if="group.key==='wallet'">Masuk akun untuk memakai saldo</small>
                                    <small v-else>{{group.description}}</small>
                                </span>
                                <span v-if="hasPackage" class="lfx-payment-amount">{{amount(groupChannel(group))}}</span>
                                <span v-if="group.items.some(x=>x.code===paymentChannelCode)" class="lf-payment-check"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></span>
                            </button>

                            <div v-if="group.key!=='wallet'" class="lf-payment-brand-strip">
                                <span v-if="group.key==='qris'">{{groupChannel(group)?.description || group.title}}</span>
                                <template v-else>
                                    <span v-for="channel in group.items.slice(0,7)" :key="channel.code">{{channel.name}}</span>
                                </template>
                            </div>

                            <div
                                v-if="group.key!=='wallet' && group.key!=='qris' && group.items.some(x=>x.code===paymentChannelCode)"
                                class="lfx-payment-method-grid"
                                role="radiogroup"
                                :aria-label="'Pilih metode '+group.title"
                            >
                                <div
                                    v-for="channel in group.items"
                                    :key="channel.code"
                                    class="lfx-payment-method-card"
                                    :class="{ 'is-selected': paymentChannelCode === channel.code, 'is-unavailable': channel.available===false }"
                                    role="radio"
                                    :aria-checked="paymentChannelCode===channel.code"
                                    :aria-disabled="channel.available===false"
                                    :tabindex="channel.available===false?-1:0"
                                    @click="choosePayment(channel.code)"
                                    @keydown.enter.prevent="choosePayment(channel.code)"
                                    @keydown.space.prevent="choosePayment(channel.code)"
                                >
                                    <img v-if="channel.logo_url" class="lfx-payment-logo" :src="channel.logo_url" alt=""/><strong class="lfx-payment-method-name">{{channel.name}}</strong>
                                    <span class="lfx-payment-method-total">{{amount(channel)}}</span>
                                </div>
                            </div>
                        </div>
                    </div>
</template>


<style scoped>
.lf-payment-groups{display:grid;gap:8px;padding:20px}
.lf-payment-group-card{overflow:hidden;border:1px solid rgba(255,255,255,.09);border-radius:6.4px;background:rgba(255,255,255,.025);transition:border-color .18s,background .18s}
.lf-payment-group-card.selected{border-color:var(--lf-color-highlight);background:color-mix(in srgb,var(--lf-color-highlight) 6%,transparent)}
.lf-payment-group-main{font-size:13px;line-height:1.5;width:100%;min-height:48px;display:flex;align-items:center;gap:8px;padding:8px 10px;border:0;background:transparent;color:var(--lf-color-text);text-align:left;cursor:pointer}
.lf-payment-wallet-art{display:block;overflow:hidden;border:1px solid rgba(255,255,255,.1);background:#000}
.lf-payment-wallet-art img{display:block;width:100%;height:100%;object-fit:cover}
.lf-payment-group-copy{min-width:0;flex:1}
.lf-payment-group-copy strong{display:block;font-size:11px;line-height:1.2}
.lf-payment-group-copy small{display:block;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lf-color-muted);font-size:9px}
.lf-payment-check{display:grid;width:16px;height:16px;place-items:center;flex:0 0 16px;border-radius:50%;background:var(--lf-color-highlight);color:var(--lf-color-background-alt)}
.lf-payment-check svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2}
.lf-payment-brand-strip{min-height:32px;display:flex;align-items:center;gap:8px;overflow:hidden;padding:6px 12px;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.1);white-space:nowrap}
.lf-payment-brand-strip>span{flex:0 0 auto;border-radius:4px;background:rgba(255,255,255,.08);padding:2px 6px;color:var(--lf-color-muted);font-size:7px;font-weight:700}
.lf-payment-brand-strip>span:only-child{overflow:hidden;max-width:100%;padding:0;background:transparent;text-overflow:ellipsis;font-size:9px}
.lf-payment-unavailable{padding:12px;color:var(--lf-color-muted);font-size:12px}
@media(max-width:639px){.lf-payment-groups{gap:5.6px;padding:16px}.lf-payment-group-main{font-size:12px;min-height:43.2px;gap:5.6px;padding:5.6px 10px}.lf-payment-group-copy strong{font-size:12px}.lf-payment-group-copy small{font-size:10px}.lf-payment-brand-strip{gap:5.6px;padding:6px 8px}.lf-payment-brand-strip>span{font-size:8px}.lf-payment-brand-strip>span:only-child{font-size:10px}}

.lf-payment-wallet-art {
  width: 48px;
  height: 48px;
  flex: 0 0 48px;
  border-radius: 11px;
}

/* Isolated method grid: the global LFAMILIA CSS forces 40px buttons.
   These non-button radio cards are sized entirely within Checkout Lab. */
.lfx-payment-amount {
  flex: 0 0 auto;
  display: inline-block;
  margin-left: auto;
  color: #e2efff;
  font-size: 12px;
  line-height: 1.3;
  font-weight: 850;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.lfx-payment-method-grid {
  box-sizing: border-box;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  grid-auto-rows: auto;
  gap: 12px;
  align-items: stretch;
  width: 100%;
  min-width: 0;
  padding: 14px;
  border-top: 1px solid rgba(190, 213, 240, .14);
  background: rgba(4, 12, 25, .22);
}
.lfx-payment-method-card {
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: space-between;
  gap: 17px;
  min-height: 110px;
  width: 100%;
  min-width: 0;
  height: auto;
  padding: 14px;
  border: 1px solid rgba(166, 190, 226, .28);
  border-radius: 11px;
  background: linear-gradient(145deg, #303f53, #263448);
  color: #eff5ff;
  cursor: pointer;
  text-align: left;
  user-select: none;
}
.lfx-payment-method-card.is-selected {
  border-color: #a6cdfd;
  background: linear-gradient(145deg, #36547b, #293d5b);
  box-shadow: inset 0 0 0 1px rgba(185, 219, 255, .22);
}
.lfx-payment-method-card.is-unavailable {
  opacity: .48;
  cursor: not-allowed;
}
.lfx-payment-method-card:focus-visible {
  outline: 2px solid #add4ff;
  outline-offset: 2px;
}
.lfx-payment-method-name {
  display: block;
  width: 100%;
  min-width: 0;
  font-size: 12px;
  line-height: 1.4;
  font-weight: 850;
  overflow-wrap: anywhere;
  white-space: normal;
}
.lfx-payment-method-total {
  display: block;
  width: 100%;
  min-width: 0;
  padding-top: 10px;
  border-top: 1px solid rgba(215, 231, 255, .22);
  color: #f3f8ff;
  font-size: 13px;
  line-height: 1.25;
  font-weight: 900;
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
@media (max-width: 390px) {
  .lfx-payment-method-grid { gap: 9px; padding: 10px; }
  .lfx-payment-method-card { padding: 12px 10px; gap: 15px; }
  .lfx-payment-method-name { font-size: 11px; }
  .lfx-payment-method-total { font-size: 12px; }
}


/* The source storefront payment styles use 9-11px labels.
   Improve legibility on real desktop monitors without changing mobile behavior. */
@media (min-width:1024px) {
 .lf-payment-group-main { padding:12px 16px; min-height:60px; }
 .lf-payment-group-copy strong { font-size:14px; line-height:1.35; }
 .lf-payment-group-copy small { font-size:12px; line-height:1.45; }
 .lf-payment-brand-strip { padding:9px 15px; }
 .lf-payment-brand-strip > span { font-size:10px; }
 .lfx-payment-amount { font-size:14px; }
 .lfx-payment-method-grid { gap:14px; padding:16px; }
 .lfx-payment-method-card { min-height:119px; padding:16px; }
 .lfx-payment-method-name { font-size:14px; line-height:1.45; }
 .lfx-payment-method-total { font-size:15px; }
}

</style>
