export type ProviderOrder = {
  id: string;
  referenceId: string;
  providerCode: string;
  providerSku: string;
  destination: string;
  server: string | null;
  customerNo: string;
  customerNotes: string | null;
  subtotal: number;
  packageSku: string;
  packageLabel: string;
  productName: string;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
};

export type ProviderResult = {
  externalId: string | null;
  status: "processing" | "success" | "failed";
  message: string;
  serialNumber: string | null;
  raw: unknown;
};

export type ProviderAdapter = {
  code: string;
  name: string;
  fulfill(order: ProviderOrder, publicBaseUrl: string): Promise<ProviderResult>;
};
