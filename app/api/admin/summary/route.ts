import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;
  const db = getD1();
  try {
    const [today, products, customers, statuses, chart] = await db.batch([
      db.prepare(`SELECT COUNT(*) AS orders, COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN MAX(0, subtotal - discount_amount) ELSE 0 END), 0) AS revenue FROM orders WHERE date(created_at) = date('now')`),
      db.prepare("SELECT COUNT(*) AS count FROM products WHERE is_active = 1"),
      db.prepare("SELECT COUNT(DISTINCT lower(buyer_email)) AS count FROM orders"),
      db.prepare(`SELECT fulfillment_status AS status, COUNT(*) AS count FROM orders GROUP BY fulfillment_status`),
      db.prepare(`WITH RECURSIVE dates(day) AS (SELECT date('now', '-6 day') UNION ALL SELECT date(day, '+1 day') FROM dates WHERE day < date('now')) SELECT dates.day, COUNT(orders.id) AS orders, COALESCE(SUM(CASE WHEN orders.payment_status = 'paid' THEN MAX(0, orders.subtotal - orders.discount_amount) ELSE 0 END), 0) AS revenue FROM dates LEFT JOIN orders ON date(orders.created_at) = dates.day GROUP BY dates.day ORDER BY dates.day ASC`),
    ]);
    const todayRow = today.results[0] as { orders: number; revenue: number } | undefined;
    const productRow = products.results[0] as { count: number } | undefined;
    const customerRow = customers.results[0] as { count: number } | undefined;
    const statusRows = statuses.results as Array<{ status: string; count: number }>;
    const success = statusRows.filter((row) => row.status === "success").reduce((sum, row) => sum + Number(row.count), 0);
    return Response.json({
      role: access.role,
      canViewFinance: access.role === "owner",
      metrics: {
        todayOrders: Number(todayRow?.orders || 0),
        todayRevenue: access.role === "owner" ? Number(todayRow?.revenue || 0) : null,
        activeProducts: Number(productRow?.count || 0),
        customers: Number(customerRow?.count || 0),
        successfulOrders: success,
      },
      statuses: statusRows.map((row) => ({ status: row.status, count: Number(row.count) })),
      chart: chart.results.map((row) => ({ day: row.day, orders: Number(row.orders), revenue: access.role === "owner" ? Number(row.revenue) : null })),
    }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Ringkasan gagal dimuat." }, { status: 503 });
  }
}
