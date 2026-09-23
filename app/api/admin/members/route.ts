import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  addMemberBalance,
  listMembersWithTiers,
  listMemberTierSettings,
  saveMemberTierSettings,
  setMemberRole,
} from "@/lib/server/member-tiers";
import { permanentlyDeleteEmptyCustomer } from "@/lib/server/customer-cleanup";

export const dynamic = "force-dynamic";

const tierSchema = z.object({
  tier: z.enum(["basic", "gold", "diamond", "platinum"]),
  discountPercent: z.number().min(0).max(100),
  benefits: z.string().trim().max(1000),
});

const updateSchema = z.object({
  settings: z.array(tierSchema).length(4),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const [settings, members] = await Promise.all([
      listMemberTierSettings(),
      listMembersWithTiers(),
    ]);
    if (access.role !== "super_admin") {
      return Response.json({ members: members.map((member) => ({
        id: member.id, name: member.name, email: member.email, phone: member.phone,
        isActive: member.isActive, createdAt: member.createdAt, paidOrders: member.paidOrders,
        tierMode: member.tierMode, tierOverride: member.tierOverride, tier: member.tier, tierLabel: member.tierLabel,
      })) });
    }
    return Response.json({ settings, members });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Data member gagal dimuat." },
      { status: 503 },
    );
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = updateSchema.parse(await request.json());
    const unique = new Set(input.settings.map((item) => item.tier));
    if (unique.size !== 4) throw new Error("Semua tier member wajib dikirim satu kali.");
    await saveMemberTierSettings(input.settings);
    return Response.json({ ok: true, settings: await listMemberTierSettings() });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message || "Pengaturan privilege tidak valid."
        : error instanceof Error
          ? error.message
          : "Pengaturan privilege gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}


const memberActionSchema = z.object({
  customerId: z.string().uuid(),
  role: z.enum(["automatic", "basic", "gold", "diamond", "platinum"]),
  addBalance: z.number().int().min(0).max(100_000_000).default(0),
  reason: z.string().trim().max(300).optional(),
});

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = memberActionSchema.parse(await request.json());
    await setMemberRole(input.customerId, input.role);
    if (input.addBalance > 0) {
      await addMemberBalance({ customerId: input.customerId, amount: input.addBalance, adminEmail: access.email, reason: input.reason });
    }
    return Response.json({ ok: true, members: await listMembersWithTiers() });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Perubahan member tidak valid."
      : error instanceof Error ? error.message : "Member gagal diperbarui.";
    return Response.json({ error: message }, { status: 400 });
  }
}


export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const customerId = new URL(request.url).searchParams.get("id")?.trim() || "";
    if (!z.string().uuid().safeParse(customerId).success) {
      return Response.json({ error: "ID pelanggan tidak valid." }, { status: 400 });
    }

    const result = await permanentlyDeleteEmptyCustomer(customerId);
    if (result.deleted) {
      return Response.json({ ok: true, members: await listMembersWithTiers() });
    }
    if (result.reason === "not_found") {
      return Response.json({ error: "Pelanggan tidak ditemukan." }, { status: 404 });
    }

    const eligibility = result.eligibility;
    const reasons = [
      Number(eligibility?.balance ?? 0) !== 0 ? "saldo belum Rp0" : "",
      Number(eligibility?.orderCount ?? 0) > 0 ? "sudah memiliki pesanan" : "",
      Number(eligibility?.topupCount ?? 0) > 0 ? "sudah memiliki riwayat top up" : "",
      Number(eligibility?.walletTransactionCount ?? 0) > 0 ? "sudah memiliki riwayat saldo" : "",
    ].filter(Boolean);
    return Response.json(
      {
        error: `Akun tidak boleh dihapus permanen karena ${reasons.join(", ") || "status akun berubah"}. Riwayat transaksi harus tetap dipertahankan.`,
        eligibility,
      },
      { status: 409 },
    );
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Akun pelanggan gagal dihapus." },
      { status: 400 },
    );
  }
}
