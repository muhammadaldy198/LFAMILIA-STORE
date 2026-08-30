import { getD1 } from "@/db";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const period = new URL(request.url).searchParams.get("period") === "all" ? "all" : "month";
  try {
    const dateFilter = period === "month" ? "AND o.created_at >= datetime('now', 'start of month')" : "";
    const result = await getD1().prepare(
      `SELECT u.name, COUNT(o.id) AS order_count, COALESCE(SUM(o.total), 0) AS total_spent
       FROM customer_users u JOIN orders o ON o.customer_id = u.id
       WHERE u.is_active = 1 AND u.leaderboard_opt_in = 1 AND o.payment_status = 'paid' ${dateFilter}
       GROUP BY u.id, u.name ORDER BY total_spent DESC, order_count DESC LIMIT 20`,
    ).all<{ name: string; order_count: number; total_spent: number }>();
    return Response.json({ period, entries: result.results.map((row, index) => ({ rank: index + 1, name: leaderboardName(row.name), orderCount: Number(row.order_count), totalSpent: Number(row.total_spent) })) }, { headers: { "Cache-Control": "public, max-age=60" } });
  } catch {
    return Response.json({ period, entries: [] });
  }
}

function leaderboardName(name: string) {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length < 2) return words[0] || "Pelanggan";
  return `${words[0]} ${words[words.length - 1][0]?.toUpperCase() ?? ""}.`;
}
