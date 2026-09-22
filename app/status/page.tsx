import { ServiceStatus } from "@/components/service-status";
import { StoreLayout } from "@/components/store-layout";

export default function StatusPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-4xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8"><p className="eyebrow">Transparansi layanan</p><h1 className="section-title">Status layanan LFAMILIA</h1><p className="mt-3 max-w-2xl text-sm leading-6 text-white/45">Lihat kondisi katalog, pembayaran, pengiriman otomatis, dan layanan pelanggan sebelum atau sesudah bertransaksi.</p><ServiceStatus /></main></StoreLayout>;
}
