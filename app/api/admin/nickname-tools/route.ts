import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { classifyKokinpayFailure } from "@/lib/server/kokinpay-errors";
import {
  NicknameServiceError,
  NicknameValidationError,
  lookupKokinpayNickname,
} from "@/lib/server/nickname-check";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

const noStoreHeaders = { "Cache-Control": "no-store" };
const gameRequest = z.object({
  action: z.literal("game"),
  gameCode: z.string().trim().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(100),
  userId: z.string().trim().min(2).max(80),
  server: z.string().trim().min(1).max(40).optional(),
});
const regionRequest = z.object({
  action: z.literal("region"),
  userId: z.string().trim().min(2).max(80),
  server: z.string().trim().min(1).max(40),
});
const plnRequest = z.object({
  action: z.literal("pln"),
  customerNumber: z.string().trim().regex(/^\d{11,12}$/, "Nomor meter PLN harus 11–12 angka."),
});
const requestSchema = z.discriminatedUnion("action", [gameRequest, regionRequest, plnRequest]);

type RuntimeEnv = { KOKINPAY_API_KEY?: string };
type KokinpayPlnResponse = {
  status?: unknown;
  message?: unknown;
  data?: { customer_name?: unknown; name?: unknown };
};

function text(value: unknown) {
  return typeof value === "string" && value.trim() ? value.trim() : null;
}

function apiKey() {
  const value = getRuntimeEnv<RuntimeEnv>().KOKINPAY_API_KEY?.trim();
  if (!value) throw new NicknameServiceError("API Key KokinPay belum disimpan.");
  return value;
}

async function checkPln(customerNumber: string) {
  let response: Response;
  try {
    response = await fetch("https://api.kokinpay.com/v1/check-pln", {
      method: "POST",
      headers: { accept: "application/json", "content-type": "application/json" },
      body: JSON.stringify({ api_key: apiKey(), customer_number: customerNumber }),
      signal: AbortSignal.timeout(8_000),
    });
  } catch {
    throw new NicknameServiceError();
  }

  let payload: KokinpayPlnResponse;
  try {
    payload = await response.json() as KokinpayPlnResponse;
  } catch {
    throw new NicknameServiceError();
  }
  if (!response.ok || payload.status !== true) {
    const message = text(payload.message);
    const kind = classifyKokinpayFailure(response.status);
    if (kind === "authentication") {
      throw new NicknameServiceError(message || "API Key KokinPay tidak valid atau tidak dapat digunakan.");
    }
    if (kind === "validation") {
      throw new NicknameValidationError(message || "Data PLN tidak ditemukan atau tidak valid.");
    }
    throw new NicknameServiceError(message || undefined);
  }

  const customerName = text(payload.data?.customer_name) || text(payload.data?.name);
  if (!customerName) {
    throw new NicknameServiceError("Layanan pengecekan PLN tidak mengembalikan nama pelanggan.");
  }
  return customerName;
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = requestSchema.parse(await request.json());
    if (input.action === "game") {
      const result = await lookupKokinpayNickname({
        apiKey: apiKey(),
        gameCode: input.gameCode,
        userId: input.userId,
        server: input.server,
      });
      return Response.json(
        { ok: true, action: input.action, nickname: result.nickname, region: result.country },
        { headers: noStoreHeaders },
      );
    }
    if (input.action === "region") {
      const result = await lookupKokinpayNickname({
        apiKey: apiKey(),
        gameCode: "mobile-legends",
        userId: input.userId,
        server: input.server,
      });
      if (!result.nickname || !result.country) {
        throw new NicknameServiceError("Validasi Mobile Legends tidak mengembalikan nickname dan region lengkap.");
      }
      return Response.json({
        ok: true,
        action: input.action,
        nickname: result.nickname,
        region: result.country,
      }, { headers: noStoreHeaders });
    }

    const customerName = await checkPln(input.customerNumber);
    return Response.json({ ok: true, action: input.action, customerName }, { headers: noStoreHeaders });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Data pemeriksaan tidak valid."
      : error instanceof Error
        ? error.message
        : "Pemeriksaan tidak dapat diproses.";
    const status = error instanceof NicknameValidationError || error instanceof z.ZodError ? 400
      : error instanceof NicknameServiceError ? 503
      : 502;
    return Response.json({ error: message }, { status, headers: noStoreHeaders });
  }
}
