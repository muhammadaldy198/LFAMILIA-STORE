import { z } from "zod";
import { getD1 } from "@/db";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import { listCustomerVoucherCodes } from "@/lib/server/vouchers";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  const db = getD1();
  const [topups, transactions, orders, membership] = await Promise.all([
    db.prepare("SELECT id, amount, sender_name, payment_method, proof_url, status, admin_notes, created_at FROM wallet_topups WHERE customer_id = ? ORDER BY created_at DESC LIMIT 40").bind(customer.id).all(),
    db.prepare("SELECT id, direction, amount, balance_before, balance_after, reference, description, created_at FROM wallet_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 60").bind(customer.id).all(),
    db.prepare("SELECT id, reference_id, product_name, package_label, total, payment_status, fulfillment_status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50").bind(customer.id).all(),
    getMemberTierProfile(customer.id),
  ]);
  const vouchers = await listCustomerVoucherCodes(customer.id).catch(() => []);
  return Response.json({ customer, membership, topups: topups.results, transactions: transactions.results, orders: orders.results, vouchers });
}

const profileSchema = z.object({
  name: z.string().trim().min(2).max(80),
  phone: z.string().trim().regex(/^\+?[0-9]{8,16}$/),
  leaderboardOptIn: z.boolean(),
});

export async function PATCH(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    const input = profileSchema.parse(await request.json());
    await getD1().prepare("UPDATE customer_users SET name = ?, phone = ?, leaderboard_opt_in = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(input.name, input.phone, input.leaderboardOptIn ? 1 : 0, customer.id).run();
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : "Profil gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
