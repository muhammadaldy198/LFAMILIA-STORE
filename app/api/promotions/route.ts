import { listDiscountVouchers, listFlashSales } from "@/lib/server/promotions";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const [vouchers, flashSales] = await Promise.all([listDiscountVouchers(false), listFlashSales(false)]);
    return Response.json({ vouchers, flashSales }, { headers: { "Cache-Control": "public, max-age=30" } });
  } catch {
    return Response.json({ vouchers: [], flashSales: [] }, { headers: { "Cache-Control": "public, max-age=15" } });
  }
}
