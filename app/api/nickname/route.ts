import { z } from "zod";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

const games = {
  "mobile-legends": { endpoint: "ml", needsServer: true, numeric: true },
  "free-fire": { endpoint: "ff", needsServer: false, numeric: true },
  "genshin-impact": { endpoint: "gi", needsServer: false, numeric: true },
  valorant: { endpoint: "valo", needsServer: false, numeric: false },
} as const;

const requestSchema = z.object({
  game: z.enum(["mobile-legends", "free-fire", "genshin-impact", "valorant"]),
  userId: z.string().trim().min(4).max(32),
  server: z.string().trim().min(1).max(20).optional(),
});

type RuntimeEnv = {
  NICKNAME_API_URL?: string;
  NICKNAME_API_KEY?: string;
};

type ProviderResponse = {
  success?: boolean;
  name?: unknown;
  nickname?: unknown;
  username?: unknown;
  country?: unknown;
  message?: unknown;
  data?: { name?: unknown; nickname?: unknown; username?: unknown };
};

export async function POST(request: Request) {
  try {
    const input = requestSchema.parse(await request.json());
    const game = games[input.game];
    if (game.numeric && !/^\d+$/.test(input.userId)) return Response.json({ error: "User ID harus berupa angka." }, { status: 400 });
    if (game.needsServer && (!input.server || !/^\d+$/.test(input.server))) return Response.json({ error: "Server / Zone ID wajib diisi dengan angka." }, { status: 400 });

    const runtime = getRuntimeEnv<RuntimeEnv>();
    const baseUrl = (runtime.NICKNAME_API_URL?.trim() || "https://api.isan.eu.org/nickname").replace(/\/$/, "");
    const endpoint = new URL(`${baseUrl}/${game.endpoint}`);
    endpoint.searchParams.set("id", input.userId);
    if (input.server) endpoint.searchParams.set("server", input.server);
    endpoint.searchParams.set("decode", "false");

    const headers = new Headers({ accept: "application/json" });
    if (runtime.NICKNAME_API_KEY) headers.set("authorization", `Bearer ${runtime.NICKNAME_API_KEY}`);

    const upstream = await fetch(endpoint, { headers, signal: AbortSignal.timeout(8_000) });
    if (!upstream.ok) return Response.json({ error: "Akun tidak ditemukan atau layanan verifikasi sedang sibuk." }, { status: upstream.status === 404 ? 404 : 502 });
    const data = await upstream.json() as ProviderResponse;
    if (data.success === false) return Response.json({ error: typeof data.message === "string" ? data.message : "ID atau Server tidak ditemukan." }, { status: 404 });

    const rawName = [data.name, data.nickname, data.username, data.data?.name, data.data?.nickname, data.data?.username].find((value): value is string => typeof value === "string" && value.trim().length > 0);
    if (!rawName) return Response.json({ error: "Nickname tidak ditemukan pada jawaban penyedia." }, { status: 404 });

    let nickname = rawName.trim();
    try { nickname = decodeURIComponent(nickname); } catch { /* keep provider value */ }
    const country = typeof data.country === "string" && data.country.trim() ? data.country.trim() : null;
    return Response.json({ nickname, country, game: input.game, userId: input.userId, server: input.server ?? null });
  } catch (error) {
    const message = error instanceof z.ZodError ? error.issues[0]?.message : "Permintaan pengecekan tidak valid.";
    return Response.json({ error: message }, { status: 400 });
  }
}
