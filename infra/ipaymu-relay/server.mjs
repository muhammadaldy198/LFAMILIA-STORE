import { createServer } from "node:http";
import { createRelayHandler } from "./relay.mjs";

const secret = process.env.RELAY_SHARED_SECRET?.trim();
const upstreamOrigin = process.env.RELAY_UPSTREAM_ORIGIN?.trim() || "https://my.ipaymu.com";
const host = process.env.RELAY_HOST?.trim() || "127.0.0.1";
const port = Number(process.env.RELAY_PORT || 8788);

if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error("RELAY_PORT tidak valid.");

const handler = createRelayHandler({ secret, upstreamOrigin });
const server = createServer((req, res) => void handler(req, res));

server.requestTimeout = 20_000;
server.headersTimeout = 10_000;
server.keepAliveTimeout = 5_000;
server.listen(port, host, () => {
  console.log(`LFAMILIA iPaymu relay aktif di ${host}:${port}`);
});

function shutdown() {
  server.close((error) => {
    if (error) {
      console.error(error);
      process.exitCode = 1;
    }
  });
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);

