import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  NicknameServiceError,
  NicknameValidationError,
  lookupKokinpayNickname,
} from "@/lib/server/nickname-check";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

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
type KokinpayResponse = {
  status?: unknown;
  message?: unknown;
  data?: { nickname?: unknown; username?: unknown; region?: unknown; customer_name?: unknown; name?: unknown };
};

function text(value: unknown) {
  return typeof value === "string" && value.trim() ? value.trim() : null;
}

async function kokinpayPost(path: "check-region-mlbb" | "check-nick-pln", body: Record<string, string>) {
  const apiKey = getRuntimeEnv<RuntimeEnv>().KOKINPAY_API_KEY?.trim();
  if (!apiKey) throw new NicknameServiceError("API Key KokinPay belum disimpan.");
  let response: Response;
  try {
    response = await fetch(`https://api.kokinpay.com/${path}`, {
      method: "POST",
      headers: { accept: "application/json", "content-type": "application/json" },
      body: JSON.stringify({ api_key: apiKey, ...body }),
      signal: AbortSignal.timeout(8_000),
    });
  } catch {
    throw new NicknameServiceError();
  }
  let payload: KokinpayResponse;
  try {
    payload = await response.json() as KokinpayResponse;
  } catch {
    throw new NicknameServiceError();
  }
  if (!response.ok || payload.status !== true) {
    if (response.status === 400 || response.status === 404 || payload.status === false) {
      throw new NicknameValidationError(text(payload.message) || "Data tidak ditemukan atau tidak valid.");
    }
    throw new NicknameServiceError(text(payload.message) || undefined);
  }
  return payload;
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = requestSchema.parse(await request.json());
    if (input.action === "game") {
      const apiKey = getRuntimeEnv<RuntimeEnv>().KOKINPAY_API_KEY?.trim();
      if (!apiKey) throw new NicknameServiceError("API Key KokinPay belum disimpan.");
      const result = await lookupKokinpayNickname({
        apiKey,
        gameCode: input.gameCode,
        userId: input.userId,
        server: input.server,
      });
      return Response.json({ ok: true, action: input.action, nickname: result.nickname, region: result.country });
    }
    if (input.action === "region") {
      const result = await kokinpayPost("check-region-mlbb", { id: input.userId, server: input.server });
      return Response.json({
        ok: true,
        action: input.action,
        nickname: text(result.data?.nickname) || text(result.data?.username),
        region: text(result.data?.region),
      });
    }
    const result = await kokinpayPost("check-nick-pln", { customer_number: input.customerNumber });
    return Response.json({ ok: true, action: input.action, customerName: text(result.data?.customer_name) || text(result.data?.name) });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Data pemeriksaan tidak valid."
      : error instanceof Error
        ? error.message
        : "Pemeriksaan tidak dapat diproses.";
    const status = error instanceof NicknameValidationError || error instanceof z.ZodError ? 400
      : error instanceof NicknameServiceError ? 503
      : 502;
    return Response.json({ error: message }, { status, headers: { "Cache-Control": "no-store" } });
  }
}
