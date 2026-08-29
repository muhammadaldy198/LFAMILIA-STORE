import { drizzle } from "drizzle-orm/d1";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import * as schema from "./schema";

type RuntimeEnv = { DB?: D1Database };

export function getDb() {
  const db = getRuntimeEnv<RuntimeEnv>().DB;
  if (!db) {
    throw new Error(
      "Cloudflare D1 binding `DB` is unavailable. Set the `d1` field in .openai/hosting.json to `DB` or let your control plane inject the real binding values before using the database."
    );
  }

  return drizzle(db, { schema });
}

export function getD1() {
  const db = getRuntimeEnv<RuntimeEnv>().DB;
  if (!db) {
    throw new Error("Cloudflare D1 binding `DB` is unavailable.");
  }

  return db;
}
