<script setup>
import AdminResponsiveTable from '../../Components/AdminResponsiveTable.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AdminShell from '../../Components/AdminShell.vue';
import AdminIcon from '../../Components/AdminIcon.vue';
import { Card, CardHeader, CardTitle, CardContent } from '../../Components/ui/card';
import { Button } from '../../Components/ui/button';
import { Badge } from '../../Components/ui/badge';
import { TableHeader, TableBody, TableRow, TableHead, TableCell } from '../../Components/ui/table';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem } from '../../Components/ui/dropdown-menu';

const props = defineProps({
    metrics:{type:Object,default:()=>({})}, notifications:{type:Array,default:()=>[]}, recentOrders:{type:Array,default:()=>[]}, range:String, generatedAt:String,
    chart:{type:Array,default:()=>[]}, activities:{type:Array,default:()=>[]}, topProducts:{type:Array,default:()=>[]}, integrations:{type:Array,default:()=>[]}, lastSyncedAt:String,webhookReady:Boolean,
    canViewFinance:Boolean, canOrders:Boolean, canCatalog:Boolean, canNotifications:Boolean, digiflazzBalance:Object,
});
const chart=computed(()=>Array.isArray(props.chart)?props.chart:[]);
const notifications=computed(()=>Array.isArray(props.notifications)?props.notifications:[]);
const recentOrders=computed(()=>Array.isArray(props.recentOrders)?props.recentOrders:[]);
const _topProducts=computed(()=>Array.isArray(props.topProducts)?props.topProducts:[]);
const integrations=computed(()=>Array.isArray(props.integrations)?props.integrations:[]);
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
const metricCards=computed(()=>Object.entries(definitions).filter(([key])=>Object.hasOwn(props.metrics||{},key)).map(([key,d])=>({key,label:d[0],icon:d[1],finance:d[2],note:d[3],value:props.metrics?.[key]})));
const money=value=>value==null?'—':'Rp'+Number(value).toLocaleString('id-ID');
const date=value=>value?new Intl.DateTimeFormat('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Asia/Jakarta'}).format(new Date(value))+' WIB':'Belum pernah';
const shortDay=value=>new Intl.DateTimeFormat('id-ID',{day:'2-digit',month:'short',timeZone:'Asia/Jakarta'}).format(new Date(value+'T00:00:00+07:00'));
const clock=ref(new Date(props.generatedAt));
let clockTimer;
onMounted(()=>{clockTimer=setInterval(()=>clock.value=new Date(),60000);});
onUnmounted(()=>clearInterval(clockTimer));
const clockDate=computed(()=>new Intl.DateTimeFormat('id-ID',{weekday:'long',day:'2-digit',month:'short',year:'numeric',timeZone:'Asia/Jakarta'}).format(clock.value));
const clockTime=computed(()=>new Intl.DateTimeFormat('id-ID',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Jakarta'}).format(clock.value)+' WIB');
const chartMetric=ref('revenue');
const chartMaximum=computed(()=>Math.max(1,...chart.value.map(p=>chartMetric.value==='revenue'?p.revenue_idr:p.orders)));
const statusLabel=status=>({SUCCESS:'Berhasil',PENDING_PAYMENT:'Pending',PAID:'Dibayar',PROCESSING:'Diproses',FAILED:'Gagal',EXPIRED:'Kedaluwarsa',CANCELLED:'Dibatalkan',HEALTHY:'Tes terakhir berhasil',CONFIGURED:'Tersimpan — tes otomatis tidak tersedia',DOWN:'Bermasalah',DEGRADED:'Perlu diperiksa',NOT_CONFIGURED:'Belum dikonfigurasi',UNTESTED:'Belum dites',STALE:'Perlu tes ulang'}[status]||status);
const _integrationSummary=computed(()=>{const active=integrations.value.filter(i=>i.active);if(!active.length)return 'Belum ada integrasi aktif';if(active.some(i=>!['HEALTHY','CONFIGURED'].includes(i.status)))return 'Ada yang perlu diperiksa';return active.some(i=>i.status==='CONFIGURED')?'Sebagian belum terverifikasi':'Tes terakhir berhasil';});
const visibleActivities=computed(()=>props.activities?.length?props.activities:recentOrders.value.slice(0,5).map(o=>({id:o.id,action:'Pesanan '+statusLabel(o.status),target:o.order_number,created_at:o.created_at,href:'/admin/orders/'+o.id})));
const markRead=n=>router.post('/admin/notifications/'+n.id+'/read',{}, {preserveScroll:true});
</script>
<template>
<Head title="Dashboard Admin"/>
<AdminShell><div class="space-y-5">
    <header class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0"><h1 class="text-2xl font-semibold tracking-tight">{{title}}</h1><p class="mt-1 max-w-2xl text-sm text-muted-foreground">{{owner?'Kelola seluruh sistem dan data finansial toko.':'Kelola operasional toko sesuai akses akun.'}}</p></div>
        <div class="flex flex-wrap items-center gap-3"><time class="min-w-36 rounded-md border border-border bg-card px-3 py-2 text-xs"><strong class="block">{{clockDate}}</strong><span class="text-muted-foreground">{{clockTime}} WIB</span></time><Button variant="outline" :disabled="busy" @click="refresh"><AdminIcon name="providers" class="size-4"/>{{busy?'Memuat…':'Muat ulang'}}</Button></div>
    </header>
    <!-- 1. Metric Cards -->
    <section class="lf-admin-summary" aria-label="Ringkasan dashboard">
        <Card v-for="metric in metricCards" :key="metric.key" class="min-w-0 p-4"><div class="flex items-start gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><AdminIcon :name="metric.icon" class="size-5"/></span><div class="min-w-0"><p class="text-xs text-muted-foreground">{{metric.label}}</p><strong class="mt-1 block break-words text-xl font-bold tabular-nums">{{metric.finance?money(metric.value):Number(metric.value||0).toLocaleString('id-ID')}}</strong></div></div><p class="mt-3 text-xs text-muted-foreground">{{metric.key==='digiflazz_balance'&&metric.value==null?statusLabel(digiflazzBalance?.status):metric.note}}</p><p v-if="metric.key==='digiflazz_balance'&&digiflazzBalance?.checked_at" class="mt-1 text-xs text-muted-foreground">{{date(digiflazzBalance.checked_at)}}</p></Card>
    </section>

    <!-- 2. Grafik Penjualan & Pesanan (Full Width) -->
    <Card v-if="canViewFinance" class="min-w-0">
        <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-3 p-4">
            <div>
                <CardTitle class="text-base">Grafik Penjualan & Pesanan</CardTitle>
                <p class="mt-0.5 text-xs text-muted-foreground">Omzet (WIB) hanya transaksi berstatus dibayar.</p>
            </div>
            <div class="flex items-center gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" :disabled="busy">{{ranges[range] || '7 Hari Terakhir'}}</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuRadioGroup :model-value="range" @update:model-value="changeRange">
                            <DropdownMenuRadioItem v-for="(label,key) in ranges" :key="key" :value="key">{{label}}</DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </CardHeader>
        <CardContent class="space-y-3 px-4 pb-4">
            <div class="flex gap-4 text-xs">
                <button type="button" class="flex items-center gap-1.5 rounded px-1 py-0.5" :aria-pressed="chartMetric==='revenue'" @click="chartMetric='revenue'"><span class="size-2.5 rounded-sm bg-primary inline-block"/>Omzet</button>
                <button type="button" class="flex items-center gap-1.5 rounded px-1 py-0.5" :aria-pressed="chartMetric==='orders'" @click="chartMetric='orders'"><span class="size-2.5 rounded-sm bg-primary/35 inline-block"/>Jumlah Pesanan</button>
            </div>
            <div ref="chartScroll" class="overflow-x-auto" role="group" :aria-label="'Grafik omzet dan jumlah pesanan '+ranges[range]">
                <div class="flex h-52 items-end gap-2" :style="{minWidth:Math.max(280,chart.length*28)+'px'}">
                    <div v-for="(point,index) in chart" :key="point.day" class="flex min-w-0 flex-1 flex-col items-center">
                        <div class="flex h-40 w-full items-end justify-center gap-1" :title="shortDay(point.day)+': '+money(point.revenue_idr)+' · '+point.orders+' pesanan'">
                            <span class="w-2.5 rounded-t bg-primary transition-all" :style="{height:(chartMetric==='revenue'?point.revenue_idr:point.orders)/chartMaximum*100+'%'}"/>
                        </div>
                        <span class="mt-2 whitespace-nowrap text-[10px] text-muted-foreground">{{chart.length<=7||index%7===0?shortDay(point.day):'·'}}</span>
                    </div>
                </div>
            </div>
            <p v-if="!chart.some(p=>p.orders>0)" class="text-center text-xs text-muted-foreground py-2">Belum ada transaksi pada periode ini.</p>
        </CardContent>
    </Card>

    <!-- 3. Pesanan Terbaru (Maks 5) -->
    <Card v-if="canOrders" class="min-w-0">
        <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-3 p-4">
            <div>
                <CardTitle class="text-base">Pesanan Terbaru</CardTitle>
                <p class="mt-0.5 text-xs text-muted-foreground">Maksimal 5 pesanan terakhir</p>
            </div>
            <Button variant="link" size="sm" as-child><Link href="/admin/orders">Buka Semua Pesanan →</Link></Button>
        </CardHeader>
        <CardContent class="px-0 pb-2">
            <AdminResponsiveTable :mobile-columns="canViewFinance?[1,3,4,5,6]:[1,3,4,5]" class="table-fixed min-w-[720px]">
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[4%]">#</TableHead>
                        <TableHead class="w-[19%]">Invoice</TableHead>
                        <TableHead class="w-[14%]">Pelanggan</TableHead>
                        <TableHead class="w-[22%] whitespace-normal">Produk / Nominal</TableHead>
                        <TableHead class="w-[15%] whitespace-normal">Pembayaran</TableHead>
                        <TableHead v-if="canViewFinance" class="w-[13%]">Total</TableHead>
                        <TableHead class="w-[13%]">Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="(order,index) in recentOrders.slice(0, 5)" :key="order.id">
                        <TableCell>{{index+1}}</TableCell>
                        <TableCell class="whitespace-normal break-words">
                            <Link :href="'/admin/orders/'+order.id" class="font-medium text-primary hover:underline">{{order.order_number}}</Link>
                            <time class="mt-1 block text-xs text-muted-foreground">{{date(order.created_at)}}</time>
                        </TableCell>
                        <TableCell class="whitespace-normal break-words">{{order.buyer_name}}</TableCell>
                        <TableCell class="whitespace-normal break-words">
                            <strong>{{order.product_name}}</strong>
                            <p class="text-xs text-muted-foreground">{{order.package_name}}</p>
                        </TableCell>
                        <TableCell class="whitespace-normal break-words">
                            {{order.payment_channel}}
                            <p class="text-xs text-muted-foreground">{{order.payment_status}}</p>
                        </TableCell>
                        <TableCell v-if="canViewFinance">{{money(order.total_idr)}}</TableCell>
                        <TableCell><Badge :variant="order.status==='FAILED'?'destructive':'secondary'">{{statusLabel(order.status)}}</Badge></TableCell>
                    </TableRow>
                    <TableRow v-if="!recentOrders.length">
                        <TableCell :colspan="canViewFinance?7:6" class="py-8 text-center text-muted-foreground">Belum ada pesanan.</TableCell>
                    </TableRow>
                </TableBody>
            </AdminResponsiveTable>
        </CardContent>
    </Card>

    <!-- 4. Ringkasan Aktivitas, Integrasi & Notifikasi (Ringkas, bukan kartu bertumpuk) -->
    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
        <!-- Aktivitas -->
        <div class="rounded-lg border border-border bg-card p-4">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <strong class="text-sm">Aktivitas Terbaru</strong>
                <Link v-if="owner||canOrders" :href="owner?'/admin/audit':'/admin/orders'" class="text-xs text-primary hover:underline">Semua</Link>
            </div>
            <div class="divide-y divide-border">
                <p v-if="!visibleActivities.length" class="py-4 text-center text-xs text-muted-foreground">Belum ada aktivitas.</p>
                <article v-for="activity in visibleActivities.slice(0, 5)" :key="activity.id" class="py-2.5">
                    <Link v-if="activity.href" :href="activity.href" class="text-xs font-medium text-primary hover:underline block">{{activity.action}}</Link>
                    <strong v-else class="block text-xs">{{activity.action}}</strong>
                    <p class="text-[11px] text-muted-foreground truncate">{{activity.target}}</p>
                    <time class="block text-[10px] text-muted-foreground mt-0.5">{{date(activity.created_at)}}</time>
                </article>
            </div>
        </div>

        <!-- Status Integrasi -->
        <div v-if="integrations.length" class="rounded-lg border border-border bg-card p-4">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <strong class="text-sm">Status Integrasi</strong>
                <Link v-if="owner" href="/admin/integrations" class="text-xs text-primary hover:underline">Kelola</Link>
            </div>
            <div class="divide-y divide-border">
                <div v-for="integration in integrations.slice(0, 5)" :key="integration.code" class="flex items-center justify-between py-2">
                    <div class="min-w-0 pr-2">
                        <span class="text-xs font-medium block truncate">{{integration.name}}</span>
                        <span class="text-[10px] text-muted-foreground">{{integration.active?'Aktif':'Nonaktif'}}</span>
                    </div>
                    <Badge :variant="integration.status==='DOWN'?'destructive':'secondary'" class="text-[10px]">{{statusLabel(integration.status)}}</Badge>
                </div>
            </div>
            <div class="mt-3 pt-2.5 border-t border-border flex justify-between text-[11px] text-muted-foreground">
                <span>Sinkronisasi terakhir:</span>
                <span class="font-medium text-foreground">{{date(lastSyncedAt)}}</span>
            </div>
        </div>

        <!-- Notifikasi Belum Dibaca -->
        <div v-if="canNotifications" class="rounded-lg border border-border bg-card p-4">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <strong class="text-sm">Notifikasi</strong>
                <Link href="/admin/notifications" class="text-xs text-primary hover:underline">Semua</Link>
            </div>
            <div class="divide-y divide-border">
                <p v-if="!notifications.length" class="py-4 text-center text-xs text-muted-foreground">Tidak ada notifikasi baru.</p>
                <article v-for="n in notifications.slice(0, 4)" :key="n.id" class="py-2.5">
                    <div class="flex items-start justify-between gap-1">
                        <strong class="text-xs truncate block">{{n.title}}</strong>
                        <Badge class="text-[9px] shrink-0" :variant="n.severity==='ERROR'?'destructive':'secondary'">{{n.severity}}</Badge>
                    </div>
                    <p class="text-[11px] text-muted-foreground truncate mt-0.5">{{n.message}}</p>
                    <div class="flex items-center justify-between mt-1">
                        <time class="text-[10px] text-muted-foreground">{{date(n.created_at)}}</time>
                        <button v-if="!n.read_at" type="button" class="text-[10px] text-primary hover:underline" @click="markRead(n)">Tandai dibaca</button>
                    </div>
                </article>
            </div>
        </div>
    </div>
</div></AdminShell>
</template>
