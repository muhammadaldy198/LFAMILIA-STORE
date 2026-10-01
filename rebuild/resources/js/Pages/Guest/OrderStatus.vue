<script setup>
import { useCustomerPresentation } from '../../Composables/customerPresentation';
const { customerText } = useCustomerPresentation();

import {Head,Link} from '@inertiajs/vue3';
import {computed,onMounted,onUnmounted,ref} from 'vue';
import {rupiah} from '../../lib/money';
import CustomerShell from '../../Components/CustomerShell.vue';

const props=defineProps({order:Object,payment:Object,events:Array,review:Object});
const orderState=ref({...props.order});
const paymentState=ref(props.payment);
const timeline=ref([...(props.events||[])]);
const errors=ref({});
const busy=ref(false);
const refreshing=ref(false);
let disposed=false;
const reviewRating=ref(Number(props.review?.rating||5));
const reviewBody=ref(props.review?.body||'');
const reviewName=ref('');
const reviewDone=ref(Boolean(props.review));
const reviewMessage=ref('');
let timer=null;

const terminal=computed(()=>['SUCCESS','FAILED','CANCELLED','EXPIRED','REFUND','REFUNDED'].includes(orderState.value.status));
const manualQris=computed(()=>paymentState.value?.instructions?.kind==='manual_qris'
    || String(paymentState.value?.channel_code||'').toLowerCase().includes('manual'));
const paymentUncertain=computed(()=>['UNKNOWN','CREATING'].includes(paymentState.value?.status));
const statusLabel=s=>({
    PENDING_PAYMENT:'Menunggu Pembayaran',PENDING:'Menunggu Pembayaran',CREATING:'Menyiapkan Pembayaran',
    PAID:'Pembayaran Diterima',PROCESSING:'Sedang Diproses',SUCCESS:'Berhasil',FAILED:'Gagal',
    CANCELLED:'Dibatalkan',EXPIRED:'Kedaluwarsa',REFUNDED:'Refund',REFUND:'Refund',
    REJECTED:'Ditolak',UNKNOWN:'Status Belum Pasti',
}[String(s||'').toUpperCase()]||String(s||'-').replaceAll('_',' '));
const eventLabel=e=>{
    if(e.to_status) return statusLabel(e.to_status);
    const map={
        'order.created':'Pesanan dibuat',
        'payment.created':'Pembayaran dibuat',
        'payment.updated':'Status pembayaran diperbarui',
        'fulfillment.started':'Pesanan diteruskan untuk diproses',
        'fulfillment.updated':'Proses pesanan diperbarui',
    };
    return map[e.event_type]||'Status pesanan diperbarui';
};
const eventTime=v=>v?new Date(v).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',second:'2-digit'}):'-';

async function refreshStatus(){
    if(disposed||refreshing.value||document.hidden)return;
    refreshing.value=true;
    try{
        const response=await fetch('/orders/guest/'+encodeURIComponent(orderState.value.order_number)+'/events',{
            headers:{Accept:'application/json'},cache:'no-store',
        });
        if(!response.ok)return;
        const data=await response.json();
        errors.value={};
        if(disposed)return;
        orderState.value={...orderState.value,...(data.order||{})};
        paymentState.value=data.payment||paymentState.value;
        timeline.value=data.events||timeline.value;
        if(terminal.value&&timer){clearInterval(timer);timer=null;}
    }catch{
        errors.value={status:['Status belum dapat diperbarui. Coba cek lagi.']};
    }finally{refreshing.value=false;}
}

async function submitReview(){
    if(reviewDone.value||orderState.value.status!=='SUCCESS')return;
    reviewMessage.value='';busy.value=true;
    try{
        const token=document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';
        const response=await fetch('/reviews',{
            method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},
            body:JSON.stringify({product_slug:orderState.value.product_slug,order_number:orderState.value.order_number,rating:Number(reviewRating.value),body:reviewBody.value,display_name:reviewName.value||null}),
        });
        const data=await response.json().catch(()=>({}));
        if(!response.ok){reviewMessage.value=data.message||Object.values(data.errors||{})?.[0]?.[0]||'Ulasan gagal dikirim.';return;}
        reviewDone.value=true;reviewMessage.value=data.message||'Ulasan berhasil dikirim.';
    }finally{busy.value=false;}
}

onMounted(()=>{refreshStatus();if(!terminal.value)timer=setInterval(refreshStatus,15000);});
onUnmounted(()=>{disposed=true;if(timer)clearInterval(timer);});
</script>

<template>
<Head :title="customerText(&quot;pages.guest.orderstatus.attribute.title.a17f89cb&quot;, &quot;Status Pesanan&quot;)"/>
<CustomerShell>
    <main class="lf-container lf-order-status-page">
        <Link href="/orders/check" class="lf-back">{{ customerText("pages.guest.orderstatus.860516c2", "← Cek pesanan lain") }}</Link>
        <section class="lf-order-status-head">
            <div>
                <p class="lf-eyebrow">{{ customerText("pages.guest.orderstatus.31b95ca5", "STATUS TRANSAKSI") }}</p>
                <h1>Pesanan {{orderState.order_number}}</h1>
                <p>{{ customerText("pages.guest.orderstatus.fad6f05b", "Log di bawah diperbarui otomatis dari server.") }}</p>
            </div>
            <span class="lf-live-badge" :class="{done:terminal}"><i></i>{{terminal?'Status final':'LIVE'}}</span>
        </section>

        <div class="lf-order-status-grid">
            <div class="space-y-3">
                <section class="lf-order-info">
                    <div><small>{{ customerText("pages.guest.orderstatus.ceb4d888", "Produk") }}</small><strong>{{orderState.product_name}}</strong></div>
                    <div><small>{{ customerText("pages.guest.orderstatus.5ef20f", "Status") }}</small><strong>{{statusLabel(orderState.status)}}</strong></div>
                    <div><small>{{ customerText("pages.guest.orderstatus.ad066d9d", "Total") }}</small><strong>{{rupiah(orderState.total_idr)}}</strong></div>
                    <div><small>{{ customerText("pages.guest.orderstatus.e378eb96", "Dibuat") }}</small><strong>{{orderState.created_at}}</strong></div>
                </section>

                <section v-if="orderState.status==='PENDING_PAYMENT'" class="lf-order-payment">
                    <h2>{{ customerText("pages.guest.orderstatus.3527df23", "Pembayaran") }}</h2>
                    <p v-if="paymentState">{{ customerText("pages.guest.orderstatus.ca77496f", "Status:") }} <strong>{{statusLabel(paymentState.status)}}</strong></p>
                    <p v-if="paymentUncertain">{{ customerText("pages.guest.orderstatus.fc9d0f83", "Status pembayaran sedang dipastikan. Jangan membayar ulang.") }}</p>
                    <img v-if="!paymentUncertain&&paymentState?.instructions?.qr_url" :src="paymentState.instructions.qr_url" :alt="customerText(&quot;pages.guest.orderstatus.attribute.alt.dfd028b8&quot;, &quot;QRIS pembayaran&quot;)">
                    <p v-if="paymentState?.instructions?.va_number">{{ customerText("pages.guest.orderstatus.d5aec755", "Nomor VA:") }} <strong>{{paymentState.instructions.va_number}}</strong></p>
                    <p v-if="paymentState?.instructions?.payment_code">{{ customerText("pages.guest.orderstatus.6af68bec", "Kode pembayaran:") }} <strong>{{paymentState.instructions.payment_code}}</strong></p>
                    <div v-if="manualQris&&paymentState&&!paymentUncertain" class="mt-3 rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-3">
                        <strong class="text-[10px] text-amber-200">{{ customerText("pages.guest.orderstatus.666bb8c9", "Menunggu verifikasi QRIS manual") }}</strong>
                        <p class="mt-1 text-[9px] leading-4 text-white/45">{{ customerText("pages.guest.orderstatus.19bf8418", "Setelah membayar, status tetap Menunggu Pembayaran sampai Admin memverifikasi transaksi. Jangan melakukan pembayaran kedua untuk invoice yang sama.") }}</p>
                    </div>
                    <Link :href="'/payment?invoice='+encodeURIComponent(orderState.order_number)" class="lf-primary mt-3">{{ customerText("pages.guest.orderstatus.a548f73b", "Lihat pembayaran") }}</Link>
                    
                </section>

                <section v-if="orderState.status==='SUCCESS'&&orderState.delivery" class="lf-order-success">
                    <h2>{{ customerText("pages.guest.orderstatus.25ba9702", "Hasil pesanan") }}</h2>
                    <p v-if="orderState.delivery.serial_number">{{ customerText("pages.guest.orderstatus.4fc61831", "Serial / SN:") }} <strong>{{orderState.delivery.serial_number}}</strong></p>
                    <p v-if="orderState.delivery.code">{{ customerText("pages.guest.orderstatus.8f52a70a", "Kode / hasil:") }} <strong>{{orderState.delivery.code}}</strong></p>
                    <p v-if="orderState.delivery.note">{{orderState.delivery.note}}</p>
                </section>

                <section v-if="orderState.status==='SUCCESS'" class="lf-order-review">
                    <template v-if="reviewDone">
                        <h2>{{ customerText("pages.guest.orderstatus.f93e4117", "Ulasan kamu") }}</h2>
                        <div class="lf-review-stars">{{'★'.repeat(reviewRating)}}{{'☆'.repeat(5-reviewRating)}}</div>
                        <p>{{reviewBody || 'Terima kasih sudah memberi ulasan.'}}</p>
                    </template>
                    <template v-else>
                        <h2>{{ customerText("pages.guest.orderstatus.20c3ee9d", "Beri ulasan") }}</h2>
                        <p>{{ customerText("pages.guest.orderstatus.e840ad58", "Ulasan hanya tersedia untuk pesanan yang sudah berhasil.") }}</p>
                        <div class="lf-order-review-form">
                            <input v-model="reviewName" maxlength="100" :placeholder="customerText(&quot;pages.guest.orderstatus.attribute.placeholder.2fadcd07&quot;, &quot;Masukkan nama tampilan (opsional)&quot;)">
                            <select v-model.number="reviewRating"><option :value="5">5 ★</option><option :value="4">4 ★</option><option :value="3">3 ★</option><option :value="2">2 ★</option><option :value="1">1 ★</option></select>
                            <textarea v-model="reviewBody" rows="3" maxlength="2000" :placeholder="customerText(&quot;pages.guest.orderstatus.attribute.placeholder.98e9ee9&quot;, &quot;Ceritakan pengalaman transaksimu&quot;)"></textarea>
                            <button class="lf-primary" :disabled="busy||reviewBody.trim().length<3" @click="submitReview">{{ customerText("pages.guest.orderstatus.c4ebe0bb", "Kirim Ulasan") }}</button>
                        </div>
                    </template>
                    <p v-if="reviewMessage" class="lf-review-message">{{reviewMessage}}</p>
                </section>
            </div>

            <aside class="lf-order-timeline">
                <header><div><h2>{{ customerText("pages.guest.orderstatus.67f44f0e", "Log Realtime") }}</h2><p>{{refreshing?'Memperbarui...':'Sinkron otomatis'}}</p></div><button type="button" @click="refreshStatus">↻</button></header>
                <p v-if="errors.status" role="alert">{{errors.status[0]}}</p>
                <ol>
                    <li v-for="event in timeline" :key="event.id">
                        <span></span>
                        <div><strong>{{eventLabel(event)}}</strong><small>{{eventTime(event.created_at)}}</small><p v-if="event.from_status&&event.to_status">{{statusLabel(event.from_status)}} → {{statusLabel(event.to_status)}}</p></div>
                    </li>
                    <li v-if="!timeline.length"><span></span><div><strong>{{ customerText("pages.guest.orderstatus.243a5ea5", "Pesanan tercatat") }}</strong><small>{{ customerText("pages.guest.orderstatus.97f7dab7", "Menunggu log transaksi berikutnya.") }}</small></div></li>
                </ol>
            </aside>
        </div>
    </main>
</CustomerShell>
</template>
