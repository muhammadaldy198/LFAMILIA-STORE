<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminIcon from '../../Components/AdminIcon.vue';
import { Card, CardHeader, CardTitle, CardContent } from '../../Components/ui/card';
import { Button } from '../../Components/ui/button';
import { Badge } from '../../Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem } from '../../Components/ui/dropdown-menu';

const props = defineProps({
    metrics:Object, notifications:Array, recentOrders:Array, range:String, generatedAt:String,
    chart:Array, activities:Array, topProducts:Array, integrations:Array, lastSyncedAt:String,webhookReady:Boolean,
    canViewFinance:Boolean, canOrders:Boolean, canCatalog:Boolean, canNotifications:Boolean, digiflazzBalance:Object,
});
const page=usePage();
const owner=computed(()=>page.props.adminPanel?.admin?.role==='SUPER_ADMIN');
const title=computed(()=>owner.value?'Panel Super Admin LFAMILIA STORE':'Panel Admin LFAMILIA STORE');
const ranges={today:'Hari Ini','7d':'7 Hari Terakhir','30d':'30 Hari Terakhir','90d':'90 Hari Terakhir'};
const busy=ref(false);
const chartScroll=ref(null);
const scrollChart=()=>nextTick(()=>{if(chartScroll.value)chartScroll.value.scrollLeft=chartScroll.value.scrollWidth;});
watch(()=>props.chart,scrollChart);
onMounted(scrollChart);
const changeRange=range=>router.get('/admin/panel',{range},{preserveScroll:true,onStart:()=>busy.value=true,onFinish:()=>busy.value=false});
const refresh=()=>router.reload({preserveScroll:true,onStart:()=>busy.value=true,onFinish:()=>busy.value=false});
const definitions={
 revenue_today:['Omzet Hari Ini','payments',true,'Transaksi yang sudah dibayar'],
 orders_today:['Pesanan Hari Ini','orders',false,'Pesanan tercatat hari ini'],
 active_products:['Produk Aktif','catalog',false,'Status produk aktif'],
 digiflazz_balance:['Saldo Digiflazz','providers',true,'Saldo akun provider'],
 success_period:['Pembayaran Berhasil','fulfillment',false,'Pesanan selesai pada periode dipilih'],
 wallet_balance:['Saldo Wallet','customers',true,'Total saldo pelanggan'],
 open_tickets:['Tiket Terbuka','support',false,'OPEN dan IN_PROGRESS'],
 pending_fulfillment:['Pending Fulfillment','fulfillment',false,'Termasuk manual dan perlu diperiksa'],
};
const metricCards=computed(()=>Object.entries(definitions).filter(([key])=>Object.hasOwn(props.metrics,key)).map(([key,d])=>({key,label:d[0],icon:d[1],finance:d[2],note:d[3],value:props.metrics[key]})));
const money=value=>value==null?'—':'Rp'+Number(value).toLocaleString('id-ID');
const date=value=>value?new Intl.DateTimeFormat('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Asia/Jakarta'}).format(new Date(value))+' WIB':'Belum pernah';
const shortDay=value=>new Intl.DateTimeFormat('id-ID',{day:'2-digit',month:'short',timeZone:'Asia/Jakarta'}).format(new Date(value+'T00:00:00+07:00'));
const clock=ref(new Date(props.generatedAt));
let clockTimer;
onMounted(()=>{clockTimer=setInterval(()=>clock.value=new Date(),60000);});
onUnmounted(()=>clearInterval(clockTimer));
const clockDate=computed(()=>new Intl.DateTimeFormat('id-ID',{weekday:'long',day:'2-digit',month:'short',year:'numeric',timeZone:'Asia/Jakarta'}).format(clock.value));
const clockTime=computed(()=>new Intl.DateTimeFormat('id-ID',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Jakarta'}).format(clock.value)+' WIB');
const maxRevenue=computed(()=>Math.max(1,...(props.chart||[]).map(p=>p.revenue_idr)));
const maxOrders=computed(()=>Math.max(1,...(props.chart||[]).map(p=>p.orders)));
const statusLabel=status=>({SUCCESS:'Berhasil',PENDING_PAYMENT:'Pending',PAID:'Dibayar',PROCESSING:'Diproses',FAILED:'Gagal',EXPIRED:'Kedaluwarsa',CANCELLED:'Dibatalkan',HEALTHY:'Terverifikasi',DOWN:'Bermasalah',DEGRADED:'Perlu diperiksa',NOT_CONFIGURED:'Belum dikonfigurasi',UNTESTED:'Belum dites',STALE:'Perlu tes ulang'}[status]||status);
const integrationNormal=computed(()=>{const active=(props.integrations||[]).filter(i=>i.active);return active.length>0&&active.every(i=>i.status==='HEALTHY');});
const visibleActivities=computed(()=>props.activities?.length?props.activities:(props.recentOrders||[]).slice(0,5).map(o=>({id:o.id,action:'Pesanan '+statusLabel(o.status),target:o.order_number,created_at:o.created_at,href:'/admin/orders/'+o.id})));
const markRead=n=>router.post('/admin/notifications/'+n.id+'/read',{}, {preserveScroll:true});
</script>
<template>
<Head title="Dashboard Admin"/>
<AdminShell><div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div><h1 class="text-xl font-bold md:text-2xl">{{title}}</h1><p class="mt-1 text-sm text-muted-foreground">{{owner?'Kelola seluruh sistem dan data finansial toko.':'Kelola operasional toko sesuai akses akun.'}}</p></div>
        <div class="flex flex-wrap items-center gap-3"><div class="rounded-lg border border-border bg-card px-3 py-2 text-xs"><strong class="block">{{clockDate}}</strong><span>{{clockTime}}</span></div><Button variant="outline" :disabled="busy" @click="refresh"><AdminIcon name="providers" class="size-4"/>{{busy?'Memuat…':'Muat ulang'}}</Button></div>
    </header>
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan dashboard">
        <Card v-for="metric in metricCards" :key="metric.key" class="p-4"><div class="flex items-start gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><AdminIcon :name="metric.icon" class="size-5"/></span><div class="min-w-0"><p class="text-xs text-muted-foreground">{{metric.label}}</p><strong class="mt-1 block break-words text-xl font-bold">{{metric.finance?money(metric.value):Number(metric.value||0).toLocaleString('id-ID')}}</strong></div></div><p class="mt-3 text-xs text-muted-foreground">{{metric.key==='digiflazz_balance'&&metric.value==null?statusLabel(digiflazzBalance?.status):metric.note}}</p><p v-if="metric.key==='digiflazz_balance'&&digiflazzBalance?.checked_at" class="mt-1 text-xs text-muted-foreground">{{date(digiflazzBalance.checked_at)}}</p></Card>
    </section>

    <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
        <Card v-if="canViewFinance" class="min-w-0">
            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-3 p-4"><CardTitle class="text-base">Grafik Penjualan</CardTitle><DropdownMenu><DropdownMenuTrigger as-child><Button variant="outline" size="sm" :disabled="busy">{{ranges[range]}}</Button></DropdownMenuTrigger><DropdownMenuContent align="end"><DropdownMenuRadioGroup :model-value="range" @update:model-value="changeRange"><DropdownMenuRadioItem v-for="(label,key) in ranges" :key="key" :value="key">{{label}}</DropdownMenuRadioItem></DropdownMenuRadioGroup></DropdownMenuContent></DropdownMenu></CardHeader>
            <CardContent class="space-y-3 px-4 pb-4"><p class="text-xs text-muted-foreground">Tanggal pesanan dalam WIB. Omzet hanya transaksi yang sudah dibayar.</p><div class="flex gap-4 text-xs"><span class="flex items-center gap-1"><i class="size-2 bg-primary"/>Omzet</span><span class="flex items-center gap-1"><i class="size-2 bg-primary/35"/>Pesanan</span></div>
                <div ref="chartScroll" class="overflow-x-auto" role="img" :aria-label="'Grafik omzet dan jumlah pesanan '+ranges[range]"><div class="flex h-52 items-end gap-2" :style="{minWidth:Math.max(240,chart.length*28)+'px'}"><div v-for="(point,index) in chart" :key="point.day" class="flex min-w-0 flex-1 flex-col items-center"><div class="flex h-40 w-full items-end justify-center gap-1" :title="shortDay(point.day)+': '+money(point.revenue_idr)+' · '+point.orders+' pesanan'"><span class="w-2 rounded-t bg-primary" :style="{height:point.revenue_idr/maxRevenue*100+'%'}"/><span class="w-2 rounded-t bg-primary/35" :style="{height:point.orders/maxOrders*100+'%'}"/></div><span class="mt-2 whitespace-nowrap text-[10px] text-muted-foreground">{{chart.length<=7||index%7===0?shortDay(point.day):'·'}}</span></div></div></div>
                <p v-if="!chart.some(p=>p.orders>0)" class="text-center text-xs text-muted-foreground">Belum ada transaksi pada periode ini.</p>
                <details><summary class="cursor-pointer text-xs font-medium">Lihat data grafik</summary><div class="max-h-60 overflow-y-auto"><Table><TableHeader><TableRow><TableHead>Tanggal WIB</TableHead><TableHead>Omzet</TableHead><TableHead>Pesanan</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="point in chart" :key="point.day"><TableCell>{{shortDay(point.day)}}</TableCell><TableCell>{{money(point.revenue_idr)}}</TableCell><TableCell>{{point.orders}}</TableCell></TableRow></TableBody></Table></div></details>
            </CardContent>
        </Card>

        <Card class="min-w-0"><CardHeader class="flex flex-row items-center justify-between gap-3 p-4"><CardTitle class="text-base">Aktivitas Terbaru</CardTitle><Button v-if="owner||canOrders" variant="link" size="sm" as-child><Link :href="owner?'/admin/audit':'/admin/orders'">Lihat semua</Link></Button></CardHeader><CardContent class="px-4 pb-4"><p v-if="!visibleActivities.length" class="py-6 text-center text-sm text-muted-foreground">Belum ada aktivitas.</p><article v-for="activity in visibleActivities" :key="activity.id" class="border-b border-border py-3 last:border-0"><Link v-if="activity.href" :href="activity.href" class="text-sm font-medium text-primary">{{activity.action}}</Link><strong v-else class="block break-words text-sm">{{activity.action}}</strong><p class="mt-1 break-words text-xs text-muted-foreground">{{activity.target}}</p><time class="mt-1 block text-xs text-muted-foreground">{{date(activity.created_at)}}</time></article></CardContent></Card>

        <Card v-if="integrations.length" class="min-w-0"><CardHeader class="flex flex-row items-center justify-between gap-3 p-4"><CardTitle class="text-base">Status Integrasi</CardTitle><Button v-if="owner" variant="link" size="sm" as-child><Link href="/admin/integrations">Lihat Integrasi</Link></Button></CardHeader><CardContent class="space-y-2 px-4 pb-4"><div class="max-h-80 space-y-2 overflow-y-auto"><div v-for="integration in integrations" :key="integration.code" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border p-2"><div class="min-w-0"><strong class="text-sm">{{integration.name}}</strong><p class="text-xs text-muted-foreground">{{integration.active?'Aktif':'Nonaktif'}}</p></div><Badge :variant="integration.status==='DOWN'?'destructive':'secondary'">{{statusLabel(integration.status)}}</Badge></div></div><dl class="space-y-2 border-t border-border pt-3 text-xs"><div class="flex flex-wrap justify-between gap-2"><dt>Terakhir Sinkronisasi</dt><dd>{{date(lastSyncedAt)}}</dd></div><div class="flex justify-between gap-2"><dt>Webhook</dt><dd>{{webhookReady?'URL HTTPS siap':'Periksa URL aplikasi'}}</dd></div><div class="flex justify-between gap-2"><dt>Integrasi Aktif</dt><dd>{{integrationNormal?'Normal':'Periksa konfigurasi'}}</dd></div></dl><p class="text-xs text-muted-foreground">Status mengikuti tes koneksi terakhir; konfigurasi tersimpan belum berarti koneksi terverifikasi.</p></CardContent></Card>
    </div>

    <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <Card v-if="canOrders" class="min-w-0"><CardHeader class="flex flex-row flex-wrap items-center justify-between gap-3 p-4"><CardTitle class="text-base">Pesanan Terbaru</CardTitle><Button variant="link" size="sm" as-child><Link href="/admin/orders">Lihat Semua Pesanan</Link></Button></CardHeader><CardContent class="px-0 pb-2"><Table><TableHeader><TableRow><TableHead>#</TableHead><TableHead>Invoice</TableHead><TableHead>Pelanggan</TableHead><TableHead>Produk / Nominal</TableHead><TableHead>Pembayaran</TableHead><TableHead v-if="canViewFinance">Total</TableHead><TableHead>Status</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="(order,index) in recentOrders" :key="order.id"><TableCell>{{index+1}}</TableCell><TableCell><Link :href="'/admin/orders/'+order.id" class="font-medium text-primary">{{order.order_number}}</Link><time class="mt-1 block text-xs text-muted-foreground">{{date(order.created_at)}}</time></TableCell><TableCell>{{order.buyer_name}}</TableCell><TableCell><strong>{{order.product_name}}</strong><p class="text-xs text-muted-foreground">{{order.package_name}}</p></TableCell><TableCell>{{order.payment_channel}}<p class="text-xs text-muted-foreground">{{order.payment_status}}</p></TableCell><TableCell v-if="canViewFinance">{{money(order.total_idr)}}</TableCell><TableCell><Badge :variant="order.status==='FAILED'?'destructive':'secondary'">{{statusLabel(order.status)}}</Badge></TableCell></TableRow><TableRow v-if="!recentOrders.length"><TableCell :colspan="canViewFinance?7:6" class="py-8 text-center text-muted-foreground">Belum ada pesanan pada periode ini.</TableCell></TableRow></TableBody></Table></CardContent></Card>
        <Card v-if="canCatalog&&canOrders" class="min-w-0"><CardHeader class="flex flex-row items-center justify-between gap-3 p-4"><CardTitle class="text-base">Produk Terlaris</CardTitle><Button variant="link" size="sm" as-child><Link href="/admin/catalog">Lihat semua</Link></Button></CardHeader><CardContent class="px-4 pb-4"><p v-if="!topProducts.length" class="py-6 text-center text-sm text-muted-foreground">Belum ada data produk pada periode ini.</p><Link v-for="(product,index) in topProducts" :key="product.id" :href="'/admin/catalog?q='+encodeURIComponent(product.name)" class="flex items-center gap-3 border-b border-border py-3 last:border-0"><span class="text-xs text-muted-foreground">{{index+1}}</span><span class="min-w-0 flex-1 break-words text-sm font-medium">{{product.name}}</span><strong class="text-sm">{{product.fulfilled_orders}}</strong></Link></CardContent></Card>
    </div>
    <Card v-if="canNotifications"><CardHeader class="flex flex-row flex-wrap items-center justify-between gap-3 p-4"><CardTitle class="text-base">Notifikasi Terbaru</CardTitle><Button variant="link" size="sm" as-child><Link href="/admin/notifications">Lihat semua</Link></Button></CardHeader><CardContent class="px-4 pb-4"><p v-if="!notifications.length" class="py-6 text-center text-sm text-muted-foreground">Belum ada notifikasi.</p><article v-for="notification in notifications" :key="notification.id" class="flex flex-wrap items-start justify-between gap-3 border-b border-border py-3 last:border-0"><div class="min-w-0 flex-1"><strong class="break-words text-sm">{{notification.title}}</strong><Badge class="ml-2" :variant="notification.severity==='ERROR'?'destructive':'secondary'">{{notification.severity}}</Badge><p class="mt-1 break-words text-xs text-muted-foreground">{{notification.message}}</p><p class="mt-1 text-xs text-muted-foreground">{{date(notification.created_at)}} · {{notification.read_at?'Sudah dibaca':'Belum dibaca'}}</p></div><Button v-if="!notification.read_at" variant="outline" size="sm" @click="markRead(notification)">Tandai dibaca</Button></article></CardContent></Card>
</div></AdminShell>
</template>
