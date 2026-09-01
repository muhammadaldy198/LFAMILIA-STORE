export const dynamic = "force-dynamic";

export async function POST() {
  return Response.json(
    { error: "Gateway iPaymu sudah digantikan oleh Midtrans." },
    { status: 410 },
  );
}
