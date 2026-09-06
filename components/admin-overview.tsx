"use client";

import { useEffect, useState } from "react";
import { Box, CircleDollarSign, LoaderCircle, PackageCheck, ReceiptText, Server, Users } from "lucide-react";
import { formatRupiah } from "@/lib/store-data";

type Summary = { canViewFinance:boolean; metrics:{todayOrders:number;todayRevenue:number|null;activeProducts:number;customers:number;successfulOrders:number}; statuses:Array<{status:string;count:number}>; chart:Array<{day:string;orders:number}> };

export function AdminOverview(){
 const [data,setData]=useState<Summary|null>(null);
 const [error,setError]=useState("");
 useEffect(()=>{fetch("/api/panel/summary",{cache:"no-store"}).then(r=>r.json()).then(setData).catch(e=>setError(e.message));},[]);
 if(error)return <div className="panel p-6 text-red-200">{error}</div>;
 if(!data)return <div className="panel flex min-h-56 items-center justify-center text-white/40"><LoaderCircle className="mr-2 animate-spin"/>Memuat dashboard</div>;
 const cards=[...(data.canViewFinance?[['Omzet Hari Ini',formatRupiah(data.metrics.todayRevenue??0),CircleDollarSign]]:[]),['Pesanan Hari Ini',data.metrics.todayOrders,ReceiptText],['Berhasil',data.metrics.successfulOrders,PackageCheck],['Produk Aktif',data.metrics.activeProducts,Box],['Pelanggan',data.metrics.customers,Users]] as const;
 return <div className="space-y-4">
  <div className="grid grid-cols-2 gap-3 xl:grid-cols-5">{cards.map(([t,v,I])=><div className="panel p-4" key={t}><I className="size-5 text-[#b9ff35]"/><p className="mt-3 text-[10px] text-white/40">{t}</p><b className="text-xl">{v}</b></div>)}</div>
  <div className="grid gap-4 xl:grid-cols-[1.5fr_.5fr]">
   <div className="panel p-5"><h2 className="font-bold">Aktivitas Transaksi</h2><div className="mt-6 flex h-40 items-end gap-2">{data.chart.map(x=><div key={x.day} className="flex-1"><div className="rounded-t bg-[#b9ff35]" style={{height:`${Math.max(8,x.orders*12)}px`}}/><p className="text-center text-[9px] text-white/30">{x.day.slice(-2)}</p></div>)}</div></div>
   <div className="panel p-5"><h2 className="font-bold">Status Sistem</h2><div className="mt-4 space-y-2"><p className="flex gap-2 text-xs"><Server className="size-4 text-[#b9ff35]"/>DigiFlazz Aktif</p><p className="flex gap-2 text-xs"><Server className="size-4 text-[#b9ff35]"/>Payment Gateway Aktif</p></div></div>
  </div>
 </div>;
}
