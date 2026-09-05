"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Copy, Edit3, Eye, KeyRound, LoaderCircle, Mail, MessageCircle, PackagePlus, RefreshCw, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

type Stock = { stock_key: string; available: number; reserved: number; delivered: number; voided: number; total: number; labels: string[]; };
type Delivery = { code_id: number; stock_key: string; code_status: string; order_id: string; reference_id: string; product_name: string; package_label: string; buyer_name: string; buyer_email: string; buyer_phone: string; email_status: string | null; whatsapp_status: string | null; reserved_at: string | null; delivered_at: string | null; };
type VoucherConfig = { encryptionReady: boolean; emailReady: boolean; whatsappReady: boolean; deliveryChannel: string; };
type DashboardResponse = { stocks?: Stock[]; deliveries?: Delivery[]; config?: VoucherConfig; error?: string; };
const emptyConfig: VoucherConfig = { encryptionReady: false, emailReady: false, whatsappReady: false, deliveryChannel: "both" };

export function AdminVoucherManager() {
  const [stocks, setStocks] = useState<Stock[]>([]);
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);
  const [config, setConfig] = useState<VoucherConfig>(emptyConfig);
  const [stockKey, setStockKey] = useState("");
  const [codes, setCodes] = useState("");
  const [importOpen, setImportOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [importing, setImporting] = useState(false);
  const [workingOrder, setWorkingOrder] = useState<string | null>(null);
  const [revealed, setRevealed] = useState<{ orderId: string; code: string; stockKey: string } | null>(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/panel/vouchers", { cache: "no-store" });
      const data = await response.json() as DashboardResponse;
      if (!response.ok) throw new Error(data.error || "Stok kode gagal dimuat.");
      const next = data.stocks ?? [];
      setStocks(next); setDeliveries(data.deliveries ?? []); setConfig(data.config ?? emptyConfig);
      setStockKey((current) => current || next[0]?.stock_key || "");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Stok kode gagal dimuat."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const totals = useMemo(() => stocks.reduce((r,s) => ({available:r.available+Number(s.available),reserved:r.reserved+Number(s.reserved),delivered:r.delivered+Number(s.delivered)}), {available:0,reserved:0,delivered:0}), [stocks]);

  function openImport(key?: string) { setStockKey(key || stocks[0]?.stock_key || ""); setCodes(""); setImportOpen(true); }

  async function importCodes(event: FormEvent) {
    event.preventDefault();
    const values=codes.split(/\r?\n/).map((v)=>v.trim()).filter(Boolean);
    if(!stockKey || !values.length){setError("Pilih voucher/paket dan isi minimal satu kode.");return;}
    setImporting(true);setError("");setMessage("");
    try{
      const response=await fetch("/api/panel/vouchers",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({action:"import",stockKey,codes:values})});
      const data=await response.json() as {imported?:number;duplicates?:number;error?:string};
      if(!response.ok) throw new Error(data.error||"Impor kode gagal.");
      setMessage(`${data.imported??0} kode berhasil ditambahkan${data.duplicates?`, ${data.duplicates} duplikat dilewati`:""}.`);
      setImportOpen(false);setCodes("");await load();
    }catch(reason){setError(reason instanceof Error?reason.message:"Impor kode gagal.");}
    finally{setImporting(false);}
  }
  async function reveal(orderId:string){setWorkingOrder(orderId);setError("");try{const response=await fetch("/api/panel/vouchers",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({action:"reveal",orderId})});const data=await response.json() as {code?:string;stockKey?:string;error?:string};if(!response.ok||!data.code||!data.stockKey)throw new Error(data.error||"Kode tidak dapat dibuka.");setRevealed({orderId,code:data.code,stockKey:data.stockKey});}catch(reason){setError(reason instanceof Error?reason.message:"Kode tidak dapat dibuka.");}finally{setWorkingOrder(null);}}
  async function retry(orderId:string){setWorkingOrder(orderId);setError("");try{const response=await fetch("/api/panel/vouchers",{method:"PATCH",headers:{"content-type":"application/json"},body:JSON.stringify({action:"retry",orderId})});const data=await response.json() as {ok?:boolean;message?:string;error?:string};if(!response.ok||!data.ok)throw new Error(data.error||data.message||"Pengiriman ulang belum berhasil.");setMessage(data.message||"Kode berhasil dikirim ulang.");await load();}catch(reason){setError(reason instanceof Error?reason.message:"Pengiriman ulang gagal.");}finally{setWorkingOrder(null);}}

  if(loading)return <div className="flex min-h-40 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin"/>Memuat stok kode…</div>;
  return <div className="space-y-4">
    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.08] pb-3"><div className="flex flex-wrap gap-x-5 gap-y-1 text-[10px] text-white/40"><span>Siap dijual <strong className="ml-1 text-[#d8ff8d]">{totals.available}</strong></span><span>Dipesan <strong className="ml-1 text-amber-200">{totals.reserved}</strong></span><span>Terkirim <strong className="ml-1 text-white/70">{totals.delivered}</strong></span></div><div className="flex gap-2"><Button type="button" onClick={()=>void load()} variant="ghost" size="sm" className="h-8 text-[9px] text-white/45"><RefreshCw className="mr-1 size-3"/>Muat ulang</Button><Button type="button" onClick={()=>openImport()} size="sm" className="h-8 bg-[#b9ff35] px-3 text-[9px] font-black text-[#091006]"><PackagePlus className="mr-1 size-3"/>Tambah stok</Button></div></div>
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-[9px] text-white/30"><Status ready={config.encryptionReady} icon={KeyRound} label="Enkripsi"/><Status ready={config.emailReady} icon={Mail} label="Email"/><Status ready={config.whatsappReady} icon={MessageCircle} label="WhatsApp"/><span>Pengiriman kode otomatis lewat website</span></div>
    {!config.encryptionReady&&<div className="rounded-lg border border-amber-300/20 bg-amber-300/[0.05] p-3 text-[10px] text-amber-100/70">Aktifkan kunci enkripsi stok sebelum menambahkan kode.</div>}
    {message&&<div className="rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3 text-xs text-[#d8ff8d]">{message}</div>}{error&&<div className="rounded-lg border border-red-400/20 bg-red-400/[0.05] p-3 text-xs text-red-200">{error}</div>}
    {revealed&&<div className="flex items-center justify-between gap-3 rounded-lg border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] p-3"><div><span className="text-[9px] text-white/35">Kode voucher</span><strong className="mt-1 block break-all font-mono text-xs text-[#d8ff8d]">{revealed.code}</strong></div><Button type="button" variant="ghost" size="sm" onClick={()=>void navigator.clipboard.writeText(revealed.code)} className="text-white"><Copy className="mr-1 size-3"/>Salin</Button></div>}
    {stocks.length===0?<div className="rounded-lg border border-dashed border-white/10 p-8 text-center text-xs text-white/30">Belum ada produk/paket yang memakai Stok kode LFAMILIA. Atur sumber pengiriman pada Edit Produk.</div>:<div className="overflow-x-auto rounded-lg border border-white/[0.08]"><Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Voucher / Paket</TableHead><TableHead className="text-[10px] text-white/35">Tersedia</TableHead><TableHead className="text-[10px] text-white/35">Dipesan</TableHead><TableHead className="text-[10px] text-white/35">Terkirim</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader><TableBody>{stocks.map((stock)=><TableRow key={stock.stock_key} className="border-white/[0.07]"><TableCell><strong className="text-xs">{stock.labels.join(", ")||"Stok lama / belum dihubungkan"}</strong></TableCell><TableCell className="text-xs font-bold text-[#d8ff8d]">{stock.available}</TableCell><TableCell className="text-xs text-amber-200">{stock.reserved}</TableCell><TableCell className="text-xs text-white/60">{stock.delivered}</TableCell><TableCell className="text-right"><Button type="button" onClick={()=>openImport(stock.stock_key)} variant="ghost" size="icon-sm" className="text-white/45 hover:text-white"><Edit3 className="size-3.5"/></Button></TableCell></TableRow>)}</TableBody></Table></div>}
    <section><h3 className="mb-2 text-xs font-bold">Pengiriman terbaru</h3>{deliveries.length===0?<div className="rounded-lg border border-dashed border-white/10 p-6 text-center text-[10px] text-white/30">Belum ada kode yang dikirim.</div>:<div className="overflow-x-auto rounded-lg border border-white/[0.08]"><Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent"><TableHead className="text-[10px] text-white/35">Invoice</TableHead><TableHead className="text-[10px] text-white/35">Produk</TableHead><TableHead className="text-[10px] text-white/35">Pelanggan</TableHead><TableHead className="text-[10px] text-white/35">Status</TableHead><TableHead className="text-right text-[10px] text-white/35">Aksi</TableHead></TableRow></TableHeader><TableBody>{deliveries.map((d)=>{const needsRetry=d.email_status==="failed"||d.whatsapp_status==="failed"||d.code_status==="reserved";return <TableRow key={d.code_id} className="border-white/[0.07]"><TableCell className="font-mono text-[9px]">{d.reference_id}</TableCell><TableCell><strong className="text-xs">{d.product_name}</strong><p className="text-[9px] text-white/30">{d.package_label}</p></TableCell><TableCell><span className="text-xs text-white/55">{d.buyer_name}</span><p className="text-[8px] text-white/25">{d.buyer_email}</p></TableCell><TableCell><DeliveryBadge value={d.code_status==="delivered"?"sent":d.code_status}/></TableCell><TableCell><div className="flex justify-end gap-1"><Button type="button" disabled={workingOrder===d.order_id} onClick={()=>void reveal(d.order_id)} variant="ghost" size="sm" className="text-[9px] text-white/55">{workingOrder===d.order_id?<LoaderCircle className="size-3 animate-spin"/>:<><Eye className="mr-1 size-3"/>Lihat</>}</Button>{needsRetry&&<Button type="button" disabled={workingOrder===d.order_id} onClick={()=>void retry(d.order_id)} variant="ghost" size="sm" className="text-[9px] text-amber-200/70"><RotateCcw className="mr-1 size-3"/>Ulang</Button>}</div></TableCell></TableRow>})}</TableBody></Table></div>}</section>
    <Dialog open={importOpen} onOpenChange={setImportOpen}><DialogContent className="border-white/10 bg-[#10141d] text-white sm:max-w-xl"><form onSubmit={importCodes}><DialogHeader><DialogTitle>Tambah stok kode</DialogTitle><DialogDescription className="text-white/38">Pilih voucher/paket lalu tempel satu kode per baris.</DialogDescription></DialogHeader><div className="mt-4 space-y-4"><label><span className="field-label">Voucher / Paket</span><select required value={stockKey} onChange={(e)=>setStockKey(e.target.value)} className="admin-input">{stocks.map((stock)=><option key={stock.stock_key} value={stock.stock_key}>{stock.labels.join(", ")||"Stok lama"}</option>)}</select></label><label><span className="field-label">Daftar kode</span><Textarea required rows={8} value={codes} onChange={(e)=>setCodes(e.target.value)} placeholder={"KODE-AAAA-BBBB\nKODE-CCCC-DDDD"} className="min-h-44 rounded-xl border-white/10 bg-[#171c27] font-mono text-xs text-white"/></label></div><DialogFooter className="mt-5"><Button type="button" variant="outline" onClick={()=>setImportOpen(false)} className="border-white/10 bg-white/[0.03] text-white">Batal</Button><Button disabled={importing||!config.encryptionReady||!stockKey} className="bg-[#b9ff35] font-black text-[#091006]">{importing&&<LoaderCircle className="mr-2 size-4 animate-spin"/>}Simpan stok</Button></DialogFooter></form></DialogContent></Dialog>
  </div>;
}
function Status({ready,icon:Icon,label}:{ready:boolean;icon:typeof KeyRound;label:string}){return <span className={ready?"text-[#d8ff8d]":"text-amber-200/60"}><Icon className="mr-1 inline size-3"/>{label}: {ready?"Siap":"Belum"}</span>}
function DeliveryBadge({value}:{value:string|null}){if(!value)return <span className="text-[9px] text-white/25">Belum</span>;const sent=value==="sent";const failed=value==="failed";return <span className={sent?"text-[9px] font-bold text-[#d8ff8d]":failed?"text-[9px] font-bold text-red-200":"text-[9px] font-bold text-amber-200"}>{sent?"Terkirim":failed?"Gagal":"Diproses"}</span>}
