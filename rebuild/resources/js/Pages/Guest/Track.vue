<script setup>
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

const money=v=>'Rp '+Number(v||0).toLocaleString('id-ID');
const statusMeta=s=>({
 pending:['Menunggu Pembayaran','Selesaikan pembayaran sebelum waktu habis.','amber'],
 paid:['Pembayaran Diterima','Pembayaran sudah terverifikasi.','blue'],
 processing:['Sedang Diproses','Pesanan sedang diproses sistem.','blue'],
 success:['Berhasil','Pesanan selesai diproses.','green'],
 failed:['Gagal','Pesanan tidak berhasil diproses.','red'],
 expired:['Kedaluwarsa','Waktu pembayaran telah berakhir.','gray'],
 refunded:['Refund','Pengembalian dana sedang atau telah diproses.','amber'],
}[s]||['Diproses','Status sedang diperbarui.','blue']);
const sourceLabel=s=>({payment:'Pembayaran',processing:'Proses Pesanan',delivery:'Pengiriman',admin:'Admin',system:'Sistem'}[s]||'Sistem');
const date=v=>v?new Date(v).toLocaleString('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}):'-';
const terminal=computed(()=>detail.value&&['success','failed','expired','refunded'].includes(detail.value.fulfillmentStatus));

async function json(url,options={}){
 const token=document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';
 const response=await fetch(url,{cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token,...(options.headers||{})},...options});
 const data=await response.json().catch(()=>({}));
 if(!response.ok)throw new Error(data.message||Object.values(data.errors||{})?.[0]?.[0]||'Permintaan gagal.');
 return data;
}
async function search(){
 const value=query.value.trim();if(!value)return;
 loading.value=true;error.value='';
 try{
  const data=await json('/orders/track/search',{method:'POST',body:JSON.stringify({query:value})});
  mode.value=data.mode;
  if(data.mode==='order'){orders.value=[];detail.value=data.order;startDetailPolling();}
  else{detail.value=null;orders.value=data.orders||[];stopDetailPolling();}
 }catch(e){error.value=e.message||'Pesanan tidak ditemukan.'}finally{loading.value=false;}
}
async function openOrder(refId){
 loading.value=true;error.value='';
 try{const data=await json('/orders/track/status/'+encodeURIComponent(refId));detail.value=data.order;mode.value='order';startDetailPolling();}
 catch(e){error.value=e.message||'Pesanan tidak ditemukan.'}finally{loading.value=false;}
}
async function refreshDetail(){
 if(!detail.value?.referenceId)return;
 try{const data=await json('/orders/track/status/'+encodeURIComponent(detail.value.referenceId));detail.value=data.order;if(terminal.value)stopDetailPolling();}catch{}
}
async function refreshFeed(){
 try{const data=await json('/orders/track/feed');feed.value=data.transactions||feed.value;}catch{}
}
function startDetailPolling(){stopDetailPolling();if(!terminal.value)detailTimer=setInterval(refreshDetail,3000);}
function stopDetailPolling(){if(detailTimer){clearInterval(detailTimer);detailTimer=null;}}
async function copyInvoice(){if(!detail.value?.referenceId)return;await navigator.clipboard?.writeText(detail.value.referenceId);copied.value=true;setTimeout(()=>copied.value=false,1400);}
onMounted(()=>{feedTimer=setInterval(refreshFeed,15000);});
onUnmounted(()=>{stopDetailPolling();if(feedTimer)clearInterval(feedTimer);});
</script>

<template>
<Head title="Cek Pesanan"/>
<CustomerShell>
<main class="lf-track-page">
 <section class="lf-container lf-track-hero">
  <div>
   <p class="lf-eyebrow">PELACAKAN TRANSAKSI</p>
   <h1>Cek status pesananmu</h1>
   <p>Masukkan nomor invoice atau nomor WhatsApp yang dipakai saat checkout. Status diperbarui otomatis dari server.</p>
  </div>
  <div class="lf-track-search">
   <svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3m1.3-5.7a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
   <input v-model="query" @keyup.enter="search" placeholder="Contoh: LF260930 atau 081234567890">
   <button :disabled="loading" @click="search">{{loading?'Mencari...':'Periksa'}}</button>
  </div>
  <p v-if="error" class="lf-track-error">{{error}}</p>
 </section>

 <section v-if="orders.length" class="lf-container lf-track-results">
  <div class="lf-track-section-head"><div><p class="lf-eyebrow">RIWAYAT NOMOR</p><h2>Pesanan ditemukan</h2></div><span>{{orders.length}} transaksi</span></div>
  <div class="lf-track-order-list">
   <button v-for="item in orders" :key="item.referenceId" @click="openOrder(item.referenceId)">
    <div><strong>{{item.maskedReferenceId}}</strong><small>{{item.productName}} · {{item.packageLabel}}</small></div>
    <div><b>{{money(item.total)}}</b><span :class="'status-'+item.status">{{statusMeta(item.status)[0]}}</span></div>
   </button>
  </div>
 </section>

 <section v-if="detail" class="lf-container lf-track-detail">
  <div class="lf-track-detail-head">
   <div><p class="lf-eyebrow">DETAIL TRANSAKSI</p><h2>{{detail.productName}}</h2><p>{{detail.packageLabel}}</p></div>
   <span class="lf-live-badge" :class="{done:terminal}"><i></i>{{terminal?'STATUS FINAL':'LIVE'}}</span>
  </div>

  <div class="lf-track-grid">
   <div class="lf-track-summary">
    <div class="lf-track-invoice">
     <div><small>Nomor Invoice</small><strong>{{detail.referenceId}}</strong></div>
     <button @click="copyInvoice">{{copied?'Tersalin':'Salin'}}</button>
    </div>
    <div class="lf-track-status-card" :class="'tone-'+statusMeta(detail.fulfillmentStatus)[2]">
     <span></span><div><small>Status Pesanan</small><strong>{{statusMeta(detail.fulfillmentStatus)[0]}}</strong><p>{{statusMeta(detail.fulfillmentStatus)[1]}}</p></div>
    </div>
    <dl class="lf-track-meta">
     <div><dt>Produk</dt><dd>{{detail.productName}}</dd></div>
     <div><dt>Nominal</dt><dd>{{detail.packageLabel}}</dd></div>
     <div><dt>Tujuan</dt><dd>{{detail.destination||'Disembunyikan'}}</dd></div>
     <div><dt>Metode Pembayaran</dt><dd>{{detail.paymentMethod}}</dd></div>
     <div><dt>Status Pembayaran</dt><dd>{{statusMeta(detail.paymentStatus)[0]}}</dd></div>
     <div><dt>Total</dt><dd class="money">{{money(detail.total)}}</dd></div>
     <div><dt>Dibuat</dt><dd>{{date(detail.createdAt)}}</dd></div>
     <div><dt>Update</dt><dd>{{date(detail.updatedAt)}}</dd></div>
    </dl>
    <div class="lf-track-sensitive">
     <strong>Butuh kode/hasil pesanan?</strong>
     <p>Data sensitif hanya ditampilkan melalui halaman akses guest yang memakai kode akses, atau dari Riwayat Pesanan jika kamu login.</p>
     <Link href="/login" class="lf-secondary">Masuk Akun</Link>
    </div>
   </div>

   <aside class="lf-track-timeline">
    <header><div><h3>Log Realtime</h3><p>Sinkron setiap 3 detik</p></div><button @click="refreshDetail">↻</button></header>
    <ol>
     <li v-for="event in detail.events" :key="event.id">
      <i></i><div><small>{{sourceLabel(event.source)}}</small><strong>{{event.label}}</strong><time>{{date(event.createdAt)}}</time></div>
     </li>
     <li v-if="!detail.events?.length"><i></i><div><small>Sistem</small><strong>Pesanan tercatat</strong><time>Menunggu pembaruan berikutnya</time></div></li>
    </ol>
   </aside>
  </div>
 </section>

 <section class="lf-track-feed">
  <div class="lf-container">
   <div class="lf-track-section-head"><div><p class="lf-eyebrow">TRANSAKSI TERBARU</p><h2>Aktivitas LFAMILIA</h2><p>Invoice dimasking untuk menjaga privasi pelanggan.</p></div><span class="lf-live-badge"><i></i>LIVE</span></div>
   <div class="lf-feed-table">
    <div class="lf-feed-row lf-feed-head"><span>Invoice</span><span>Produk</span><span>Total</span><span>Status</span><span>Waktu</span></div>
    <div v-for="item in feed" :key="item.maskedReferenceId+item.createdAt" class="lf-feed-row">
     <strong>{{item.maskedReferenceId}}</strong><span>{{item.productName}}<small>{{item.packageLabel}}</small></span><b>{{money(item.total)}}</b><em :class="'status-'+item.status">{{statusMeta(item.status)[0]}}</em><time>{{date(item.createdAt)}}</time>
    </div>
    <div v-if="!feed.length" class="lf-empty">Belum ada transaksi untuk ditampilkan.</div>
   </div>
  </div>
 </section>
</main>
</CustomerShell>
</template>
