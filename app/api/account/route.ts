import { z } from "zod";
import { getD1 } from "@/db";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { normalizeWhatsappPhone } from "@/lib/phone";
import { listCustomerWebsiteVoucherCodes } from "@/lib/server/customer-voucher-codes";
import { getMemberTierProfile } from "@/lib/server/member-tiers";
import { rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

function publicReferenceId(value: string) {
  const clean = value.trim().toUpperCase();
  if (!clean.includes("-")) return clean;
  const token = clean.split("-").at(-1) ?? clean.replace(/^LF/, "");
  return `LF${token}`;
}

export async function GET(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  const db = getD1();
  const [topups, transactions, orders, membership] = await Promise.all([
    db.prepare("SELECT id, amount, sender_name, payment_method, proof_url, status, admin_notes, created_at FROM wallet_topups WHERE customer_id = ? ORDER BY created_at DESC LIMIT 40").bind(customer.id).all(),
    db.prepare("SELECT id, direction, amount, balance_before, balance_after, reference, description, created_at FROM wallet_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 60").bind(customer.id).all(),
    db.prepare("SELECT id, reference_id, product_name, package_label, total, payment_status, fulfillment_status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50").bind(customer.id).all<{ id: string; reference_id: string; product_name: string; package_label: string; total: number; payment_status: string; fulfillment_status: string; created_at: string }>(),
    getMemberTierProfile(customer.id),
  ]);
  const vouchers = await listCustomerWebsiteVoucherCodes(customer.id).catch(() => []);
  return Response.json({
    customer,
    membership,
    topups: topups.results,
    transactions: transactions.results,
    orders: orders.results.map((order) => ({ ...order, reference_id: publicReferenceId(order.reference_id) })),
    vouchers: vouchers.map((voucher) => ({ ...voucher, referenceId: publicReferenceId(voucher.referenceId) })),
  }, { headers: { "Cache-Control": "no-store" } });
}

const profileSchema = z.object({
  name: z.string().trim().min(2).max(80),
  phone: z.string().trim().regex(/^\+?[0-9]{8,16}$/),
  leaderboardOptIn: z.boolean(),
});

export async function PATCH(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    const input = profileSchema.parse(await request.json());
    const normalizedPhone = normalizeWhatsappPhone(input.phone);
    await getD1().prepare("UPDATE customer_users SET name = ?, phone = ?, leaderboard_opt_in = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(input.name, normalizedPhone, input.leaderboardOptIn ? 1 : 0, customer.id).run();
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : "Profil gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}
