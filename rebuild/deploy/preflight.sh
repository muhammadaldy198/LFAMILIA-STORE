#!/usr/bin/env bash
set -euo pipefail

OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${REPO_DIR:?REPO_DIR is required}"
: "${APP_SUBDIR:?APP_SUBDIR is required}"
: "${PHP_BIN:?PHP_BIN is required}"
: "${COMPOSER_BIN:?COMPOSER_BIN is required}"
: "${NPM_BIN:?NPM_BIN is required}"
: "${PHP_FPM_SERVICE:?PHP_FPM_SERVICE is required}"
: "${MYSQL_DEFAULTS_FILE:?MYSQL_DEFAULTS_FILE is required}"
: "${MYSQL_ADMIN_DEFAULTS_FILE:?MYSQL_ADMIN_DEFAULTS_FILE is required}"
: "${BACKUP_DATABASE_NAME:?BACKUP_DATABASE_NAME is required}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
ENV_FILE="${APP_DIR}/.env"
[[ -d "${APP_DIR}" ]] || { echo "APP_DIR not found: ${APP_DIR}" >&2; exit 1; }
[[ -r "${ENV_FILE}" ]] || { echo "Production .env is missing" >&2; exit 1; }
[[ -r "${MYSQL_DEFAULTS_FILE}" ]] || { echo "MySQL backup defaults file is not readable" >&2; exit 1; }
[[ -r "${MYSQL_ADMIN_DEFAULTS_FILE}" ]] || { echo "MySQL admin defaults file is not readable" >&2; exit 1; }
[[ -r "${BACKUP_PASSPHRASE_FILE}" ]] || { echo "Backup passphrase file is not readable" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "Missing command: $1" >&2; exit 1; }
}
for cmd in git curl mysql mysqldump redis-cli openssl sha256sum tar systemctl; do
  require_cmd "${cmd}"
done

systemctl cat "${PHP_FPM_SERVICE}" >/dev/null 2>&1 \
  || { echo "PHP-FPM service not found: ${PHP_FPM_SERVICE}" >&2; exit 1; }

"${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' \
  || { echo "PHP 8.4+ is required" >&2; exit 1; }

for ext in pdo_mysql redis mbstring openssl; do
  "${PHP_BIN}" -r "exit(extension_loaded('${ext}') ? 0 : 1);" \
    || { echo "Missing PHP extension: ${ext}" >&2; exit 1; }
done

# start of argon2id capability check
# The default password hash driver is Argon2id (config/hashing.php). A PHP
# build without Argon2id support would pass every other check in this
# script and still break password creation and login rehashing after the
# deploy, so this is a hard gate, not a warning. It resolves the EFFECTIVE
# configuration exactly as the application will: the config file's env()
# calls are answered with Laravel's own precedence - a value exported
# into the process environment wins (deploy.sh sources ops.env, and
# artisan config:cache inherits whatever it exports, freezing it into
# the production config), then the production .env (first occurrence
# wins, matching the immutable dotenv repository), with Laravel's
# literal conversions ((null), (true), ...) applied to values from
# either source; a null Argon cost then falls through to the hasher's
# own defaults, exactly as the framework's ?? chains resolve it. An
# invalid HASH_* value from any source fails here instead of after
# the deploy. The
# algorithm must be listed by password_algos(), and a real hash must
# round-trip at the effective cost. An unsupported driver value fails
# here too (the framework would only throw after the deploy), and a
# bcrypt driver round-trips at its effective rounds instead. Absurd
# cost values are rejected by range checks before any hashing, so the
# gate itself can never hang or over-allocate on a typo: the memory
# ceiling (524288 KiB) is sized for the 4 GiB production host, eight
# times the pinned default, so an absurd HASH_MEMORY is refused here
# instead of being handed to password_hash() to allocate. A leading
# UTF-8 BOM in .env is stripped before parsing, as phpdotenv does, so
# a first-line override is never silently skipped.
"${PHP_BIN}" -r '
$map = [];
$envContents = file_get_contents($argv[1]);
if ($envContents === false) {
    exit(1);
}
if (str_starts_with($envContents, "\xEF\xBB\xBF")) {
    $envContents = substr($envContents, 3);
}
foreach (explode("\n", $envContents) as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#" || ! str_contains($line, "=")) {
        continue;
    }
    [$k, $v] = explode("=", $line, 2);
    $k = trim($k);
    $v = trim($v);
    if (str_starts_with($k, "export ")) {
        $k = trim(substr($k, 7));
    }
    $quote = null;
    if ($v !== "" && ($v[0] === "\"" || $v[0] === "\047")) {
        $q = $v[0];
        $close = null;
        $vlen = strlen($v);
        for ($i = 1; $i < $vlen; $i++) {
            if ($q === "\"" && $v[$i] === "\\") {
                $i++;
                continue;
            }
            if ($v[$i] === $q) {
                $close = $i;
                break;
            }
        }
        if ($close !== null) {
            $rest = trim(substr($v, $close + 1));
            if ($rest === "" || str_starts_with($rest, "#")) {
                $quote = $q;
                $v = substr($v, 1, $close - 1);
                if ($q === "\"") {
                    $v = strtr($v, ["\\\"" => "\"", "\\\\" => "\\", "\\n" => "\n", "\\r" => "\r", "\\t" => "\t", "\\\$" => "\0"]);
                }
            }
        }
    }
    if ($quote === null && ($pos = strpos($v, " #")) !== false) {
        $v = trim(substr($v, 0, $pos));
    }
    if ($quote !== "\047") {
        $v = preg_replace_callback("/" . "\\$" . "{([a-zA-Z0-9_.]+)}/", function ($m) use (&$map) {
            foreach ([$_ENV, $_SERVER] as $bag) {
                if (array_key_exists($m[1], $bag)) {
                    return (string) $bag[$m[1]];
                }
            }
            $fromProcess = getenv($m[1]);
            if ($fromProcess !== false) {
                return $fromProcess;
            }
            return array_key_exists($m[1], $map) ? $map[$m[1]] : $m[0];
        }, $v);
        $v = str_replace("\0", "\$", $v);
    }
    if (! array_key_exists($k, $map)) {
        $map[$k] = $v;
    }
}
function env($key, $default = null) {
    global $map;
    $value = null;
    $found = false;
    foreach ([$_ENV, $_SERVER] as $bag) {
        if (array_key_exists($key, $bag)) {
            $value = $bag[$key];
            $found = true;
            break;
        }
    }
    if (! $found) {
        $fromProcess = getenv($key);
        if ($fromProcess !== false) {
            $value = $fromProcess;
            $found = true;
        }
    }
    if (! $found) {
        if (! array_key_exists($key, $map)) {
            return $default;
        }
        $value = $map[$key];
    }
    switch (strtolower((string) $value)) {
        case "true": case "(true)": return true;
        case "false": case "(false)": return false;
        case "empty": case "(empty)": return "";
        case "null": case "(null)": return null;
    }
    return $value;
}
$config = require $argv[2];
$driver = array_key_exists("driver", $config) ? $config["driver"] : "bcrypt";
if (! in_array($driver, ["bcrypt", "argon", "argon2id"], true)) {
    exit(1);
}
if ($driver === "bcrypt") {
    $rounds = (int) ($config["bcrypt"]["rounds"] ?? 12);
    if ($rounds < 4 || $rounds > 20) {
        exit(1);
    }
    $hash = password_hash("preflight-probe", PASSWORD_BCRYPT, ["cost" => $rounds]);
    exit($hash !== false && password_verify("preflight-probe", $hash) ? 0 : 1);
}
$algo = $driver === "argon" ? PASSWORD_ARGON2I : PASSWORD_ARGON2ID;
$algoName = $driver === "argon" ? "argon2i" : "argon2id";
if (! in_array($algoName, password_algos(), true)) {
    exit(1);
}
$argon = $config["argon"] ?? [];
$memory = (int) ($argon["memory"] ?? 1024);
$time = (int) ($argon["time"] ?? 2);
$threads = (int) ($argon["threads"] ?? 2);
if ($memory < 8 || $memory > 524288 || $time < 1 || $time > 16 || $threads < 1 || $threads > 32) {
    exit(1);
}
$hash = password_hash("preflight-probe", $algo, [
    "memory_cost" => $memory,
    "time_cost" => $time,
    "threads" => $threads,
]);
exit($hash !== false && password_verify("preflight-probe", $hash) ? 0 : 1);
' "${ENV_FILE}" "${APP_DIR}/config/hashing.php" \
  || { echo "PHP Argon2 support is required and must hash at the effective configured cost (see config/hashing.php and HASH_* in .env)" >&2; exit 1; }
# end of argon2id capability check

for pair in \
  'APP_ENV=production' \
  'APP_DEBUG=false' \
  'APP_TIMEZONE=Asia/Jakarta' \
  'DB_CONNECTION=mysql' \
  'CACHE_STORE=redis' \
  'QUEUE_CONNECTION=redis' \
  'SESSION_DRIVER=redis' \
  'SESSION_ENCRYPT=true' \
  'SESSION_SECURE_COOKIE=true'; do
  grep -Fqx "${pair}" "${ENV_FILE}" || { echo "Required setting missing: ${pair}" >&2; exit 1; }
done

grep -Eq '^APP_KEY=base64:.+$' "${ENV_FILE}" || { echo "APP_KEY must be generated server-side" >&2; exit 1; }
grep -Eq '^APP_URL=https://' "${ENV_FILE}" || { echo "APP_URL must use HTTPS" >&2; exit 1; }
grep -Eq '^APP_TRUSTED_HOSTS=.+$' "${ENV_FILE}" || { echo "APP_TRUSTED_HOSTS is required" >&2; exit 1; }
grep -Eq '^[[:space:]]*(export[[:space:]]+)?TRUSTED_PROXIES[[:space:]]*=.+$' "${ENV_FILE}" || { echo "TRUSTED_PROXIES is required" >&2; exit 1; }
while IFS= read -r proxies_line; do
  proxies_raw="${proxies_line#*TRUSTED_PROXIES}"
  proxies_raw="${proxies_raw#"${proxies_raw%%[![:space:]]*}"}"
  proxies_raw="${proxies_raw#=}"
  proxies_raw="${proxies_raw#"${proxies_raw%%[![:space:]]*}"}"
  proxies_quote="${proxies_raw:0:1}"
  if [[ "${proxies_quote}" == '"' || "${proxies_quote}" == "'" ]]; then
    proxies_rest="${proxies_raw:1}"
    if [[ "${proxies_rest}" != *"${proxies_quote}"* ]]; then
      echo "TRUSTED_PROXIES must be written as a single-line value in production; multiline values are not allowed" >&2
      exit 1
    fi
  fi
  if [[ "${proxies_quote}" == '"' || "${proxies_quote}" == "'" ]]; then
    proxies_rest="${proxies_raw:1}"
    proxies_value="${proxies_rest%%"${proxies_quote}"*}"
  else
    proxies_value="${proxies_raw%%[[:space:]]#*}"
    if [[ "${proxies_raw:0:1}" == '#' ]]; then proxies_value=""; fi
  fi
  proxies_value="${proxies_value#"${proxies_value%%[![:space:]]*}"}"
  proxies_value="${proxies_value%"${proxies_value##*[![:space:]]}"}"
  if [[ "${proxies_value}" == *\\* ]]; then
    echo "TRUSTED_PROXIES must be written literally in production; escape sequences are not allowed" >&2
    exit 1
  fi
  if [[ "${proxies_value}" == *'${'* ]]; then
    echo "TRUSTED_PROXIES must be written literally in production; variable references are not allowed" >&2
    exit 1
  fi
  IFS=',' read -ra proxies_entries <<<"${proxies_value}"
  proxies_named=0
  for proxies_entry in "${proxies_entries[@]}"; do
    proxies_entry="${proxies_entry#"${proxies_entry%%[![:space:]]*}"}"
    proxies_entry="${proxies_entry%"${proxies_entry##*[![:space:]]}"}"
    [[ -n "${proxies_entry}" ]] && proxies_named=1
    if [[ "${proxies_entry}" =~ /0+$ ]]; then
      echo "TRUSTED_PROXIES must not trust every address in production; catch-all CIDR ranges (any address with a /0 prefix length) are not allowed" >&2
      exit 1
    fi
    if [[ "${proxies_entry}" == '*' ]]; then
      echo "TRUSTED_PROXIES must not contain a wildcard entry in production; list the proxy addresses (e.g. 127.0.0.1,::1)" >&2
      exit 1
    fi
    if [[ -n "${proxies_entry}" ]] && ! awk -v proxy_entry="${proxies_entry}" '
      function is_ipv4(addr,    n, octets, i) {
        n = split(addr, octets, "[.]")
        if (n != 4) return 0
        for (i = 1; i <= 4; i++) {
          if (octets[i] !~ /^[0-9]+$/) return 0
          if (length(octets[i]) > 3) return 0
          if (length(octets[i]) > 1 && substr(octets[i], 1, 1) == "0") return 0
          if (octets[i] + 0 > 255) return 0
        }
        return 1
      }
      function is_ipv6(addr,    rest, dc, q, flat, n, groups, i, explicit) {
        if (index(addr, ".") > 0) return 0
        if (index(addr, ":::") > 0) return 0
        dc = 0; rest = addr
        while ((q = index(rest, "::")) > 0) { dc++; rest = substr(rest, q + 2) }
        if (dc > 1) return 0
        if (substr(addr, 1, 1) == ":" && substr(addr, 1, 2) != "::") return 0
        if (substr(addr, length(addr), 1) == ":" && substr(addr, length(addr) - 1, 2) != "::") return 0
        flat = addr
        if (dc == 1) { q = index(addr, "::"); flat = substr(addr, 1, q - 1) ":" substr(addr, q + 2) }
        n = split(flat, groups, ":")
        explicit = 0
        for (i = 1; i <= n; i++) {
          if (groups[i] == "") continue
          if (groups[i] !~ /^[0-9A-Fa-f]+$/) return 0
          if (length(groups[i]) > 4) return 0
          explicit++
        }
        if (dc == 1) return explicit <= 7
        return explicit == 8
      }
      BEGIN {
        n = split(proxy_entry, parts, "/")
        if (n > 2) exit 1
        if (n == 2) {
          if (parts[2] !~ /^[0-9]+$/) exit 1
          if (parts[2] + 0 == 0) exit 1
        }
        if (index(parts[1], ":") > 0) {
          if (!is_ipv6(parts[1])) exit 1
          if (n == 2 && parts[2] + 0 > 128) exit 1
        } else {
          if (!is_ipv4(parts[1])) exit 1
          if (n == 2 && parts[2] + 0 > 32) exit 1
        }
        exit 0
      }
    '; then
      echo "TRUSTED_PROXIES entries must be IP addresses or CIDR ranges in production; a malformed entry is not allowed" >&2
      exit 1
    fi
  done
  if [[ "${proxies_value}" == '*' ]]; then
    echo "TRUSTED_PROXIES must not be the wildcard in production; list the proxy addresses (e.g. 127.0.0.1,::1)" >&2
    exit 1
  fi
  if [[ "${proxies_named}" -eq 0 ]]; then
    echo "TRUSTED_PROXIES must name at least one proxy in production; an empty value is not allowed" >&2
    exit 1
  fi
done < <(grep -E '^[[:space:]]*(export[[:space:]]+)?TRUSTED_PROXIES[[:space:]]*=' "${ENV_FILE}" || true)

# The running application reads the frozen configuration cache, not the
# .env file, once bootstrap/cache/config.php exists. A cache built while
# TRUSTED_PROXIES still held a value the boot guard rejects (for example
# the wildcard) would make the first boot of the guarded tree throw even
# after .env is fixed, so validate the cached value with the same rules.
proxies_cache_file="${APP_DIR}/bootstrap/cache/config.php"
if [[ -f "${proxies_cache_file}" ]]; then
  if ! "${PHP_BIN}" -r '
    $config = require $argv[1];
    $value = $config["lfamilia"]["trusted_proxies"] ?? null;
    if ($value === "*") { exit(1); }
    if (!is_array($value) || $value === []) { exit(1); }
    foreach ($value as $entry) {
      if (!is_string($entry) || $entry === "*") { exit(1); }
      $parts = explode("/", $entry);
      if (count($parts) > 2) { exit(1); }
      $prefix = $parts[1] ?? null;
      if ($prefix !== null && ($prefix === "" || !ctype_digit($prefix) || (int) $prefix === 0)) { exit(1); }
      $address = $parts[0];
      if (str_contains($address, ".") && str_contains($address, ":")) { exit(1); }
      if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if ($prefix !== null && (int) $prefix > 32) { exit(1); }
      } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        if ($prefix !== null && (int) $prefix > 128) { exit(1); }
      } else { exit(1); }
    }
    exit(0);
  ' "${proxies_cache_file}"; then
    echo "The cached configuration (bootstrap/cache/config.php) holds a TRUSTED_PROXIES value the application will reject at boot; delete the cached configuration file and re-run preflight" >&2
    exit 1
  fi
fi
# end of cached-config proxy check

cd "${APP_DIR}"
COMPOSER_ALLOW_SUPERUSER=1 \
GIT_CONFIG_COUNT=1 \
GIT_CONFIG_KEY_0=safe.directory \
GIT_CONFIG_VALUE_0="${REPO_DIR}" \
"${COMPOSER_BIN}" validate --strict
"${NPM_BIN}" --version >/dev/null

mysql --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" "${BACKUP_DATABASE_NAME}" \
  --batch --skip-column-names -e 'SELECT 1' | grep -Fxq '1'

db_version="$(mysql --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" \
  --batch --skip-column-names -e 'SELECT VERSION()')"
[[ "${db_version}" != *MariaDB* ]] || { echo "MariaDB is not allowed; LFAMILIA requires MySQL 8." >&2; exit 1; }
[[ "${db_version}" =~ ^8\. ]] || { echo "MySQL 8.x is required; detected ${db_version}." >&2; exit 1; }

if [[ -n "${REDIS_PASSWORD_FILE:-}" ]]; then
  [[ -r "${REDIS_PASSWORD_FILE}" ]] || { echo "Redis password file is not readable" >&2; exit 1; }
  REDISCLI_AUTH="$(<"${REDIS_PASSWORD_FILE}")" redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
else
  redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
fi

if [[ "${BACKUP_REQUIRE_REMOTE:-true}" == "true" ]]; then
  require_cmd rclone
  : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE is required}"
  [[ -r "${RCLONE_CONFIG:?RCLONE_CONFIG is required}" ]] \
    || { echo "rclone config is not readable" >&2; exit 1; }
fi

# Detect drift between tracked operational scripts and root-owned copies run by systemd.
# Deployment intentionally does not overwrite privileged service scripts.
for privileged_script in backup.sh restore-verify.sh; do
  tracked_script="${APP_DIR}/deploy/${privileged_script}"
  installed_script="/usr/local/lib/lfamilia/${privileged_script}"
  if [[ ! -r "${installed_script}" ]]; then
    echo "WARNING: Privileged script missing or unreadable: ${installed_script}" >&2
  elif ! cmp -s "${tracked_script}" "${installed_script}"; then
    echo "WARNING: Privileged script differs from repository: ${privileged_script}. Review and synchronize it separately before relying on the corresponding systemd service." >&2
  fi
done

# Signal when the effective nginx configuration has lost the secret-file
# deny rules from deploy/nginx/cloudpanel-vhost-snippet.conf. CloudPanel
# regenerates vhosts outside this repository, so - like the privileged
# script drift check above - this warns instead of failing the deploy.
if command -v nginx >/dev/null 2>&1; then
  nginx_dump="$(nginx -T 2>/dev/null || true)"
  vhost_block="$(printf '%s\n' "${nginx_dump}" | awk '
    function scrub(s,    out, i, c, esc) {
      # The quote state lives in SQ, not a local: nginx keeps a quoted
      # string open across physical lines, so it carries from one line
      # to the next. The escape state stays per-line: in the nginx
      # stream a trailing backslash escapes the newline itself.
      out = ""; esc = 0
      for (i = 1; i <= length(s); i++) {
        c = substr(s, i, 1)
        if (SQ != "") {
          if (esc) { esc = 0; out = out ((c == "{" || c == "}") ? " " : c) }
          else if (c == "\\") { esc = 1; out = out c }
          else if (c == SQ) { SQ = ""; out = out c }
          else if (c == "{" || c == "}") out = out " "
          else out = out c
        } else {
          if (c == "#") break
          if (c == "\"" || c == "\047") { SQ = c; out = out c }
          else out = out c
        }
      }
      return out
    }
    {
      line = scrub($0)
      gsub(/[[:space:]]+/, " ", line)
      if (!inblock) {
        if (line ~ /(^|[[:space:]])server[[:space:]]*\{/) { inblock = 1; depth = 0; buf = ""; hit = 0 } else next
      }
      buf = buf line ORS
      if (line ~ /server_name/) {
        rest = line
        sub(/^.*server_name[[:space:]]+/, "", rest)
        sub(/;.*/, "", rest)
        ntok = split(rest, toks, /[[:space:]]+/)
        for (k = 1; k <= ntok; k++) { tk = toks[k]; if (length(tk) >= 2 && (substr(tk, 1, 1) == "\"" || substr(tk, 1, 1) == "\047")) tk = substr(tk, 2, length(tk) - 2); if (tk == "lfamilia.com" || tk == "www.lfamilia.com") hit = 1 }
      }
      tmp = line; opens = gsub(/\{/, "{", tmp); tmp = line; closes = gsub(/\}/, "}", tmp); depth += opens - closes
      if (depth <= 0) { if (hit) result = result buf; inblock = 0 }
    }
    END { printf "%s", result }')"
  if [[ -z "${vhost_block}" ]]; then
    echo "WARNING: Could not locate the lfamilia.com server block in the effective nginx configuration; the vhost deny rules could not be verified." >&2
  else
    deny_patterns='location ~ /\.(?!well-known).*
location ~* \.(?:env|log|sql|bak|ini)$
location ~* ^/(?:storage/logs|bootstrap/cache)/'
    missing_denies="$(printf '%s\n' "${vhost_block}" | DENY_PATTERNS="${deny_patterns}" awk '
      function spanend(p, j,    d2, e2, c2, q, st, pd2) {
        d2 = 0; e2 = 0; c2 = 0
        for (q = j; q <= length(p); q++) {
          st = substr(p, q, 1)
          if (e2) { e2 = 0; continue }
          if (st == "\\") { e2 = 1; continue }
          if (c2 == 1) { if (st == "^") c2 = 2; else if (st == "[" && substr(p, q + 1, 1) ~ /[:.=]/) { c2 = 4; pd2 = substr(p, q + 1, 1) } else c2 = 3; continue }
          if (c2 == 2) { if (st == "[" && substr(p, q + 1, 1) ~ /[:.=]/) { c2 = 4; pd2 = substr(p, q + 1, 1) } else c2 = 3; continue }
          if (c2 == 3) { if (st == "]") c2 = 0; else if (st == "[" && substr(p, q + 1, 1) ~ /[:.=]/) { c2 = 4; pd2 = substr(p, q + 1, 1) } continue }
          if (c2 == 4) { if (st == pd2) c2 = 5; continue }
          if (c2 == 5) { if (st == "]") c2 = 3; else if (st != pd2) c2 = 4; continue }
          if (st == "[") { c2 = 1; continue }
          if (st == "(") d2++
          else if (st == ")") { d2--; if (!d2) return q }
        }
        return 0
      }
      function simplify(p,    out, j, ch, nx, t3, esc, cls, cut, fl, fk, ps, ng, pd) {
        out = ""; esc = 0; cls = 0; simp_ci = 0
        for (j = 1; j <= length(p); j++) {
          ch = substr(p, j, 1)
          if (esc) {
            esc = 0
            if (ch ~ /[0-9]/) return ""
            if (ch == "d") { out = out (cls ? "0-9" : "[0-9]"); continue }
            if (ch == "D") { if (cls) return ""; out = out "[^0-9]"; continue }
            if (ch == "s") { out = out (cls ? "[:space:]" : "[[:space:]]"); continue }
            if (ch == "S") { if (cls) return ""; out = out "[^[:space:]]"; continue }
            if (ch == "w") { out = out (cls ? "A-Za-z0-9_" : "[A-Za-z0-9_]"); continue }
            if (ch == "W") { if (cls) return ""; out = out "[^A-Za-z0-9_]"; continue }
            if (ch ~ /[A-Za-z]/) return ""
            out = out "\\" ch; continue
          }
          if (ch == "\\") { esc = 1; continue }
          if (cls == 1) { out = out ch; if (ch == "^") cls = 2; else if (ch == "[" && substr(p, j + 1, 1) ~ /[:.=]/) { cls = 4; pd = substr(p, j + 1, 1) } else cls = 3; continue }
          if (cls == 2) { out = out ch; if (ch == "[" && substr(p, j + 1, 1) ~ /[:.=]/) { cls = 4; pd = substr(p, j + 1, 1) } else cls = 3; continue }
          if (cls == 3) { out = out ch; if (ch == "]") cls = 0; else if (ch == "[" && substr(p, j + 1, 1) ~ /[:.=]/) { cls = 4; pd = substr(p, j + 1, 1) } continue }
          if (cls == 4) { out = out ch; if (ch == pd) cls = 5; continue }
          if (cls == 5) { out = out ch; if (ch == "]") cls = 3; else if (ch != pd) cls = 4; continue }
          if (ch == "[") { out = out ch; cls = 1; continue }
          if (ch == "(" && substr(p, j + 1, 1) == "?") {
            nx = substr(p, j + 2, 1)
            if (nx == ":") { out = out "("; j += 2; continue }
            if (nx == ">") { out = out "("; j += 2; continue }
            if (nx == "=" || nx == "!") { cut = spanend(p, j); if (!cut) return ""; j = cut; continue }
            if (nx == "#") { cut = spanend(p, j); if (!cut) return ""; j = cut; continue }
            if (nx == "<") {
              t3 = substr(p, j + 3, 1)
              if (t3 == "=" || t3 == "!") { cut = spanend(p, j); if (!cut) return ""; j = cut; continue }
              cut = index(substr(p, j + 3), ">"); if (!cut) return ""
              out = out "("; j = j + 2 + cut; continue
            }
            if (nx == "\047") {
              cut = index(substr(p, j + 3), "\047"); if (!cut) return ""
              out = out "("; j = j + 2 + cut; continue
            }
            if (nx == "P" && substr(p, j + 3, 1) == "<") {
              cut = index(substr(p, j + 4), ">"); if (!cut) return ""
              out = out "("; j = j + 3 + cut; continue
            }
            fl = ""; fk = j + 2
            while (fk <= length(p) && substr(p, fk, 1) ~ /[A-Za-z-]/) { fl = fl substr(p, fk, 1); fk++ }
            if (fl != "" && fk <= length(p) && (substr(p, fk, 1) == ")" || substr(p, fk, 1) == ":")) {
              ps = index(fl, "i"); ng = index(fl, "-")
              if (ps && (!ng || ps < ng)) simp_ci = 1
              if (substr(p, fk, 1) == ":") out = out "("
              j = fk; continue
            }
            return ""
          }
          if (ch == "{") return ""
          out = out ch
        }
        return out
      }
      function realp1(u,    q) {
        while ((q = index(u, "/.")) > 0) {
          if (index(substr(u, q + 2), "well-known") != 1) return 1
          u = substr(u, q + 2)
        }
        return 0
      }
      function litprefix(p,    out, j, ch, nx, d, inner, q, st, bad) {
        out = ""; j = 1
        if (substr(p, 1, 1) == "^") j = 2
        while (j <= length(p)) {
          ch = substr(p, j, 1)
          if (ch == "\\") {
            nx = substr(p, j + 1, 1)
            if (nx ~ /[A-Za-z0-9]/) break
            out = out nx; j += 2; continue
          }
          if (ch == "(") {
            d = 1; inner = ""; q = j + 1; bad = 0
            while (q <= length(p) && d > 0) {
              st = substr(p, q, 1)
              if (st == "\\") {
                nx = substr(p, q + 1, 1)
                if (nx ~ /[A-Za-z0-9]/) { bad = 1; break }
                inner = inner nx; q += 2; continue
              }
              if (st == "(") { bad = 1; break }
              if (st == ")") { d--; q++; if (!d) break; continue }
              if (st ~ /[*+?|\[\]$^]/) { bad = 1; break }
              inner = inner st; q++
            }
            if (bad || d != 0) break
            out = out inner; j = q; continue
          }
          if (ch ~ /[*+?|\[\]$]/) break
          out = out ch; j++
        }
        return out
      }
      function overlaps(k, i,    p, s, t, ci, lp, c, pj, pp, pci, sp, sci) {
        p = e_pat[k]
        if (e_caret[k] || e_exact[k]) {
          for (s = 1; s <= sc[i]; s++) if (e_exact[k] ? (subj[i, s] == p) : (index(subj[i, s], p) == 1)) return 1
          pp = ""
          for (pj = 1; pj <= E; pj++) if (e_prot[pj] == i) { pp = e_pat[pj]; pci = e_ci[pj]; break }
          if (pp == "") return 0
          sp = simplify(pp); sci = simp_ci
          if (sp == "") return 1
          if (i == 1) { if (realp1(p)) return 1 }
          else if ((pci || sci) ? match(tolower(p), tolower(sp)) : match(p, sp)) return 1
          if (e_caret[k]) {
            for (c = 1; c <= cc[i]; c++) {
              t = p core[i, c]
              if (i == 1) { if (realp1(t)) return 1; continue }
              if ((pci || sci) ? match(tolower(t), tolower(sp)) : match(t, sp)) return 1
            }
          }
          return 0
        }
        p = simplify(p)
        if (p == "") return 1
        ci = (e_ci[k] || simp_ci)
        for (s = 1; s <= sc[i]; s++) {
          t = subj[i, s]
          if (ci) { if (match(tolower(t), tolower(p))) return 1 }
          else if (match(t, p)) return 1
        }
        lp = litprefix(p)
        if (lp != "") {
          pp = ""
          for (pj = 1; pj <= E; pj++) if (e_prot[pj] == i) { pp = e_pat[pj]; pci = e_ci[pj]; break }
          sp = (pp != "") ? simplify(pp) : ""; sci = simp_ci
          for (c = 1; c <= cc[i]; c++) {
            t = lp core[i, c]
            if (i == 1 && !realp1(t)) continue
            if (i == 3 && sp != "" && !((pci || sci) ? match(tolower(t), tolower(sp)) : match(t, sp))) continue
            if (ci) { if (match(tolower(t), tolower(p))) return 1 }
            else if (match(t, p)) return 1
          }
          if ((ci && match(tolower(lp), tolower(p))) || (!ci && match(lp, p))) {
            if (i == 1) { if (realp1(lp)) return 1 }
            else if (pp != "") {
              if (sp == "") return 1
              if ((pci || sci) && match(tolower(lp), tolower(sp))) return 1
              if (!(pci || sci) && match(lp, sp)) return 1
            }
          }
        }
        return 0
      }
      function close_loc() { e_eff[E] = (cur_eff && !nested_allow) ? 1 : 0; inloc = 0 }
      function finalize(    i, j, j0, credited) {
        if (inloc) close_loc()
        # A block whose server level unconditionally redirects (the www
        # host) serves no files, so it needs no deny rules and must not
        # fail the blocks that do serve the site.
        if (block_redirect) { E = 0; return }
        for (i = 1; i <= n; i++) {
          credited = 0; j0 = 0
          for (j = 1; j <= E; j++) if (e_prot[j] == i) { j0 = j; break }
          if (j0 && e_eff[j0]) {
            credited = 1
            for (j = 1; j <= E; j++) {
              if (j == j0 || e_eff[j] || e_prot[j]) continue
              if ((e_caret[j] || e_exact[j]) && overlaps(j, i)) { credited = 0; break }
              if (e_regex[j] && j < j0 && overlaps(j, i)) { credited = 0; break }
            }
          }
          if (!credited) missing[pats[i]] = 1
        }
        E = 0
      }
      BEGIN {
        n = split(ENVIRON["DENY_PATTERNS"], pats, "\n")
        subj[1, 1] = "/.env"; subj[1, 2] = "/.git/config"; subj[1, 3] = "/.env.local"; subj[1, 4] = "/.env.production"; subj[1, 5] = "/.ENV"; subj[1, 6] = "/.ENV.LOCAL"; subj[1, 7] = "/.ENV.PRODUCTION"; subj[1, 8] = "/.hidden"; subj[1, 9] = "/x/.env"; subj[1, 10] = "/x/.env.local"; subj[1, 11] = "/x/.env.production"; subj[1, 12] = "/x/.ENV"; subj[1, 13] = "/x/.git/config"; subj[1, 14] = "/uploads/.env"; subj[1, 15] = "/uploads/.env.local"; subj[1, 16] = "/uploads/.env.production"; subj[1, 17] = "/uploads/.ENV"; sc[1] = 17
        subj[2, 1] = "/files/x.env"; subj[2, 2] = "/files/x.log"; subj[2, 3] = "/files/x.sql"; subj[2, 4] = "/files/x.bak"; subj[2, 5] = "/files/x.ini"; subj[2, 6] = "/files/x.ENV"; subj[2, 7] = "/files/x.LOG"; subj[2, 8] = "/files/x.SQL"; subj[2, 9] = "/files/x.BAK"; subj[2, 10] = "/files/x.INI"; subj[2, 11] = "/files/X.ENV"; subj[2, 12] = "/files/BACKUP.SQL"; subj[2, 13] = "/x.env"; subj[2, 14] = "/x.log"; subj[2, 15] = "/x.sql"; subj[2, 16] = "/x.bak"; subj[2, 17] = "/x.ini"; subj[2, 18] = "/x.ENV"; subj[2, 19] = "/x.LOG"; subj[2, 20] = "/x.SQL"; subj[2, 21] = "/x.BAK"; subj[2, 22] = "/x.INI"; subj[2, 23] = "/uploads/x.env"; subj[2, 24] = "/uploads/x.log"; subj[2, 25] = "/uploads/x.sql"; subj[2, 26] = "/uploads/x.bak"; subj[2, 27] = "/uploads/x.ini"; subj[2, 28] = "/uploads/x.ENV"; subj[2, 29] = "/uploads/x.LOG"; subj[2, 30] = "/uploads/x.SQL"; subj[2, 31] = "/uploads/x.BAK"; subj[2, 32] = "/uploads/x.INI"; sc[2] = 32
        subj[3, 1] = "/storage/logs/laravel.log"; subj[3, 2] = "/storage/logs/x.txt"; subj[3, 3] = "/storage/logs/x.php"; subj[3, 4] = "/Storage/Logs/laravel.log"; subj[3, 5] = "/Storage/Logs/x.txt"; subj[3, 6] = "/Storage/Logs/x.php"; subj[3, 7] = "/STORAGE/LOGS/laravel.log"; subj[3, 8] = "/STORAGE/LOGS/x.txt"; subj[3, 9] = "/STORAGE/LOGS/x.php"; subj[3, 10] = "/bootstrap/cache/config.php"; subj[3, 11] = "/bootstrap/cache/x.txt"; subj[3, 12] = "/bootstrap/cache/x.log"; subj[3, 13] = "/bootstrap/cache/views/abc.php"; subj[3, 14] = "/Bootstrap/Cache/config.php"; subj[3, 15] = "/Bootstrap/Cache/x.txt"; subj[3, 16] = "/Bootstrap/Cache/x.log"; subj[3, 17] = "/BOOTSTRAP/CACHE/config.php"; subj[3, 18] = "/BOOTSTRAP/CACHE/x.txt"; subj[3, 19] = "/BOOTSTRAP/CACHE/x.log"; sc[3] = 19
        core[1, 1] = ".env"; core[1, 2] = ".env.local"; core[1, 3] = ".env.production"; core[1, 4] = ".ENV"; core[1, 5] = ".ENV.LOCAL"; core[1, 6] = ".ENV.PRODUCTION"; core[1, 7] = ".git/config"; core[1, 8] = ".hidden"; cc[1] = 8
        core[2, 1] = "x.env"; core[2, 2] = "x.log"; core[2, 3] = "x.sql"; core[2, 4] = "x.bak"; core[2, 5] = "x.ini"; core[2, 6] = "x.ENV"; core[2, 7] = "x.LOG"; core[2, 8] = "x.SQL"; core[2, 9] = "x.BAK"; core[2, 10] = "x.INI"; cc[2] = 10
        core[3, 1] = "laravel.log"; core[3, 2] = "config.php"; core[3, 3] = "x.txt"; core[3, 4] = "x.php"; core[3, 5] = "x.log"; cc[3] = 5
      }
      function scrub(s,    out, i, c, esc) {
        # The quote state lives in SQ, not a local: nginx keeps a quoted
        # string open across physical lines, so it carries from one line
        # to the next. The escape state stays per-line: in the nginx
        # stream a trailing backslash escapes the newline itself.
        out = ""; esc = 0
        for (i = 1; i <= length(s); i++) {
          c = substr(s, i, 1)
          if (SQ != "") {
            if (esc) { esc = 0; out = out ((c == "{" || c == "}") ? " " : c) }
            else if (c == "\\") { esc = 1; out = out c }
            else if (c == SQ) { SQ = ""; out = out c }
            else if (c == "{" || c == "}") out = out " "
            else out = out c
          } else {
            if (c == "#") break
            if (c == "\"" || c == "\047") { SQ = c; out = out c }
            else out = out c
          }
        }
        return out
      }
      function maskq(s,    out, i, c, esc) {
        # The masking quote state (MQ) carries across lines for the same
        # reason as the scrub state; the escape state stays per-line, as
        # in scrub.
        out = ""; esc = 0
        for (i = 1; i <= length(s); i++) {
          c = substr(s, i, 1)
          if (MQ != "") {
            out = out "x"
            if (esc) esc = 0
            else if (c == "\\") esc = 1
            else if (c == MQ) MQ = ""
          } else {
            if (c == "\"" || c == "\047") { MQ = c; out = out "x" }
            else out = out c
          }
        }
        return out
      }
      {
        line = scrub($0)
        if (pending != "") line = pending " " line
        gsub(/[[:space:]]+/, " ", line)
        gsub(/ ;/, ";", line)
        if (line !~ /[;{}] *$/) { pending = line; next }
        pending = ""
        mline = maskq(line)
        if (!inloc && line ~ /(^| )server *\{/) { if (started) finalize(); started = 1; sdepth = 1; block_redirect = 0; next }
        if (!inloc) {
          nf = split(line, f, " ")
          li = 0
          for (q = 1; q <= nf; q++) if (f[q] == "location") { li = q; break }
          if (!li) {
            # A return 3xx stated directly at server level (depth 1, not
            # nested in an if or another block on the same line) makes the
            # whole block a redirect; conditional returns inside an if do
            # not, because the block still serves matching requests.
            if (sdepth == 1 && match(line, /(^| )return +3[0-9][0-9] /)) { pre = substr(line, 1, RSTART); if (pre !~ /[{}]/) block_redirect = 1 }
            tmp = line; o = gsub(/\{/, "{", tmp); tmp = line; c = gsub(/\}/, "}", tmp); sdepth += o - c
            next
          }
          mod = f[li + 1]; cand = f[li + 2]
          if (mod == "~" || mod == "~*" || mod == "=" || mod == "^~") { pat_text = cand } else { pat_text = mod; mod = "" }
          if (length(pat_text) >= 2 && (substr(pat_text, 1, 1) == "\"" || substr(pat_text, 1, 1) == "\047") && substr(pat_text, length(pat_text), 1) == substr(pat_text, 1, 1)) pat_text = substr(pat_text, 2, length(pat_text) - 2)
          E++
          e_prot[E] = 0
          for (i = 1; i <= n; i++) if (index(line, pats[i])) { e_prot[E] = i; break }
          e_regex[E] = (mod == "~" || mod == "~*") ? 1 : 0
          e_ci[E] = (mod == "~*") ? 1 : 0
          e_caret[E] = (mod == "^~") ? 1 : 0
          e_exact[E] = (mod == "=") ? 1 : 0
          e_pat[E] = pat_text
          e_eff[E] = 0
          inloc = 1; ldepth = 0; cur_eff = 0; scope_deny_seen = 0; allow_before = 0; nested_allow = 0
        }
        pd = 0; scope_deny_here = 0
        if (match(mline, /(^|[;{]) *deny all;/)) {
          pd = RSTART + RLENGTH - 9
          pre = substr(mline, 1, pd - 1)
          tmp = pre; ob = gsub(/\{/, "{", tmp); tmp = pre; cb = gsub(/\}/, "}", tmp)
          if (ldepth + ob - cb <= 1) scope_deny_here = 1
        }
        allow_here = 0; pa = 0
        if (match(mline, /(^|[;{]) *allow [^;]*;/)) {
          mtxt = substr(mline, RSTART, RLENGTH)
          pa = RSTART + index(mtxt, "allow") - 1
          allow_here = 1
          pre = substr(mline, 1, pa - 1)
          tmp = pre; ob = gsub(/\{/, "{", tmp); tmp = pre; cb = gsub(/\}/, "}", tmp)
          if (ldepth + ob - cb >= 2) nested_allow = 1
        }
        eff_allow = (allow_before || (allow_here && (!scope_deny_here || pa < pd))) ? 1 : 0
        if (scope_deny_here && !scope_deny_seen) { scope_deny_seen = 1; cur_eff = eff_allow ? 0 : 1 }
        if (allow_here && !scope_deny_seen) allow_before = 1
        tmp = line; o = gsub(/\{/, "{", tmp); tmp = line; c = gsub(/\}/, "}", tmp); ldepth += o - c; sdepth += o - c
        if (ldepth <= 0) close_loc()
      }
      END { if (started) finalize(); for (m in missing) print m }
    ')"
    while IFS= read -r deny_location; do
      [[ -n "${deny_location}" ]] || continue
      echo "WARNING: nginx deny rule missing or inactive in the lfamilia.com vhost: ${deny_location}. Reapply deploy/nginx/cloudpanel-vhost-snippet.conf to the vhost, then run nginx -t before reload." >&2
    done <<<"${missing_denies}"
  fi
else
  echo "WARNING: nginx binary not found; the vhost deny rules could not be verified." >&2
fi

echo "M12 production preflight passed."
