# LFAMILIA Provider Relay

Relay VPS hanya meneruskan request Digiflazz jika jalur egress statis dibutuhkan. Tidak menyimpan credential provider atau payment gateway.

```env
RELAY_BIND_HOST=<bind-host>
RELAY_BIND_PORT=<bind-port>
RELAY_TOKEN=<secret-random>
RELAY_MAX_BODY_BYTES=<max-request-bytes>
RELAY_UPSTREAM_TIMEOUT_MS=<provider-timeout-ms>
DIGIFLAZZ_RELAY_HOST=digiflazz-relay.lfamiliastore.my.id
DIGIFLAZZ_DEVELOPMENT_UPSTREAM_ORIGIN=https://api.digiflazz.com
DIGIFLAZZ_PRODUCTION_UPSTREAM_ORIGIN=https://api.digiflazz.com
```

Di Admin Panel isi URL relay Digiflazz dan token yang sama. Endpoint relay dibatasi ke transaction, price-list, dan cek-saldo Digiflazz.
