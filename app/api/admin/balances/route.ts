import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

export const dynamic = "force-dynamic";

const adjustmentSchema = z.object({
  accountType: z.enum(["customer", "admin"]),
  targetId: z.string().trim().min(1).max(80),
  operation: z.enum(["credit", "debit"]),
  amount: z.number().int().min(1).max(100_000_000),
  reason: z.string().trim().min(3).max(300),
});

type BalanceTarget = { ledger_id: string; target_name: string; target_email: string; balance: number };

async function readOverview() {
  const db = getD1();
  const [customers, admins] = await db.batch([
    db.prepare(`SELECT u.id, u.name, u.email, u.phone, u.balance, u.is_active, u.created_at,
      COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS lifetime_spend,
      COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_orders
      FROM customer_users u LEFT JOIN orders o ON o.customer_id = u.id
      WHERE u.email NOT LIKE '__lfadmin__:%'
      GROUP BY u.id ORDER BY u.created_at DESC LIMIT 500`),
    db.prepare(`SELECT CAST(a.id AS TEXT) AS id, a.name, a.email, a.role, a.is_active,
      c.id AS ledger_id, c.balance
      FROM admin_users a JOIN customer_users c ON c.email = ('__lfadmin__:' || lower(a.email))
      ORDER BY CASE a.role WHEN 'owner' THEN 0 ELSE 1 END, a.name ASC`),
  ]);
  return { customers: customers.results, admins: admins.results };
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    await ensureLegacyDatabaseColumns();
    return Response.json(await readOverview(), { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Data saldo gagal dimuat." }, { status: 503 });
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = adjustmentSchema.parse(await request.json());
    await ensureLegacyDatabaseColumns();
    const db = getD1();
    const target = input.accountType === "customer"
      ? await db.prepare(`SELECT id AS ledger_id, name AS target_name, email AS target_email, balance
          FROM customer_users WHERE id = ? AND email NOT LIKE '__lfadmin__:%' LIMIT 1`).bind(input.targetId).first<BalanceTarget>()
      : await db.prepare(`SELECT c.id AS ledger_id, a.name AS target_name, a.email AS target_email, c.balance
          FROM admin_users a JOIN customer_users c ON c.email = ('__lfadmin__:' || lower(a.email))
          WHERE CAST(a.id AS TEXT) = ? LIMIT 1`).bind(input.targetId).first<BalanceTarget>();
    if (!target) throw new Error(input.accountType === "customer" ? "Pelanggan tidak ditemukan." : "Akun admin tidak ditemukan.");
    const before = Math.max(0, Number(target.balance || 0));
    if (input.operation === "debit" && before < input.amount) throw new Error("Saldo tidak mencukupi untuk dikurangi.");
    const direction = input.operation === "credit" ? "credit" : "debit";
    const delta = direction === "credit" ? input.amount : -input.amount;
    const reference = `admin-adjustment:${crypto.randomUUID()}`;
    const description = `${input.accountType === "admin" ? "Saldo admin" : "Saldo pelanggan"} oleh ${access.email}: ${input.reason}`;
    const results = await db.batch([
      db.prepare(`INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
        SELECT ?, id, ?, ?, balance, balance + ?, ?, ? FROM customer_users
        WHERE id = ? AND (? = 'credit' OR balance >= ?)`)
        .bind(crypto.randomUUID(), direction, input.amount, delta, reference, description, target.ledger_id, direction, input.amount),
      db.prepare(`UPDATE customer_users SET balance = balance + ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND (? = 'credit' OR balance >= ?)`)
        .bind(delta, target.ledger_id, direction, input.amount),
    ]);
    if (!results[1]?.meta.changes) throw new Error("Saldo berubah sebelum transaksi selesai. Muat ulang lalu coba lagi.");
    const updated = await db.prepare("SELECT balance FROM customer_users WHERE id = ? LIMIT 1").bind(target.ledger_id).first<{ balance: number }>();
    const balanceAfter = Number(updated?.balance ?? before + delta);
    return Response.json({ ok: true, balanceBefore: balanceAfter - delta, balanceAfter, target: { id: input.targetId, name: target.target_name, email: target.target_email }, ...(await readOverview()) });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message || "Perubahan saldo tidak valid." : error instanceof Error ? error.message : "Saldo gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
