export type PaymentMethodCode = "va" | "ewallet" | "qris";
export type PaymentGatewayCode = "midtrans" | "doku";

export type PaymentChannel = {
  method: PaymentMethodCode;
  channel: string;
  name: string;
  description: string;
  gateway: PaymentGatewayCode;
};

export const paymentChannels: PaymentChannel[] = [
  { method: "va", channel: "bca", name: "BCA", description: "Virtual Account BCA", gateway: "midtrans" },
  { method: "va", channel: "mandiri", name: "Mandiri", description: "Virtual Account Mandiri", gateway: "midtrans" },
  { method: "va", channel: "bni", name: "BNI", description: "Virtual Account BNI", gateway: "midtrans" },
  { method: "va", channel: "bri", name: "BRI", description: "Virtual Account BRI", gateway: "midtrans" },
  { method: "va", channel: "cimb", name: "CIMB Niaga", description: "Virtual Account CIMB Niaga", gateway: "midtrans" },
  { method: "va", channel: "permata", name: "Permata", description: "Virtual Account Permata", gateway: "midtrans" },
  { method: "va", channel: "danamon", name: "Danamon", description: "Virtual Account Danamon", gateway: "midtrans" },
  { method: "ewallet", channel: "dana", name: "DANA", description: "Bayar melalui aplikasi DANA", gateway: "doku" },
  { method: "ewallet", channel: "shopeepay", name: "ShopeePay", description: "Bayar melalui aplikasi ShopeePay", gateway: "doku" },
  { method: "qris", channel: "mpm", name: "QRIS", description: "Scan dari aplikasi bank atau e-wallet", gateway: "doku" },
];

export function findPaymentChannel(method: string, channel: string) {
  return paymentChannels.find((item) => item.method === method && item.channel === channel);
}

export const paymentGroups = [
  { code: "va" as const, name: "Virtual Account", description: "Transfer bank dengan nomor VA unik" },
  { code: "ewallet" as const, name: "E-Wallet", description: "Bayar melalui aplikasi e-wallet yang tersedia" },
  { code: "qris" as const, name: "QRIS", description: "Scan dari semua aplikasi yang mendukung QRIS" },
];
