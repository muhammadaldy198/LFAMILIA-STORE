export type PaymentMethodCode = "va" | "ewallet" | "qris";

export type PaymentChannel = {
  method: PaymentMethodCode;
  channel: string;
  name: string;
  description: string;
};

export const paymentChannels: PaymentChannel[] = [
  { method: "va", channel: "bca", name: "BCA", description: "Virtual Account BCA" },
  { method: "va", channel: "mandiri", name: "Mandiri", description: "Virtual Account Mandiri" },
  { method: "va", channel: "bni", name: "BNI", description: "Virtual Account BNI" },
  { method: "va", channel: "bri", name: "BRI", description: "Virtual Account BRI" },
  { method: "va", channel: "bsi", name: "BSI", description: "Virtual Account BSI" },
  { method: "va", channel: "cimb", name: "CIMB Niaga", description: "Virtual Account CIMB Niaga" },
  { method: "va", channel: "permata", name: "Permata", description: "Virtual Account Permata" },
  { method: "va", channel: "danamon", name: "Danamon", description: "Virtual Account Danamon" },
  { method: "va", channel: "btn", name: "BTN", description: "Virtual Account BTN" },
  { method: "va", channel: "bmi", name: "Bank Muamalat", description: "Virtual Account Bank Muamalat" },
  { method: "va", channel: "bag", name: "Bank Artha Graha", description: "Virtual Account Bank Artha Graha" },
  { method: "va", channel: "bpd_bali", name: "BPD Bali", description: "Virtual Account BPD Bali" },
  { method: "ewallet", channel: "dana", name: "DANA", description: "Bayar melalui aplikasi DANA" },
  { method: "ewallet", channel: "shopeepay", name: "ShopeePay", description: "Bayar melalui aplikasi ShopeePay" },
  { method: "qris", channel: "mpm", name: "QRIS", description: "Scan dari aplikasi bank atau e-wallet" },
];

export function findPaymentChannel(method: string, channel: string) {
  return paymentChannels.find((item) => item.method === method && item.channel === channel);
}

export const paymentGroups = [
  { code: "va" as const, name: "Virtual Account", description: "Transfer bank dengan nomor VA unik" },
  { code: "ewallet" as const, name: "E-Wallet", description: "DANA atau ShopeePay melalui DOKU Direct API" },
  { code: "qris" as const, name: "QRIS", description: "Scan dari semua aplikasi yang mendukung QRIS" },
];
