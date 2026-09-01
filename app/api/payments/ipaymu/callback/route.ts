export const dynamic = "force-dynamic";

export async function POST() {
  return Response.json(
    {
      error: "Gateway iPaymu sudah tidak digunakan. Gunakan callback Midtrans.",
    },
    { status: 410 },
  );
}
