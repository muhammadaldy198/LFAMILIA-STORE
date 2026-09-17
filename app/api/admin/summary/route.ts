import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import {
  getDigiflazzBalance,
  getDigiflazzReadiness,
} from "@/lib/server/providers/digiflazz";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type DashboardRange = "today" | "7d" | "30d" | "90d" | "all";
const validRanges: DashboardRange[] = ["today", "7d", "30d", "90d", "all"];

function readRange(request: Request): DashboardRange {
  const value = new URL(request.url).searchParams.get("range");
  return validRanges.includes(value as DashboardRange)
    ? (value as DashboardRange)
    : "7d";
}

function periodClause(range: DashboardRange, column: string) {
  switch (range) {
    case "today":
      return `date(${column}) = date('now')`;
    case "7d":
      return `date(${column}) >= date('now', '-6 day')`;
    case "30d":
      return `date(${column}) >= date('now', '-29 day')`;
    case "90d":
      return `date(${column}) >= date('now', '-89 day')`;
    default:
      return "1 = 1";
  }
}

function trendDays(range: DashboardRange) {
  if (range === "today") return 1;
  if (range === "7d") return 7;
  if (range === "30d") return 30;
  if (range === "90d") return 90;
  return 30;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "staff");
  if (access instanceof Response) return access;

  const db = getD1();
  const range = readRange(request);
  const orderPeriod = periodClause(range, "o.created_at");
  const topupPeriod = periodClause(range, "created_at");
  const days = trendDays(range);
  const firstTrendDay = `-${days - 1} day`;
  const canViewFinance = access.role === "super_admin";

  try {
    const common = await db.batch([
      db.prepare(
        `SELECT
          COUNT(*) AS total_orders,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS paid_revenue,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
            THEN MAX(o.total - COALESCE(o.supplier_cost_snapshot, 0), 0) ELSE 0 END), 0) AS profit,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.discount_amount ELSE 0 END), 0) AS total_discount,
          COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
          COALESCE(SUM(CASE WHEN o.payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
            AND o.fulfillment_status NOT IN ('success', 'failed', 'cancelled') THEN 1 ELSE 0 END), 0) AS pending_fulfillments,
          COALESCE(SUM(CASE WHEN o.payment_status = 'failed' OR o.fulfillment_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_orders
        FROM orders o
        WHERE ${orderPeriod}`,
      ),
      db.prepare(
        `SELECT
          COUNT(*) AS total_orders,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS paid_revenue,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
            THEN MAX(o.total - COALESCE(o.supplier_cost_snapshot, 0), 0) ELSE 0 END), 0) AS profit,
          COALESCE(SUM(CASE WHEN o.payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments,
          COALESCE(SUM(CASE WHEN o.payment_status = 'failed' OR o.fulfillment_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_orders
        FROM orders o
        WHERE date(o.created_at) = date('now')`,
      ),
      db.prepare("SELECT COUNT(*) AS count FROM products WHERE is_active = 1"),
      db.prepare("SELECT COUNT(*) AS count FROM customer_users WHERE is_active = 1 AND email NOT LIKE '__lfadmin__:%'"),
      db.prepare(`SELECT o.payment_status AS status, COUNT(*) AS count FROM orders o WHERE ${orderPeriod} GROUP BY o.payment_status ORDER BY count DESC`),
      db.prepare(`SELECT o.fulfillment_status AS status, COUNT(*) AS count FROM orders o WHERE ${orderPeriod} GROUP BY o.fulfillment_status ORDER BY count DESC`),
      db.prepare(
        `WITH RECURSIVE dates(day) AS (
          SELECT date('now', '${firstTrendDay}')
          UNION ALL
          SELECT date(day, '+1 day') FROM dates WHERE day < date('now')
        )
        SELECT
          dates.day AS day,
          COUNT(o.id) AS orders,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
            THEN MAX(o.total - COALESCE(o.supplier_cost_snapshot, 0), 0) ELSE 0 END), 0) AS profit
        FROM dates
        LEFT JOIN orders o ON date(o.created_at) = dates.day
        GROUP BY dates.day
        ORDER BY dates.day ASC`,
      ),
      db.prepare(
        `SELECT
          o.product_slug AS slug,
          o.product_name AS name,
          COUNT(*) AS total_orders,
          COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
            THEN MAX(o.total - COALESCE(o.supplier_cost_snapshot, 0), 0) ELSE 0 END), 0) AS profit
        FROM orders o
        WHERE ${orderPeriod}
        GROUP BY o.product_slug, o.product_name
        ORDER BY fulfilled_orders DESC, total_orders DESC, revenue DESC
        LIMIT 5`,
      ),
      db.prepare(
        `SELECT
          COALESCE(p.category, 'lainnya') AS category,
          COUNT(*) AS total_orders,
          COALESCE(SUM(CASE WHEN o.fulfillment_status = 'success' THEN 1 ELSE 0 END), 0) AS fulfilled_orders,
          COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue
        FROM orders o
        LEFT JOIN products p ON p.slug = o.product_slug
        WHERE ${orderPeriod}
        GROUP BY COALESCE(p.category, 'lainnya')
        ORDER BY fulfilled_orders DESC, total_orders DESC, revenue DESC
        LIMIT 5`,
      ),
      db.prepare(
        `SELECT
          o.id, o.reference_id, o.product_name, o.package_label, o.buyer_name,
          o.payment_method, o.payment_channel, o.payment_status,
          o.fulfillment_status, o.total, o.created_at
        FROM orders o
        WHERE ${orderPeriod}
        ORDER BY o.created_at DESC
        LIMIT 8`,
      ),
    ]);

    const metricRow = common[0].results[0] as Record<string, number> | undefined;
    const todayRow = common[1].results[0] as Record<string, number> | undefined;
    const productRow = common[2].results[0] as { count?: number } | undefined;
    const customerRow = common[3].results[0] as { count?: number } | undefined;

    let approvedTopups = 0;
    let topCustomers: Array<{ name: string; email: string; orders: number; total: number }> = [];
    if (canViewFinance) {
      const finance = await db.batch([
        db.prepare(
          `SELECT COALESCE(SUM(amount), 0) AS total
           FROM wallet_topups
           WHERE status = 'approved' AND ${topupPeriod}`,
        ),
        db.prepare(
          `SELECT buyer_name AS name, buyer_email AS email, COUNT(*) AS orders,
             COALESCE(SUM(total), 0) AS total
           FROM orders o
           WHERE o.payment_status = 'paid' AND ${orderPeriod}
           GROUP BY lower(o.buyer_email), o.buyer_name
           ORDER BY total DESC, orders DESC
           LIMIT 5`,
        ),
      ]);
      approvedTopups = Number((finance[0].results[0] as { total?: number } | undefined)?.total || 0);
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

    const digiflazz = getDigiflazzReadiness();
    let publicBaseUrl = "";
    try {
      publicBaseUrl = getPublicBaseUrl();
    } catch {
      publicBaseUrl = "";
    }

    let digiflazzBalance: number | null = null;
    if (canViewFinance && digiflazz.ready) {
      try {
        digiflazzBalance = (await getDigiflazzBalance()).balance;
      } catch {
        digiflazzBalance = null;
      }
    }

    const attention = {
      sellerOff: 0,
      outOfStock: 0,
      priceChanged: 0,
      digiflazzPending: 0,
      paymentCallbackFailed: 0,
      manualPending: 0,
    };
    let lastDigiflazzSync: string | null = null;
    let recentActivities: Array<{
      id: string;
      adminName: string;
      adminRole: string;
      action: string;
      target: string;
      createdAt: string;
    }> = [];

    try {
      const attentionRows = await db.batch([
        db.prepare("SELECT COUNT(*) AS count FROM digiflazz_seller_monitor WHERE seller_product_status = 0"),
        db.prepare("SELECT COUNT(*) AS count FROM digiflazz_seller_monitor WHERE unlimited_stock = 0 AND stock <= 0"),
        db.prepare("SELECT COUNT(*) AS count FROM digiflazz_seller_monitor WHERE baseline_price IS NOT NULL AND current_price IS NOT NULL AND current_price <> baseline_price"),
        db.prepare("SELECT COUNT(*) AS count FROM orders WHERE provider_code = 'digiflazz' AND payment_status = 'paid' AND fulfillment_status IN ('processing', 'dispatching', 'pending')"),
        db.prepare("SELECT COUNT(*) AS count FROM orders WHERE payment_method <> 'wallet' AND payment_status = 'failed'"),
        db.prepare("SELECT COUNT(*) AS count FROM orders WHERE fulfillment_type = 'manual' AND payment_status = 'paid' AND fulfillment_status IN ('manual_pending', 'processing')"),
      ]);
      attention.sellerOff = Number((attentionRows[0].results[0] as { count?: number } | undefined)?.count || 0);
      attention.outOfStock = Number((attentionRows[1].results[0] as { count?: number } | undefined)?.count || 0);
      attention.priceChanged = Number((attentionRows[2].results[0] as { count?: number } | undefined)?.count || 0);
      attention.digiflazzPending = Number((attentionRows[3].results[0] as { count?: number } | undefined)?.count || 0);
      attention.paymentCallbackFailed = Number((attentionRows[4].results[0] as { count?: number } | undefined)?.count || 0);
      attention.manualPending = Number((attentionRows[5].results[0] as { count?: number } | undefined)?.count || 0);
    } catch {
      // Monitoring tables may not exist yet on a legacy deployment.
    }

    try {
      const synced = await db.prepare(
        "SELECT MAX(supplier_synced_at) AS synced_at FROM product_packages WHERE provider_code = 'digiflazz'",
      ).first<{ synced_at?: string | null }>();
      lastDigiflazzSync = synced?.synced_at || null;
    } catch {
      lastDigiflazzSync = null;
    }

    try {
      const activities = await db.prepare(
        "SELECT id, admin_name, admin_role, action, target, created_at FROM admin_activity_logs ORDER BY created_at DESC LIMIT 8",
      ).all<{
        id: string;
        admin_name: string;
        admin_role: string;
        action: string;
        target: string;
        created_at: string;
      }>();
      recentActivities = activities.results.map((item) => ({
        id: item.id,
        adminName: item.admin_name,
        adminRole: item.admin_role,
        action: item.action,
        target: item.target,
        createdAt: item.created_at,
      }));
    } catch {
      recentActivities = [];
    }

    return Response.json(
      {
        range,
        role: access.role,
        canViewFinance,
        metrics: {
          totalOrders: Number(metricRow?.total_orders || 0),
          paidRevenue: canViewFinance ? Number(metricRow?.paid_revenue || 0) : null,
          profit: canViewFinance ? Number(metricRow?.profit || 0) : null,
          totalDiscount: canViewFinance ? Number(metricRow?.total_discount || 0) : null,
          approvedTopups: canViewFinance ? approvedTopups : null,
          activeProducts: Number(productRow?.count || 0),
          customers: Number(customerRow?.count || 0),
          fulfilledOrders: Number(metricRow?.fulfilled_orders || 0),
          pendingPayments: Number(metricRow?.pending_payments || 0),
          pendingFulfillments: Number(metricRow?.pending_fulfillments || 0),
          failedOrders: Number(metricRow?.failed_orders || 0),
        },
        todayMetrics: {
          paidRevenue: canViewFinance ? Number(todayRow?.paid_revenue || 0) : null,
          profit: canViewFinance ? Number(todayRow?.profit || 0) : null,
          totalOrders: Number(todayRow?.total_orders || 0),
          pendingPayments: Number(todayRow?.pending_payments || 0),
          failedOrders: Number(todayRow?.failed_orders || 0),
          activeProducts: Number(productRow?.count || 0),
        },
        paymentStatuses: common[4].results.map((row) => {
          const item = row as { status?: string; count?: number };
          return { status: item.status || "unknown", count: Number(item.count || 0) };
        }),
        fulfillmentStatuses: common[5].results.map((row) => {
          const item = row as { status?: string; count?: number };
          return { status: item.status || "unknown", count: Number(item.count || 0) };
        }),
        statuses: common[5].results.map((row) => {
          const item = row as { status?: string; count?: number };
          return { status: item.status || "unknown", count: Number(item.count || 0) };
        }),
        chart: common[6].results.map((row) => {
          const item = row as { day?: string; orders?: number; revenue?: number; profit?: number };
          return {
            day: item.day || "",
            orders: Number(item.orders || 0),
            revenue: canViewFinance ? Number(item.revenue || 0) : null,
            profit: canViewFinance ? Number(item.profit || 0) : null,
          };
        }),
        topProducts: common[7].results.map((row) => {
          const item = row as {
            slug?: string;
            name?: string;
            total_orders?: number;
            fulfilled_orders?: number;
            revenue?: number;
            profit?: number;
          };
          return {
            slug: item.slug || "",
            name: item.name || "Produk",
            totalOrders: Number(item.total_orders || 0),
            fulfilledOrders: Number(item.fulfilled_orders || 0),
            revenue: canViewFinance ? Number(item.revenue || 0) : null,
            profit: canViewFinance ? Number(item.profit || 0) : null,
          };
        }),
        topCategories: common[8].results.map((row) => {
          const item = row as {
            category?: string;
            total_orders?: number;
            fulfilled_orders?: number;
            revenue?: number;
          };
          return {
            category: item.category || "lainnya",
            totalOrders: Number(item.total_orders || 0),
            fulfilledOrders: Number(item.fulfilled_orders || 0),
            revenue: canViewFinance ? Number(item.revenue || 0) : null,
          };
        }),
        topCustomers: canViewFinance ? topCustomers : [],
        integrations: {
          digiflazz: {
            ready: access.role === "staff" ? false : digiflazz.ready,
            environment: canViewFinance ? digiflazz.environment : null,
            reason: canViewFinance ? digiflazz.reason : null,
            balance: digiflazzBalance,
            issues:
              attention.sellerOff +
              attention.outOfStock +
              attention.priceChanged,
            lastSyncAt: lastDigiflazzSync,
          },
          webhook: {
            ready: Boolean(publicBaseUrl),
            baseUrl: canViewFinance ? publicBaseUrl || null : null,
          },
        },
        attention: access.role === "staff" ? { pendingPayments: 0, pendingFulfillments: 0, failedOrders: 0, lowStock: 0, sellerOff: 0, outOfStock: 0, priceChanged: 0 } : attention,
        recentActivities: access.role === "staff" ? [] : recentActivities,
        recentOrders: common[9].results.map((row) => {
          const item = row as {
            id?: string;
            reference_id?: string;
            product_name?: string;
            package_label?: string;
            buyer_name?: string;
            payment_method?: string;
            payment_channel?: string;
            payment_status?: string;
            fulfillment_status?: string;
            total?: number;
            created_at?: string;
          };
          return {
            id: item.id || item.reference_id || item.created_at || "order",
            referenceId: item.reference_id || "-",
            productName: item.product_name || "Produk",
            packageLabel: item.package_label || "-",
            buyerName: item.buyer_name || "Pelanggan",
            paymentMethod: item.payment_method || "-",
            paymentChannel: item.payment_channel || "-",
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
