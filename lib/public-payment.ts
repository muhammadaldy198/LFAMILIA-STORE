export function publicPaymentLabel(method: string, channel?: string | null) {
  const cleanChannel = channel?.trim().toUpperCase();
  if (method === "wallet") return "LFAMILIA Cash";
  if (method === "qris") return "QRIS";
  if (method === "va") {
    return cleanChannel && cleanChannel !== "VA"
      ? `Virtual Account ${cleanChannel}`
      : "Virtual Account";
  }
  if (method === "ewallet") {
    return cleanChannel && cleanChannel !== "EWALLET"
      ? `E-Wallet ${cleanChannel}`
      : "E-Wallet";
  }
  return "Pembayaran";
}
