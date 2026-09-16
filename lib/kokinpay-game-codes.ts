export const KOKINPAY_GAME_CODES = [
  ["Mobile Legends", "mobile-legends", true],
  ["Free Fire", "free-fire", false],
  ["PUBG Mobile", "pubg-mobile", false],
  ["Call of Duty Mobile", "call-of-duty-mobile", false],
  ["Valorant", "valorant", false],
  ["Genshin Impact", "genshin-impact", true],
  ["Honor of Kings", "honor-of-kings", false],
  ["League of Legends: Wild Rift", "league-of-legends-wild-rift", false],
  ["Arena of Valor", "arena-of-valor", false],
  ["Point Blank", "point-blank", false],
  ["Free Fire Max", "free-fire-max", false],
  ["Whiteout Survival", "whiteout-survival", false],
  ["Honkai Impact 3", "honkai-impact-3", false],
  ["Honkai: Star Rail", "honkai-star-rail", true],
  ["Eggy Party", "eggy-party", true],
  ["Undawn", "undawn", false],
  ["Growtopia", "growtopia", false],
  ["League of Legends PC", "league-of-legends-pc", false],
  ["FC Mobile", "fc-mobile", false],
  ["Super Sus", "super-sus", false],
  ["Harry Potter: Magic Awakened", "harry-potter-magic-awakened", true],
  ["Revelation: Infinite Journey", "revelation-infinite-journey", false],
  ["MU Origin 3", "mu-origin-3", false],
  ["Sausage Man", "sausage-man", false],
  ["Speed Drifters", "speed-drifters", false],
  ["Tom and Jerry: Chase", "tom-and-jerry-chase", true],
  ["Teamfight Tactics Mobile", "teamfight-tactics-mobile", false],
  ["LifeAfter", "lifeafter", true],
  ["Laplace M", "laplace-m", false],
  ["Arena Breakout", "arena-breakout", false],
  ["Zenless Zone Zero", "zenless-zone-zero", true],
  ["AFK Journey", "afk-journey", false],
  ["Magic Chess Go Go", "magic-chess-go-go", true],
  ["Love and Deepspace", "love-and-deepspace", false],
  ["Pokemon Unite", "pokemon-unite", false],
  ["Dragon Raja", "dragon-raja", false],
  ["Football Master 2", "football-master-2", false],
  ["Garena Shell", "garena-shell", false],
  ["Goddess of Victory: Nikke", "goddess-of-victory-nikke", true],
  ["Metal Slug: Awakening", "metal-slug-awakening", false],
  ["Ragnarok M: Eternal Love", "ragnarok-m-eternal-love", true],
] as const;

const SERVER_REQUIRED_CODES = new Set<string>(
  KOKINPAY_GAME_CODES
    .filter(([, , needsServer]) => needsServer)
    .map(([, code]) => code),
);

export function kokinpayGameRequiresServer(gameCode: string) {
  return SERVER_REQUIRED_CODES.has(gameCode.trim().toLowerCase());
}
