import { listDiscountVouchers, listFlashSales } from "@/lib/server/promotions";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const [vouchers, flashSales] = await Promise.all([listDiscountVouchers(false), listFlashSales(false)]);
    return Response.json({ vouchers, flashSales }, { headers: { "Cache-Control": "public, max-age=30, s-maxage=60, stale-while-revalidate=120" } });
  } catch {
    return Response.json({ vouchers: [], flashSales: [] }, { headers: { "Cache-Control": "public, max-age=15" } });
  }
}
