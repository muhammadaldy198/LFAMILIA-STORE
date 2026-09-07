import { requireAdminSession } from "@/lib/server/admin";
import {
  getDokuDatabasePreparationStatus,
  prepareDokuDatabase,
} from "@/lib/server/doku-database-preparation";
import { rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    return Response.json(await getDokuDatabasePreparationStatus(), {
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    return Response.json(
      {
        error:
          error instanceof Error
            ? error.message
            : "Status database DOKU gagal diperiksa.",
      },
      { status: 503 },
    );
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;

  try {
    return Response.json(await prepareDokuDatabase(), {
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    return Response.json(
      {
        error:
          error instanceof Error
            ? error.message
            : "Persiapan database DOKU gagal.",
      },
      { status: 503 },
    );
  }
}
