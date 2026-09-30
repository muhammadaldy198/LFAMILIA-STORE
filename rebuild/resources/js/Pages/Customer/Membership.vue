<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountShell from '../../Components/AccountShell.vue';

const props=defineProps({currentCode:String,profile:Object,tiers:Array});
const money=value=>'Rp'+Number(value||0).toLocaleString('id-ID');
const current=computed(()=>props.tiers.find(t=>t.code===props.currentCode)||null);
const progressPercent=computed(()=>{
    const target=Number(props.profile?.next_target_idr||0);
    if(!target)return 100;
    return Math.max(0,Math.min(100,Math.round(Number(props.profile?.progress_idr||0)/target*100)));
});
function requirement(tier){
    const value=Number(tier?.requirements?.minimum_spend_idr||0);
    return value>0?money(value):tier.code==='BASIC'?'Mulai dari akun baru':'Diatur toko';
}
function discount(tier){
    const bps=Number(tier?.benefits?.discount_bps||0);
    return bps>0?(bps/100).toLocaleString('id-ID',{maximumFractionDigits:2})+'%':'–';
}
</script>
<template>
<Head title="Membership"/>
<AccountShell>
    <section class="lf-membership-hero">
        <div>
            <p class="lf-eyebrow">MEMBERSHIP LFAMILIA</p>
            <h1>{{currentCode}}</h1>
            <p>{{profile?.mode==='MANUAL'?'Tier ini ditetapkan manual oleh Super Admin.':'Tier mengikuti total transaksi berhasil dan manfaat yang dikonfigurasi toko.'}}</p>
        </div>
        <div class="lf-membership-discount">
            <span>Diskon member</span>
            <strong>{{Number(profile?.discount_bps||0)>0?(Number(profile.discount_bps)/100).toLocaleString('id-ID',{maximumFractionDigits:2})+'%':'0%'}}</strong>
        </div>
    </section>

    <section class="lf-membership-progress">
        <div class="lf-membership-progress-head">
            <div><small>Progress tier</small><strong>{{money(profile?.progress_idr)}}</strong></div>
            <div v-if="profile?.next_code" class="text-right"><small>Berikutnya {{profile.next_code}}</small><strong>Sisa {{money(profile.remaining_to_next_idr)}}</strong></div>
            <div v-else class="text-right"><small>Tier berikutnya</small><strong>Level tertinggi / manual</strong></div>
        </div>
        <div class="lf-membership-progress-bar"><span :style="{width:progressPercent+'%'}"></span></div>
        <p>Belanja berhasil: {{money(profile?.lifetime_spend_idr)}}<template v-if="profile?.progress_bonus_idr"> · Bonus progress: {{money(profile.progress_bonus_idr)}}</template></p>
    </section>

    <section class="lf-membership-grid">
        <article v-for="tier in tiers" :key="tier.code" :class="{active:tier.code===currentCode}">
            <div class="lf-membership-tier-head"><strong>{{tier.code}}</strong><span v-if="tier.code===currentCode">Tier kamu</span></div>
            <dl>
                <div><dt>Syarat</dt><dd>{{requirement(tier)}}</dd></div>
                <div><dt>Diskon</dt><dd>{{discount(tier)}}</dd></div>
            </dl>
            <div v-if="tier.benefits && Object.keys(tier.benefits).filter(k=>k!=='discount_bps').length" class="lf-membership-benefits">
                <p v-for="(value,key) in tier.benefits" v-show="key!=='discount_bps'" :key="key"><strong>{{String(key).replaceAll('_',' ')}}</strong><span>{{value}}</span></p>
            </div>
        </article>
    </section>
</AccountShell>
</template>
