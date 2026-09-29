export function requireDigiflazzEndpoint(value: string, path: "/v1/transaction" | "/v1/price-list") {
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`URL DigiFlazz harus https://api.digiflazz.com${path}.`);
  }
  if (url.protocol !== "https:" || url.hostname !== "api.digiflazz.com" || url.port ||
      url.pathname !== path || url.username || url.password || url.search || url.hash) {
    throw new Error(`URL DigiFlazz harus https://api.digiflazz.com${path}.`);
  }
  return `https://api.digiflazz.com${path}`;
}
