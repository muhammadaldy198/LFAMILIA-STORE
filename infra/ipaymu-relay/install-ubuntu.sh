#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Jalankan sebagai root."
  exit 1
fi

FQDN="${1:-ipaymu-relay.lfamiliastore.my.id}"
if [[ "${FQDN}" != "ipaymu-relay.lfamiliastore.my.id" ]]; then
  echo "Hostname harus ipaymu-relay.lfamiliastore.my.id"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nodejs caddy openssl ca-certificates curl

if ! id lfamilia-relay >/dev/null 2>&1; then
  useradd --system --home-dir /opt/lfamilia-ipaymu-relay --shell /usr/sbin/nologin lfamilia-relay
fi
install -d -m 0750 -o lfamilia-relay -g lfamilia-relay /opt/lfamilia-ipaymu-relay

printf '%s' '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' | base64 -d | tar -xz -C /opt/lfamilia-ipaymu-relay
chown -R lfamilia-relay:lfamilia-relay /opt/lfamilia-ipaymu-relay
chmod 0640 /opt/lfamilia-ipaymu-relay/*.mjs

RELAY_SECRET="$(openssl rand -hex 32)"
install -m 0600 /dev/null /etc/lfamilia-ipaymu-relay.env
printf 'RELAY_SHARED_SECRET=%s\nRELAY_UPSTREAM_ORIGIN=https://my.ipaymu.com\nRELAY_HOST=127.0.0.1\nRELAY_PORT=8788\n' "${RELAY_SECRET}" > /etc/lfamilia-ipaymu-relay.env
install -m 0600 /dev/null /root/lfamilia-relay-secret.txt
printf '%s\n' "${RELAY_SECRET}" > /root/lfamilia-relay-secret.txt
unset RELAY_SECRET

install -m 0644 /opt/lfamilia-ipaymu-relay/lfamilia-ipaymu-relay.service /etc/systemd/system/lfamilia-ipaymu-relay.service
install -m 0644 /opt/lfamilia-ipaymu-relay/Caddyfile /etc/caddy/Caddyfile

if command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH >/dev/null || true
  ufw allow 80/tcp >/dev/null || true
  ufw allow 443/tcp >/dev/null || true
fi

systemctl daemon-reload
systemctl enable --now lfamilia-ipaymu-relay
caddy validate --config /etc/caddy/Caddyfile
systemctl enable caddy
systemctl restart caddy
curl --fail --silent --show-error http://127.0.0.1:8788/healthz
printf '\nRelay aktif. Secret tersimpan di /root/lfamilia-relay-secret.txt\n'

