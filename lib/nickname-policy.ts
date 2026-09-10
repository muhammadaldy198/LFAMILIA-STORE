export type NicknamePolicy = {
  supported: boolean;
  needsServer: boolean;
  fallbackEndpoint?: string;
};

const nicknamePolicies: Record<string, NicknamePolicy> = {
  "mobile-legends": { supported: true, needsServer: true, fallbackEndpoint: "ml" },
  "free-fire": { supported: true, needsServer: false, fallbackEndpoint: "ff" },
  "genshin-impact": { supported: true, needsServer: false, fallbackEndpoint: "gi" },
  valorant: { supported: true, needsServer: false, fallbackEndpoint: "valo" },
  "pubg-mobile": { supported: true, needsServer: false },
  "honor-of-kings": { supported: true, needsServer: false },
  "call-of-duty-mobile": { supported: true, needsServer: false },
  "wild-rift": { supported: true, needsServer: false },
  "arena-of-valor": { supported: true, needsServer: false },
  "fc-mobile": { supported: true, needsServer: false },
  efootball: { supported: true, needsServer: false },
  "point-blank": { supported: true, needsServer: false },
};

const unsupportedPolicy: NicknamePolicy = {
  supported: false,
  needsServer: false,
};

export function getNicknamePolicy(productSlug: string): NicknamePolicy {
  return nicknamePolicies[productSlug.trim().toLowerCase()] ?? unsupportedPolicy;
}

export function supportsNicknameLookup(productSlug: string) {
  return getNicknamePolicy(productSlug).supported;
}
