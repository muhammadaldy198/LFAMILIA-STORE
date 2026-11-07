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
grep -Eq '^TRUSTED_PROXIES=.+$' "${ENV_FILE}" || { echo "TRUSTED_PROXIES is required" >&2; exit 1; }
while IFS= read -r proxies_line; do
  proxies_raw="${proxies_line#TRUSTED_PROXIES=}"
  proxies_raw="${proxies_raw#"${proxies_raw%%[![:space:]]*}"}"
  proxies_quote="${proxies_raw:0:1}"
  if [[ "${proxies_quote}" == '"' || "${proxies_quote}" == "'" ]]; then
    proxies_rest="${proxies_raw:1}"
    if [[ "${proxies_rest}" != *"${proxies_quote}"* ]]; then
      echo "TRUSTED_PROXIES must be written as a single-line value in production; multiline values are not allowed" >&2
      exit 1
    fi
  fi
  proxies_value="$(printf '%s' "${proxies_line}" | sed -E 's/^TRUSTED_PROXIES=[[:space:]]*//; s/#.*//' | tr -d '[:space:]' | tr -d "\"'")"
  if [[ "${proxies_value}" == *'${'* ]]; then
    echo "TRUSTED_PROXIES must be written literally in production; variable references are not allowed" >&2
    exit 1
  fi
  IFS=',' read -ra proxies_entries <<<"${proxies_value}"
  proxies_named=0
  for proxies_entry in "${proxies_entries[@]}"; do
    [[ -n "${proxies_entry}" ]] && proxies_named=1
    if [[ "${proxies_entry}" =~ /0+$ ]]; then
      echo "TRUSTED_PROXIES must not trust every address in production; catch-all CIDR ranges (any address with a /0 prefix length) are not allowed" >&2
      exit 1
    fi
    if [[ "${proxies_entry}" == '*' ]]; then
      echo "TRUSTED_PROXIES must not contain a wildcard entry in production; list the proxy addresses (e.g. 127.0.0.1,::1)" >&2
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
done < <(grep -E '^TRUSTED_PROXIES=' "${ENV_FILE}" || true)

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
    {
      line = $0
      sub(/#.*/, "", line)
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
        for (k = 1; k <= ntok; k++) if (toks[k] == "lfamilia.com" || toks[k] == "www.lfamilia.com") hit = 1
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
      function spanend(p, j,    d2, e2, c2, q, st) {
        d2 = 0; e2 = 0; c2 = 0
        for (q = j; q <= length(p); q++) {
          st = substr(p, q, 1)
          if (e2) { e2 = 0; continue }
          if (st == "\\") { e2 = 1; continue }
          if (c2 == 1) { if (st == "^") c2 = 2; else c2 = 3; continue }
          if (c2 == 2) { c2 = 3; continue }
          if (c2 == 3) { if (st == "]") c2 = 0; continue }
          if (st == "[") { c2 = 1; continue }
          if (st == "(") d2++
          else if (st == ")") { d2--; if (!d2) return q }
        }
        return 0
      }
      function simplify(p,    out, j, ch, nx, t3, esc, cls, cut, fl, fk, ps, ng) {
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
          if (cls == 1) { out = out ch; if (ch == "^") cls = 2; else cls = 3; continue }
          if (cls == 2) { out = out ch; cls = 3; continue }
          if (cls == 3) { out = out ch; if (ch == "]") cls = 0; continue }
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
          out = out ch
        }
        return out
      }
      function overlaps(k, i,    p, s, t, ci) {
        p = e_pat[k]
        if (e_caret[k]) {
          for (s = 1; s <= sc[i]; s++) if (index(subj[i, s], p) == 1) return 1
          return 0
        }
        if (e_exact[k]) {
          for (s = 1; s <= sc[i]; s++) if (subj[i, s] == p) return 1
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
        return 0
      }
      function close_loc() { e_eff[E] = (cur_eff && !nested_allow) ? 1 : 0; inloc = 0 }
      function finalize(    i, j, j0, credited) {
        if (inloc) close_loc()
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
        subj[1, 1] = "/.env"; subj[1, 2] = "/.git/config"; sc[1] = 2
        subj[2, 1] = "/files/x.env"; subj[2, 2] = "/files/x.log"; subj[2, 3] = "/files/x.sql"; subj[2, 4] = "/files/x.bak"; subj[2, 5] = "/files/x.ini"; sc[2] = 5
        subj[3, 1] = "/storage/logs/laravel.log"; subj[3, 2] = "/bootstrap/cache/config.php"; sc[3] = 2
      }
      {
        line = $0
        sub(/#.*/, "", line)
        gsub(/[[:space:]]+/, " ", line)
        gsub(/ ;/, ";", line)
        if (!inloc && line ~ /(^| )server *\{/) { if (started) finalize(); started = 1; next }
        if (!inloc) {
          nf = split(line, f, " ")
          li = 0
          for (q = 1; q <= nf; q++) if (f[q] == "location") { li = q; break }
          if (!li) next
          mod = f[li + 1]; cand = f[li + 2]
          if (mod == "~" || mod == "~*" || mod == "=" || mod == "^~") { pat_text = cand } else { pat_text = mod; mod = "" }
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
        if (match(line, /(^|[;{]) *deny all;/)) {
          pd = RSTART + RLENGTH - 9
          pre = substr(line, 1, pd - 1)
          tmp = pre; ob = gsub(/\{/, "{", tmp); tmp = pre; cb = gsub(/\}/, "}", tmp)
          if (ldepth + ob - cb <= 1) scope_deny_here = 1
        }
        allow_here = 0; pa = 0
        if (match(line, /(^|[;{]) *allow [^;]*;/)) {
          mtxt = substr(line, RSTART, RLENGTH)
          pa = RSTART + index(mtxt, "allow") - 1
          allow_here = 1
          pre = substr(line, 1, pa - 1)
          tmp = pre; ob = gsub(/\{/, "{", tmp); tmp = pre; cb = gsub(/\}/, "}", tmp)
          if (ldepth + ob - cb >= 2) nested_allow = 1
        }
        eff_allow = (allow_before || (allow_here && (!scope_deny_here || pa < pd))) ? 1 : 0
        if (scope_deny_here && !scope_deny_seen) { scope_deny_seen = 1; cur_eff = eff_allow ? 0 : 1 }
        if (allow_here && !scope_deny_seen) allow_before = 1
        tmp = line; o = gsub(/\{/, "{", tmp); tmp = line; c = gsub(/\}/, "}", tmp); ldepth += o - c
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
