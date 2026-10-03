<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import {Head,Link} from '@inertiajs/vue3';
import {computed,onMounted,onUnmounted,ref} from 'vue';
import CustomerShell from '../../Components/CustomerShell.vue';

const props=defineProps({transactions:Array});
const query=ref('');
const loading=ref(false);
const error=ref('');
const mode=ref('');
const orders=ref([]);
const detail=ref(null);
const feed=ref([...(props.transactions||[])]);
const copied=ref(false);
let detailTimer=null,feedTimer=null;
const detailRefreshing=ref(false);
let disposed=false;
let selectionVersion=0;

const money=v=>'Rp '+Number(v||0).toLocaleString('id-ID');
const statusMeta=s=>({
 pending:['Menunggu Pembayaran','Selesaikan pembayaran sebelum waktu habis.','amber'],
 paid:['Pembayaran Diterima','Pembayaran sudah terverifikasi.','blue'],
 processing:['Sedang Diproses','Pesanan sedang diproses sistem.','blue'],
 success:['Berhasil','Pesanan selesai diproses.','green'],
 failed:['Gagal','Pesanan tidak berhasil diproses.','red'],
 cancelled:['Dibatalkan','Transaksi dibatalkan dan tidak akan diproses.','red'],
 expired:['Kedaluwarsa','Waktu pembayaran telah berakhir.','gray'],
 refunded:['Refund','Pengembalian dana sedang atau telah diproses.','amber'],
}[s]||['Diproses','Status sedang diperbarui.','blue']);
const sourceLabel=s=>({payment:'Pembayaran',processing:'Proses Pesanan',delivery:'Pengiriman',admin:'Admin',system:'Sistem'}[s]||'Sistem');
const date=v=>v?new Date(v).toLocaleString('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}):'-';
const terminal=computed(()=>detail.value&&['success','failed','cancelled','expired','refunded'].includes(detail.value.fulfillmentStatus));

async function json(url,options={}){
 const token=document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';
 const response=await fetch(url,{cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token,...(options.headers||{})},...options});
 const data=await response.json().catch(()=>({}));
 if(!response.ok)throw new Error(data.message||Object.values(data.errors||{})?.[0]?.[0]||'Permintaan gagal.');
 return data;
}
async function search(){
 if(loading.value)return;
 const value=query.value.trim();
 stopDetailPolling();selectionVersion++;detail.value=null;orders.value=[];mode.value='';copied.value=false;
 if(!value){error.value='Masukkan nomor invoice atau nomor WhatsApp.';return;}
 loading.value=true;error.value='';
 const version=selectionVersion;
 try{
  const data=await json('/orders/track/search',{method:'POST',body:JSON.stringify({query:value})});
  if(disposed||version!==selectionVersion)return;
  mode.value=data.mode;
  if(data.mode==='order'){detail.value=data.order;startDetailPolling();}
  else{orders.value=data.orders||[];}
 }catch(e){if(!disposed&&version===selectionVersion)error.value=e.message||'Pesanan tidak ditemukan.'}
 finally{if(!disposed&&version===selectionVersion)loading.value=false;}
}
async function openOrder(trackingToken){
 if(loading.value)return;
 stopDetailPolling();selectionVersion++;detail.value=null;copied.value=false;
 const version=selectionVersion;
 loading.value=true;error.value='';
 try{
  const data=await json('/orders/track/status',{method:'POST',body:JSON.stringify({tracking_token:trackingToken})});
  if(disposed||version!==selectionVersion)return;
  detail.value=data.order;orders.value=[];mode.value='order';startDetailPolling();
 }catch(e){if(!disposed&&version===selectionVersion)error.value=e.message||'Pesanan tidak ditemukan.'}
 finally{if(!disposed&&version===selectionVersion)loading.value=false;}
}
async function refreshDetail(){
 if(!detail.value?.trackingToken||detailRefreshing.value||disposed||document.hidden)return;
 const token=detail.value.trackingToken;
 const version=selectionVersion;
 detailRefreshing.value=true;
 try{
  const data=await json('/orders/track/status',{method:'POST',body:JSON.stringify({tracking_token:token})});
  if(disposed||version!==selectionVersion||detail.value?.trackingToken!==token)return;
  detail.value=data.order;if(terminal.value)stopDetailPolling();
 }catch{
  // Polling failures are transient; keep the last known status and retry on the next interval.
 }finally{detailRefreshing.value=false;}
}
async function refreshFeed(){
 if(disposed||document.hidden)return;
 try{const data=await json('/orders/track/feed');feed.value=data.transactions||feed.value;}catch{
  // Feed refresh is best-effort; keep the current feed until the next poll succeeds.
 }
}
function startDetailPolling(){stopDetailPolling();if(!terminal.value)detailTimer=setInterval(refreshDetail,15000);}
function stopDetailPolling(){if(detailTimer){clearInterval(detailTimer);detailTimer=null;}}
async function copyInvoice(){if(!detail.value?.referenceId||detail.value?.referenceMasked)return;await navigator.clipboard?.writeText(detail.value.referenceId);copied.value=true;setTimeout(()=>copied.value=false,1400);}
onMounted(()=>{feedTimer=setInterval(refreshFeed,30000);});
onUnmounted(()=>{disposed=true;selectionVersion++;stopDetailPolling();if(feedTimer)clearInterval(feedTimer);});
</script>

<template>
<Head :title="customerText('pages.guest.track.attribute.title.196596ce', 'Cek Pesanan')"/>
<CustomerShell>
<main class="lf-track-page">
 <section class="lf-container lf-track-hero">
  <div>
   <p class="lf-eyebrow">{{ customerText("pages.guest.track.c6bebc5b", "PELACAKAN TRANSAKSI") }}</p>
   <h1>{{ customerText("pages.guest.track.aa9778ee", "Cek status pesananmu") }}</h1>
   <p>{{ customerText("pages.guest.track.ed2fdbf1", "Masukkan nomor invoice atau nomor WhatsApp yang dipakai saat checkout. Status diperbarui otomatis dari server.") }}</p>
  </div>
  <div class="lf-track-search">
   <svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3m1.3-5.7a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
   <input v-model="query" @keyup.enter="search" :aria-label="customerText('pages.guest.track.attribute.aria-label.6395bd69', 'Nomor invoice atau nomor WhatsApp')" :placeholder="customerText('pages.guest.track.attribute.placeholder.e4947fc0', 'Masukkan nomor invoice atau nomor WhatsApp')">
   <button :disabled="loading" @click="search">{{loading?'Mencari...':'Periksa'}}</button>
  </div>
  <p v-if="error" role="alert" class="lf-track-error">{{error}}</p>
 </section>

 <p v-if="mode==='phone' && !orders.length && !loading && !error" class="lf-container lf-empty" role="status">{{ customerText("pages.guest.track.45250ba7", "Tidak ada pesanan untuk nomor WhatsApp ini. Periksa kembali nomor yang digunakan saat checkout.") }}</p>

 <section v-if="orders.length" class="lf-container lf-track-results">
  <div class="lf-track-section-head"><div><p class="lf-eyebrow">{{ customerText("pages.guest.track.385a9fe9", "RIWAYAT NOMOR") }}</p><h2>{{ customerText("pages.guest.track.6a63c25b", "Pesanan ditemukan") }}</h2></div><span>{{orders.length}} transaksi</span></div>
  <div class="lf-track-order-list">
   <button v-for="item in orders" :key="item.trackingToken" :disabled="loading" @click="openOrder(item.trackingToken)">
    <div><strong>{{item.maskedReferenceId}}</strong><small>{{item.productName}} · {{item.packageLabel}}</small></div>
    <div><b>{{money(item.total)}}</b><span :class="'status-'+item.status">{{statusMeta(item.status)[0]}}</span></div>
   </button>
  </div>
 </section>

 <section v-if="detail" class="lf-container lf-track-detail">
  <div class="lf-track-detail-head">
   <div><p class="lf-eyebrow">{{ customerText("pages.guest.track.dab1e3ee", "DETAIL TRANSAKSI") }}</p><h2>{{detail.productName}}</h2><p>{{detail.packageLabel}}</p></div>
   <span class="lf-live-badge" :class="{done:terminal}"><i></i>{{terminal?'STATUS FINAL':'LIVE'}}</span>
  </div>

  <div class="lf-track-grid">
   <div class="lf-track-summary">
    <div class="lf-track-invoice">
     <div><small>{{ customerText("pages.guest.track.b8d5b0b7", "Nomor Invoice") }}</small><strong>{{detail.referenceId}}</strong></div>
     <button v-if="!detail.referenceMasked" @click="copyInvoice">{{copied?'Tersalin':'Salin'}}</button><span v-else class="text-[8px] font-bold uppercase tracking-[0.08em] text-white/35">{{ customerText("pages.guest.track.cbd3ad32", "Terlindungi") }}</span>
    </div>
    <div class="lf-track-status-card" :class="'tone-'+statusMeta(detail.fulfillmentStatus)[2]">
     <span></span><div><small>{{ customerText("pages.guest.track.a17f89cb", "Status Pesanan") }}</small><strong>{{statusMeta(detail.fulfillmentStatus)[0]}}</strong><p>{{statusMeta(detail.fulfillmentStatus)[1]}}</p></div>
    </div>
    <dl class="lf-track-meta">
     <div><dt>{{ customerText("pages.guest.track.ceb4d888", "Produk") }}</dt><dd>{{detail.productName}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.a2defbe3", "Nominal") }}</dt><dd>{{detail.packageLabel}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.65ca564", "Tujuan") }}</dt><dd>{{detail.destination||'Disembunyikan'}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.443c0671", "Metode Pembayaran") }}</dt><dd>{{detail.paymentMethod}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.da472e8b", "Status Pembayaran") }}</dt><dd>{{statusMeta(detail.paymentStatus)[0]}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.ad066d9d", "Total") }}</dt><dd class="money">{{money(detail.total)}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.e378eb96", "Dibuat") }}</dt><dd>{{date(detail.createdAt)}}</dd></div>
     <div><dt>{{ customerText("pages.guest.track.6e230e94", "Update") }}</dt><dd>{{date(detail.updatedAt)}}</dd></div>
    </dl>
    <div class="lf-track-sensitive">
     <strong>{{ customerText("pages.guest.track.ee28e295", "Butuh kode/hasil pesanan?") }}</strong>
     <p>{{ customerText("pages.guest.track.84d645e7", "Data sensitif hanya ditampilkan melalui halaman akses guest yang memakai kode akses, atau dari Riwayat Pesanan jika kamu login.") }}</p>
     <Link href="/login" class="lf-secondary">{{ customerText("pages.guest.track.da4f28f7", "Masuk Akun") }}</Link>
    </div>
   </div>

   <aside class="lf-track-timeline">
    <header><div><h3>{{ customerText("pages.guest.track.67f44f0e", "Log Realtime") }}</h3><p>{{ customerText("pages.guest.track.cea3f93c", "Sinkron setiap 15 detik") }}</p></div><button :disabled="detailRefreshing" :aria-label="customerText('pages.guest.track.attribute.aria-label.76774c83', 'Perbarui status pesanan')" @click="refreshDetail">↻</button></header>
    <ol>
     <li v-for="event in detail.events" :key="event.id">
      <i></i><div><small>{{sourceLabel(event.source)}}</small><strong>{{event.label}}</strong><time>{{date(event.createdAt)}}</time></div>
     </li>
     <li v-if="!detail.events?.length"><i></i><div><small>{{ customerText("pages.guest.track.59bb7a0c", "Sistem") }}</small><strong>{{ customerText("pages.guest.track.243a5ea5", "Pesanan tercatat") }}</strong><time>{{ customerText("pages.guest.track.1b9e33ac", "Menunggu pembaruan berikutnya") }}</time></div></li>
    </ol>
   </aside>
  </div>
 </section>

 <section class="lf-track-feed">
  <div class="lf-container">
   <div class="lf-track-section-head"><div><p class="lf-eyebrow">{{ customerText("pages.guest.track.8c613442", "TRANSAKSI TERBARU") }}</p><h2>{{ customerText("pages.guest.track.785e2064", "Aktivitas LFAMILIA") }}</h2><p>{{ customerText("pages.guest.track.4a372d30", "Invoice dimasking untuk menjaga privasi pelanggan.") }}</p></div><span class="lf-live-badge"><i></i>{{ customerText("pages.guest.track.45424caf", "LIVE") }}</span></div>
   <div class="lf-feed-table">
    <div class="lf-feed-row lf-feed-head"><span>{{ customerText("pages.guest.track.71ec87b0", "Invoice") }}</span><span>{{ customerText("pages.guest.track.ceb4d888", "Produk") }}</span><span>{{ customerText("pages.guest.track.ad066d9d", "Total") }}</span><span>{{ customerText("pages.guest.track.5ef20f", "Status") }}</span><span>{{ customerText("pages.guest.track.4293eacd", "Waktu") }}</span></div>
    <div v-for="item in feed" :key="item.maskedReferenceId+item.createdAt" class="lf-feed-row">
     <strong>{{item.maskedReferenceId}}</strong><span>{{item.productName}}<small>{{item.packageLabel}}</small></span><b>{{money(item.total)}}</b><em :class="'status-'+item.status">{{statusMeta(item.status)[0]}}</em><time>{{date(item.createdAt)}}</time>
    </div>
    <div v-if="!feed.length" class="lf-empty">{{ customerText("pages.guest.track.faa60eef", "Belum ada transaksi untuk ditampilkan.") }}</div>
   </div>
  </div>
 </section>
</main>
</CustomerShell>
</template>
