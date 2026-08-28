import { FlaskConical } from "lucide-react";

export function DemoBanner() {
  return (
    <div className="border-b border-[#b9ff35]/20 bg-[#b9ff35]/[0.07] px-4 py-2 text-center text-[11px] font-medium leading-5 text-[#dcffa0] sm:text-xs">
      <span className="inline-flex items-center gap-2"><FlaskConical className="size-3.5" />Mode desain — harga, akun, pembayaran, dan pengiriman produk belum aktif.</span>
    </div>
  );
}

