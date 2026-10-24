# LFAMILIA VPS Deploy Runbook (owner-ordered)

Execution guide for the deploy gate checklist in `release-checklist.md`.
Every step below IS the evidence that gate item; run it in order, read
actual output, report it. Never skip a gate based on the previous run.

## Reach the VPS

**Direct SSH from this host works and is the primary path.** The key is
`/home/hatch/.ssh/id_ed25519`; it must be passed explicitly because the
default identity under `HOME=/root` is not authorized on the server.

```sh
SSH="ssh -i /home/hatch/.ssh/id_ed25519 -o BatchMode=yes -o ConnectTimeout=15 root@202.155.17.191"
$SSH 'hostname; date -u; whoami'   # expect server.lfamiliastore.my.id / root
```

- Log in as `root`, then drop to the app user for git/artisan:
  `sudo -u lfamilia <cmd>`. The `lfamilia` account is not an SSH login
  (publickey denied) — do not try `lfamilia@` over SSH.
- Passing no `-i` gives `Permission denied (publickey,password)`, which
  looks like SSH is closed. It is not: retry with the explicit key
  before concluding anything about network or firewall.
- Run `git` as `sudo -u lfamilia git -c safe.directory='*' ...`; root
  git hits dubious-ownership errors.

### Relay fallback (Termux)

Use the relay only when direct SSH is unavailable. Helper path is under
`/home/hatch/workspace`, not `~/workspace` (HOME here is `/root`, so `~`
resolves wrong).

```sh
python3 /home/hatch/workspace/custom-connectors/custom.termux-relay/bin/relay-send.py \
  "ssh -o BatchMode=yes -o ConnectTimeout=15 root@202.155.17.191 '<vps-command>'"
```

- Wrap the remote command in `ssh ... '<cmd>'`; an unwrapped `systemctl`
  or `su` runs inside Termux and fails. Confirm the returned hostname
  is `server.lfamiliastore.my.id` before trusting any output as VPS
  state.
- A relay `TIMEOUT` with the worker answering `200` on `POST /cmd` and
  `204` forever on `GET /result` means the Termux-side poller is not
  running — the request was queued, nothing will ever answer it. Switch
  to direct SSH; do not resend the same command and do not report the
  command as executed.
- Hostname/date/`git status` first (read-only), then classify every
  later command before sending. Deploy/migrate/restart are state
  changes and need the owner's explicit deploy order.
- One command per relay call with short output. Never re-send a
  command after a timeout — confirm the prior request first.

## Order — five phases

### 0. Probe (read-only)
```sh
cd /home/lfamilia/htdocs/lfamiliastore.my.id/repo
hostname && date -u
cd rebuild && git -c safe.directory='*' status -sb && git log --oneline -3
ls deploy/   # backup.sh restore-verify.sh preflight.sh deploy.sh healthcheck.sh
```
Note the Laravel app is in the `rebuild/` subdir; repo root is the git
root. `deploy/` is `rebuild/deploy/`. Confirm `REPO_REF` in
`/etc/lfamilia/ops.env` (usually `main`) so the pull lands where expected.

### 1. Encrypted backup (gate item 3)
`deploy.sh` FAILS CLOSED if no `lfamilia-*.tar.gz.enc` newer than 60
minutes exists in `BACKUP_DIR`, even when the systemd backup timer has
been producing them. Fastest correct path is the installed unit — it
runs the same privileged script as the timer:
```sh
systemctl start lfamilia-backup.service
systemctl show lfamilia-backup.service -p Result -p ExecMainStatus   # expect success / 0
ls -lht /var/backups/lfamilia/production/ | head -4                # expect a fresh .enc + .sha256
```
`systemctl show ... -p Result` is the evidence; the file listing alone
proves only that something was written.

### 2. Verify the backup restores (gate item 3)
Prove decryption + integrity before the deploy touches the DB:
```sh
bash deploy/restore-verify.sh
```
Run it as **root**, not `sudo -u lfamilia`: the app user cannot read the
MySQL admin defaults file and the script aborts with
`MySQL admin defaults file is not readable`. Require the `...: OK` line
and `Restore verification passed`; a bare exit code is not proof.
It decrypts to a temp DB, restores, and checks users/orders/
product_packages/paid_total against metadata counts, then drops the
temp DB. Output `...: OK` + `Restore verification passed` = clean.

### 3. Deploy (gate items 4,5)
```sh
bash deploy/deploy.sh
```
Run with a generous timeout (>= 600s); it does preflight, `artisan down`
(retry=60/refresh=15), composer install, npm ci, vite build,
route/config clear, `migrate --force`, config/view cache, queue restart,
restart of php-fpm/queue/scheduler, then `artisan up` and healthcheck.
Traps keep the app in maintenance mode and print the previous SHA on
failure — a failed deploy leaves the app down; that is intentional.
The final line `M12 deployment complete: <prev> -> <target>` is the
deploy success marker. `public/storage` link "already exists" is benign.

### 4. Independent verification (gate item 6)
Do not trust healthcheck alone — read the live state:
```sh
git -c safe.directory='*' log --oneline -1      # should be the merge SHA
for s in nginx php8.4-fpm mysql redis-server lfamilia-queue lfamilia-scheduler; do \
  printf "%s: " "$s"; systemctl is-active "$s"; done
ls rebuild/storage/framework/maintenance.php && echo DOWN || echo LIVE
curl -sf --max-time 10 https://lfamiliastore.my.id/health/ready | head -c 200
```
A healthy response returns `{"status":"healthy"}`; a 404/empty is a
red flag even if the edge healthcheck passed — check the origin route
too. Report every gate's actual output, not a paraphrase.

## Pitfalls

- `~/workspace` is wrong: the relay helper is under `/home/hatch/workspace`.
  Using `~` with HOME=/root points at a nonexistent path.
- SSH without `-i /home/hatch/.ssh/id_ed25519` fails with
  `Permission denied (publickey,password)` and reads as "SSH is blocked".
  Always retry with the explicit key before blaming the network.
- A relay queue that accepts `POST /cmd` but never returns a result means
  the Termux poller is down. Do not treat the `200` as delivery.
- `deploy.sh` won't run without a backup `< 60` min old — start
  `lfamilia-backup.service` first and confirm `Result=success`, do not
  assume a timer-fired backup.
- `deploy.sh` only checks backup FILE freshness; it does not run
  `restore-verify.sh`. Restore verification stays a separate gate you
  must run yourself, and say so plainly when you did not.
- A `git pull`/checkout is NOT a deployment. `main` on the server must
  move to the merge SHA and healthcheck must pass for a deploy to be real.
- Never run `git pull` in the deploy step unguarded; use `deploy.sh`'s
  own fetch/checkout/ff-only logic so the merge order is enforced.
- After deploy, verify the migration ran by reading the deploy output
  for the migration line, not just exit code.
- `storage:link` printing "The [public/storage] link already exists" is
  benign on redeploys; the script guards it with `|| true`.
- Prove user-visible fixes are actually live, not merely deployed: fetch
  the built CSS/asset and grep for the new rule, and resolve a real
  translation key through the app kernel (`__('validation.required')`)
  via a throwaway bootstrap script in the app dir. A `200` on the page
  proves routing, not that the fix shipped.
