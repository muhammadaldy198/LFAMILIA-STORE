import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";

export const dynamic = "force-dynamic";

type DashboardRange = "today" | "7d" | "30d" | "90d" | "all";

const validRanges: DashboardRange[] = ["today", "7d", "30d", "90d", "all"];

function readRange(request: Request): DashboardRange {
  const value = new URL(request.url).searchParams.get("range");
  return validRanges.includes(value as DashboardRange) ? (value as DashboardRange) : "7d";
}

function periodClause(range: DashboardRange, column: string) {
  switch (range) {
    case "today":
      return "date(" + column + ") = date('now')";
    case "7d":
      return "date(" + column + ") >= date('now', '-6 day')";
    case "30d":
      return "date(" + column + ") >= date('now', '-29 day')";
    case "90d":
      return "date(" + column + ") >= date('now', '-89 day')";
    default:
      return "1 = 1";
  }
}

function trendDays(range: DashboardRange) {
  if (range === "today") return 1;
  if (range === "7d") return 7;
  if (range === "30d") return 30;
  return 30;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;

  const db = getD1();
  const range = readRange(request);
  const orderPeriod = periodClause(range, "created_at");
  const orderAliasPeriod = periodClause(range, "o.created_at");
  const topupPeriod = periodClause(range, "created_at");
  const days = trendDays(range);
  const firstTrendDay = "-" + (days - 1) + " day";

  try {
    const common = await db.batch([
      db.prepare(
        "SELECT " +
          "COUNT(*) AS total_orders, " +
          "COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END), 0) AS paid_revenue, " +
          "COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN discount_amount ELSE 0 END), 0) AS total_discount, " +
          "COALESCE(SUM(CASE WHEN fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders, " +
          "COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments, " +
          "COALESCE(SUM(CASE WHEN payment_status = 'paid' AND fulfillment_status NOT IN ('success', 'failed', 'cancelled') THEN 1 ELSE 0 END), 0) AS pending_fulfillments " +
          "FROM orders WHERE " + orderPeriod,
      ),
      db.prepare("SELECT COUNT(*) AS count FROM products WHERE is_active = 1"),
      db.prepare("SELECT COUNT(*) AS count FROM customer_users WHERE is_active = 1 AND email NOT LIKE '__lfadmin__:%'"),
      db.prepare("SELECT payment_status AS status, COUNT(*) AS count FROM orders WHERE " + orderPeriod + " GROUP BY payment_status ORDER BY count DESC"),
      db.prepare("SELECT fulfillment_status AS status, COUNT(*) AS count FROM orders WHERE " + orderPeriod + " GROUP BY fulfillment_status ORDER BY count DESC"),
      db.prepare(
        "WITH RECURSIVE dates(day) AS (" +
          "SELECT date('now', '" + firstTrendDay + "') " +
          "UNION ALL SELECT date(day, '+1 day') FROM dates WHERE day < date('now')" +
        ") " +
        "SELECT dates.day AS day, " +
          "COUNT(o.id) AS orders, " +
          "COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue " +
        "FROM dates LEFT JOIN orders o ON date(o.created_at) = dates.day " +
        "GROUP BY dates.day ORDER BY dates.day ASC",
      ),
      db.prepare(
        "SELECT o.product_slug AS slug, o.product_name AS name, COUNT(*) AS total_orders, " +
          "COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders, " +
          "COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue " +
        "FROM orders o WHERE " + orderAliasPeriod + " " +
        "GROUP BY o.product_slug, o.product_name " +
        "ORDER BY fulfilled_orders DESC, total_orders DESC, revenue DESC LIMIT 5",
      ),
      db.prepare(
        "SELECT COALESCE(p.category, 'lainnya') AS category, COUNT(*) AS total_orders, " +
          "COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders, " +
          "COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue " +
        "FROM orders o LEFT JOIN products p ON p.slug = o.product_slug " +
        "WHERE " + orderAliasPeriod + " " +
        "GROUP BY COALESCE(p.category, 'lainnya') " +
        "ORDER BY fulfilled_orders DESC, total_orders DESC, revenue DESC LIMIT 5",
      ),
      db.prepare(
        "SELECT id, reference_id, product_name, buyer_name, payment_status, fulfillment_status, total, created_at " +
        "FROM orders WHERE " + orderPeriod + " ORDER BY created_at DESC LIMIT 8",
      ),
    ]);

    const metricRow = common[0].results[0] as {
      total_orders?: number;
      paid_revenue?: number;
      total_discount?: number;
      fulfilled_orders?: number;
      pending_payments?: number;
      pending_fulfillments?: number;
    } | undefined;
    const productRow = common[1].results[0] as { count?: number } | undefined;
    const customerRow = common[2].results[0] as { count?: number } | undefined;
    const canViewFinance = access.role === "owner";

    let approvedTopups = 0;
    let topCustomers: Array<{ name: string; email: string; orders: number; total: number }> = [];

    if (canViewFinance) {
      const finance = await db.batch([
        db.prepare(
          "SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_topups " +
          "WHERE status = 'approved' AND " + topupPeriod,
        ),
        db.prepare(
          "SELECT buyer_name AS name, buyer_email AS email, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS total " +
          "FROM orders o WHERE o.payment_status = 'paid' AND " + orderAliasPeriod + " " +
          "GROUP BY lower(o.buyer_email), o.buyer_name " +
          "ORDER BY total DESC, orders DESC LIMIT 5",
        ),
      ]);
      const topupRow = finance[0].results[0] as { total?: number } | undefined;
      approvedTopups = Number(topupRow?.total || 0);
      topCustomers = finance[1].results.map((row) => {
        const item = row as { name?: string; email?: string; orders?: number; total?: number };
        return {
          name: item.name || "Pelanggan",
          email: item.email || "-",
          orders: Number(item.orders || 0),
          total: Number(item.total || 0),
        };
      });
    }

    return Response.json(
      {
        range,
        role: access.role,
        canViewFinance,
        metrics: {
          totalOrders: Number(metricRow?.total_orders || 0),
          paidRevenue: canViewFinance ? Number(metricRow?.paid_revenue || 0) : null,
          totalDiscount: canViewFinance ? Number(metricRow?.total_discount || 0) : null,
          approvedTopups: canViewFinance ? approvedTopups : null,
          activeProducts: Number(productRow?.count || 0),
          customers: Number(customerRow?.count || 0),
          fulfilledOrders: Number(metricRow?.fulfilled_orders || 0),
          pendingPayments: Number(metricRow?.pending_payments || 0),
          pendingFulfillments: Number(metricRow?.pending_fulfillments || 0),
        },
        paymentStatuses: common[3].results.map((row) => {
          const item = row as { status?: string; count?: number };
          return { status: item.status || "unknown", count: Number(item.count || 0) };
        }),
        fulfillmentStatuses: common[4].results.map((row) => {
          const item = row as { status?: string; count?: number };
          return { status: item.status || "unknown", count: Number(item.count || 0) };
        }),
        statuses: common[4].results.map((row) => {
          const item = row as { status?: string; count?: number };
          return { status: item.status || "unknown", count: Number(item.count || 0) };
        }),
        chart: common[5].results.map((row) => {
          const item = row as { day?: string; orders?: number; revenue?: number };
          return {
            day: item.day || "",
            orders: Number(item.orders || 0),
            revenue: canViewFinance ? Number(item.revenue || 0) : null,
          };
        }),
        topProducts: common[6].results.map((row) => {
          const item = row as { slug?: string; name?: string; total_orders?: number; fulfilled_orders?: number; revenue?: number };
          return {
            slug: item.slug || "",
            name: item.name || "Produk",
            totalOrders: Number(item.total_orders || 0),
            fulfilledOrders: Number(item.fulfilled_orders || 0),
            revenue: canViewFinance ? Number(item.revenue || 0) : null,
          };
        }),
        topCategories: common[7].results.map((row) => {
          const item = row as { category?: string; total_orders?: number; fulfilled_orders?: number; revenue?: number };
          return {
            category: item.category || "lainnya",
            totalOrders: Number(item.total_orders || 0),
            fulfilledOrders: Number(item.fulfilled_orders || 0),
            revenue: canViewFinance ? Number(item.revenue || 0) : null,
          };
        }),
        topCustomers: canViewFinance ? topCustomers : [],
        recentOrders: common[8].results.map((row) => {
          const item = row as {
            id?: string;
            reference_id?: string;
            product_name?: string;
            buyer_name?: string;
            payment_status?: string;
            fulfillment_status?: string;
            total?: number;
            created_at?: string;
          };
          return {
            id: item.id || item.reference_id || item.created_at || "order",
            referenceId: item.reference_id || "-",
            productName: item.product_name || "Produk",
            buyerName: item.buyer_name || "Pelanggan",
            paymentStatus: item.payment_status || "pending",
            fulfillmentStatus: item.fulfillment_status || "waiting_payment",
            total: canViewFinance ? Number(item.total || 0) : null,
            createdAt: item.created_at || "",
          };
        }),
      },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Ringkasan gagal dimuat." },
      { status: 503 },
    );
  }
}
