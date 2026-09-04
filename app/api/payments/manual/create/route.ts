export const dynamic = "force-dynamic";

export async function POST() {
  return Response.json(
    { error: "Pembayaran manual sudah dinonaktifkan. Gunakan payment gateway atau saldo LFAMILIA." },
    { status: 410 },
  );
}
