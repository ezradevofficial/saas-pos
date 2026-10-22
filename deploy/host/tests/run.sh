#!/usr/bin/env bash
# Tests for deploy/host (ADR 025, NFR-01, NFR-07, TEN-01). No test framework:
# syntax (bash -n), shellcheck when installed (required in CI), then every
# script in dry-run or against stubs, in a scratch directory. Never touches
# the machine it runs on. Runs on bash 3.2 (macOS) and 5.x (CI).
#
#   bash deploy/host/tests/run.sh
#   REQUIRE_SHELLCHECK=1 bash deploy/host/tests/run.sh    (CI)
set -uo pipefail

HOST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO="$(cd "$HOST_DIR/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

passed=0
failed=0
ok() {
  passed=$((passed + 1))
  printf '  ok    %s\n' "$1"
}
bad() {
  failed=$((failed + 1))
  printf '  FAIL  %s\n' "$1"
  [ -z "${2:-}" ] || printf '%s\n' "$2" | sed 's/^/        /' | head -40
}
# check NAME COMMAND...: passes when the command succeeds.
check() {
  local name="$1"
  shift
  if "$@" >/dev/null 2>&1; then ok "$name"; else bad "$name"; fi
}
has() { grep -Eq -- "$2" "$1"; }
lacks() { ! grep -Eq -- "$2" "$1"; }

SECRETS="redis-secret-1234567890abcdef db-admin-secret-0123456789 app-secret-0123456789abcd owner-secret-0123456789abcd platform-secret-0123456789ab backup-secret-key-0123456789abcdef"
base_env() {
  export DEPLOY_USER=deploy DEPLOY_PATH=/srv/platform DB_NAME=app HOST_RAM_MB=2048
  export APP_DOMAIN=app.example.test API_DOMAIN=api.example.test CONSOLE_DOMAIN=console.example.test LETSENCRYPT_EMAIL=ops@example.test
  export HEALTH_ALERT_EMAIL=owner@example.test
  export REDIS_PASSWORD=redis-secret-1234567890abcdef DB_ADMIN_PASSWORD=db-admin-secret-0123456789
  export APP_PASSWORD=app-secret-0123456789abcd APP_OWNER_PASSWORD=owner-secret-0123456789abcd APP_PLATFORM_PASSWORD=platform-secret-0123456789ab
  unset BACKUPS_ENABLED OBJECT_STORAGE_CLUSTER BACKUP_BUCKET BACKUP_ACCESS_KEY_ID BACKUP_SECRET_ACCESS_KEY WALG_LIBSODIUM_KEY PG_PROFILE PG_ALLOW_SUBNET PG_LISTEN_ADDRESS
}
backup_env() {
  export BACKUPS_ENABLED=true OBJECT_STORAGE_CLUSTER=de-fra-1 BACKUP_BUCKET=app-backups-test
  export BACKUP_ACCESS_KEY_ID=TESTACCESSKEYID BACKUP_SECRET_ACCESS_KEY=backup-secret-key-0123456789abcdef
  export WALG_LIBSODIUM_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
}
no_secrets_in() {
  local s
  for s in $SECRETS; do
    if grep -qF -- "$s" "$1"; then return 1; fi
  done
}
provision() { bash "$HOST_DIR/provision-single-host.sh" --dry-run "$@"; }

echo "Syntax"
for f in "$HOST_DIR"/*.sh "$HOST_DIR"/tests/*.sh "$REPO"/api/database/scripts/provision-db.sh; do
  check "bash -n $(basename "$f")" bash -n "$f"
done

echo "shellcheck"
SHELLCHECK="${SHELLCHECK:-$(command -v shellcheck || true)}"
if [ -n "$SHELLCHECK" ]; then
  if out="$("$SHELLCHECK" -x -P SCRIPTDIR "$HOST_DIR"/*.sh "$HOST_DIR"/tests/*.sh 2>&1)"; then ok "shellcheck clean"; else bad "shellcheck clean" "$out"; fi
elif [ "${REQUIRE_SHELLCHECK:-0}" = 1 ]; then
  bad "shellcheck is required here but not installed"
else
  echo "  skip  shellcheck is not installed: bash -n only"
fi

echo "Provisioning, backups off (the staging and pilot setup)"
base_env
R="$WORK/root-off"
mkdir -p "$R"
out="$WORK/provision-off.out"
if HOST_ROOT="$R" provision >"$out" 2>&1; then ok "every step runs in a sandboxed dry run"; else bad "every step runs in a sandboxed dry run" "$(tail -20 "$out")"; fi
conf="$R/etc/postgresql/16/main/conf.d/app.conf"
for want in "shared_buffers = 256MB" "effective_cache_size = 768MB" "work_mem = 4MB" "maintenance_work_mem = 64MB" \
  "max_connections = 40" "max_locks_per_transaction = 256" "max_prepared_transactions = 0" "listen_addresses = 'localhost'" \
  "wal_level = replica" "archive_mode = off" "archive_command = ''" "ssl = on" "idle_in_transaction_session_timeout = '5min'" \
  "log_min_duration_statement = 1000"; do
  check "postgresql: $want" has "$conf" "^$want\$"
done
hba="$R/etc/postgresql/16/main/pg_hba.conf"
check "pg_hba: TLS and scram-sha-256 on localhost" has "$hba" '^hostssl +all +all +127\.0\.0\.1/32 +scram-sha-256$'
check "pg_hba: the superuser only through the socket (peer)" has "$hba" '^local +all +postgres +peer$'
check "pg_hba: the superuser refused over TCP" has "$hba" '^host +all +postgres +0\.0\.0\.0/0 +reject$'
check "pg_hba: the superuser refused over IPv6 too" has "$hba" '^host +all +postgres +::/0 +reject$'
check "pg_hba: the superuser refusal comes before the TLS lines" test "$(grep -nE 'postgres +0\.0\.0\.0/0 +reject' "$hba" | cut -d: -f1)" -lt "$(grep -n '^hostssl' "$hba" | head -1 | cut -d: -f1)"
check "pg_hba: every other address refused" has "$hba" '^host +all +all +0\.0\.0\.0/0 +reject$'
check "pg_hba: no trust, md5 or plain TCP allow line" lacks "$hba" 'trust|md5|^host +all +all +[0-9:./]+ +scram'
check "pg_hba: no subnet while there is no VPC" lacks "$hba" '^hostssl +all +all +(10|172|192)\.'
check "ufw: 22, 80 and 443 allowed" has "$out" 'ufw allow 22/tcp'
check "ufw: 80" has "$out" 'ufw allow 80/tcp'
check "ufw: 443" has "$out" 'ufw allow 443/tcp'
check "ufw: 5432 not opened" lacks "$out" 'ufw allow .*5432'
check "ufw: enabled" has "$out" 'ufw --force enable'
check "swap: 2 GB file" has "$out" 'fallocate -l 2048M /swapfile'
check "swap: swappiness 10" has "$R/etc/sysctl.d/90-app.conf" '^vm.swappiness = 10$'
check "fail2ban: sshd jail" has "$R/etc/fail2ban/jail.d/sshd.local" '^\[sshd\]$'
check "unattended-upgrades: on, no automatic reboot" has "$R/etc/apt/apt.conf.d/52app-unattended" 'Automatic-Reboot "false"'
check "PGDG key fingerprint checked" has "$HOST_DIR/lib.sh" 'PGDG_KEY_FINGERPRINT="B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8"'
check "PostgreSQL 16 from PGDG" has "$R/etc/apt/sources.list.d/pgdg.sources" 'noble-pgdg'
check "redis: localhost only" has "$R/etc/redis/app.conf" '^bind 127\.0\.0\.1 -::1$'
check "redis: password" has "$R/etc/redis/app.conf" '^requirepass '
check "redis: never evicts queued jobs" has "$R/etc/redis/app.conf" '^maxmemory-policy noeviction$'
check "php: expose_php off" has "$R/etc/php/8.3/fpm/conf.d/99-app.ini" '^expose_php = Off$'
check "php: display_errors off (CLI too)" has "$R/etc/php/8.3/cli/conf.d/99-app.ini" '^display_errors = Off$'
check "php-fpm: 6 workers on 2 GB, as the deploy user" has "$R/etc/php/8.3/fpm/pool.d/app.conf" '^pm.max_children = 6$'
check "php-fpm: pool user" has "$R/etc/php/8.3/fpm/pool.d/app.conf" '^user = deploy$'
check "nginx: port 80 serves the ACME challenge" has "$R/etc/nginx/sites-available/app-http" 'acme-challenge'
check "nginx: no HTTPS site before the certificate" test ! -e "$R/etc/nginx/sites-available/app"
check "certbot asked for every name" has "$out" 'certbot certonly --webroot .*-d app.example.test -d console.example.test -d api.example.test'
check "systemd: Horizon as the deploy user" has "$R/etc/systemd/system/app-horizon.service" '^User=deploy$'
check "systemd: Horizon waits for a deploy" has "$R/etc/systemd/system/app-horizon.service" '^ConditionPathExists=/srv/platform/api/artisan$'
check "systemd: the scheduler every minute" has "$R/etc/systemd/system/app-scheduler.timer" '^OnCalendar=\*-\*-\* \*:\*:00$'
check "systemd: schedule:run" has "$R/etc/systemd/system/app-scheduler.service" 'artisan schedule:run$'
check "systemd: the host watch every 5 minutes" has "$R/etc/systemd/system/app-host-watch.timer" '^OnCalendar=\*:0/5$'
check "backups off: no WAL-G download" lacks "$out" 'wal-g'
check "backups off: no backup timer" test ! -e "$R/etc/systemd/system/app-db-backup.timer"
check "backups off: said so" has "$out" 'Backups: off'
check "host.env: backups off" has "$R/etc/app-host/host.env" '^BACKUPS_ENABLED=false$'
check "db-roles: provision-db.sh is called" has "$out" 'provision-db.sh'
check "db-roles: the cluster admin has BYPASSRLS" has "$HOST_DIR/provision-single-host.sh" 'NOSUPERUSER CREATEDB CREATEROLE BYPASSRLS PASSWORD'
check "no secret in the output" no_secrets_in "$out"

echo "Provisioning again changes nothing"
out2="$WORK/provision-off-2.out"
HOST_ROOT="$R" provision >"$out2" 2>&1
check "second run writes no file" lacks "$out2" '^    wrote '
check "second run restarts nothing it configured" lacks "$out2" 'systemctl (restart|reload) (postgresql|redis-server|php8.3-fpm|nginx|fail2ban)'

echo "HTTPS once the certificate exists"
mkdir -p "$R/etc/letsencrypt/live/platform"
: >"$R/etc/letsencrypt/live/platform/fullchain.pem"
mkdir -p "$R/etc/nginx"
printf 'http {\n\tssl_protocols TLSv1 TLSv1.1 TLSv1.2 TLSv1.3;\n\tssl_prefer_server_ciphers on;\n}\n' >"$R/etc/nginx/nginx.conf"
out3="$WORK/provision-nginx.out"
if HOST_ROOT="$R" provision --steps nginx >"$out3" 2>&1; then ok "nginx step"; else bad "nginx step" "$(tail -10 "$out3")"; fi
site="$R/etc/nginx/sites-available/app"
headers="$R/etc/nginx/snippets/app-web-headers.conf"
check "https: the web app and console names" has "$site" 'server_name app.example.test console.example.test;'
check "https: the API" has "$site" 'server_name api.example.test;'
check "https: /metrics denied publicly" has "$site" 'location ~ \^/\(index\\\.php/\)\?metrics/\?\$ \{ deny all; \}'
check "https: TLS ask denied publicly" has "$site" 'location = /api/v1/tls/ask \{ deny all; \}'
check "https: an internal listener on 127.0.0.1:8080 only" has "$site" 'listen 127\.0\.0\.1:8080;'
check "https: headers in every web location" test "$(grep -c 'include /etc/nginx/snippets/app-web-headers.conf' "$site")" = 3
check "headers: CSP names the API" has "$headers" "connect-src 'self' https://api\.example\.test"
check "headers: HSTS" has "$headers" 'Strict-Transport-Security "max-age=31536000; includeSubDomains"'
check "headers: frame-ancestors none" has "$headers" "frame-ancestors 'none'"
check "https: http2 on the listen lines (nginx 1.24)" test "$(grep -c 'listen 443 ssl http2;' "$site")" = 2
check "https: no separate http2 directive (nginx 1.25.1+ only)" lacks "$site" '^[[:space:]]*http2 on;'
check "tls: 1.2 and 1.3 only" has "$R/etc/nginx/conf.d/tls.conf" '^ssl_protocols TLSv1.2 TLSv1.3;$'
check "tls: Ubuntu's own ssl_protocols and cipher preference are commented out" has "$out3" 'sed -i -E .*ssl_prefer_server_ciphers.* /etc/nginx/nginx\.conf$'
check "no placeholder left in nginx files" lacks "$site" '@[A-Z_]+@'

echo "Provisioning with backups on (go-live)"
base_env
backup_env
R="$WORK/root-on"
mkdir -p "$R"
out="$WORK/provision-on.out"
if HOST_ROOT="$R" provision >"$out" 2>&1; then ok "every step runs with backups on"; else bad "every step runs with backups on" "$(tail -20 "$out")"; fi
conf="$R/etc/postgresql/16/main/conf.d/app.conf"
check "archiving on" has "$conf" '^archive_mode = on$'
check "archive_command is WAL-G wal-push" has "$conf" "^archive_command = '/usr/local/bin/wal-g --config /etc/wal-g/walg.json wal-push %p'$"
check "WAL-G: the pinned release is downloaded" has "$out" 'releases/download/v3.0.9/wal-g-pg-24.04-amd64.tar.gz'
walg="$R/etc/wal-g/walg.json"
check "walg.json is valid JSON" jq -e . "$walg"
check "walg.json: Object Storage cluster is one variable" jq -e '.AWS_ENDPOINT == "https://de-fra-1.linodeobjects.com" and .AWS_REGION == "de-fra-1"' "$walg"
check "walg.json: client-side encryption" jq -e '.WALG_LIBSODIUM_KEY | length == 64' "$walg"
check "walg.json: prefix" jq -e '.WALG_S3_PREFIX == "s3://app-backups-test/db"' "$walg"
check "backup timer enabled" has "$out" 'systemctl enable --now app-db-backup.timer'
check "backup runs as postgres" has "$R/etc/systemd/system/app-db-backup.service" '^User=postgres$'
check "no secret in the output" no_secrets_in "$out"
check "walg.json not world-readable" test "$(stat -c %a "$walg" 2>/dev/null || stat -f %Lp "$walg")" = 640

echo "Sizing and refusals"
base_env
R="$WORK/root-8g"
mkdir -p "$R"
HOST_RAM_MB=8192 PG_PROFILE=dedicated PG_ALLOW_SUBNET=10.0.0.0/24 PG_LISTEN_ADDRESS=10.0.0.2 HOST_ROOT="$R" provision --steps postgres,firewall >"$WORK/8g.out" 2>&1
conf="$R/etc/postgresql/16/main/conf.d/app.conf"
check "dedicated 8 GB: shared_buffers 2048MB" has "$conf" '^shared_buffers = 2048MB$'
check "dedicated 8 GB: effective_cache_size 6144MB" has "$conf" '^effective_cache_size = 6144MB$'
check "scale out: listens on the private address" has "$conf" "^listen_addresses = 'localhost,10\.0\.0\.2'$"
check "scale out: the subnet over TLS" has "$R/etc/postgresql/16/main/pg_hba.conf" '^hostssl +all +all +10\.0\.0\.0/24 +scram-sha-256$'
check "scale out: 5432 from the subnet only" has "$WORK/8g.out" 'ufw allow from 10.0.0.0/24 to any port 5432 proto tcp'
base_env
unset REDIS_PASSWORD
if provision --steps redis >"$WORK/refuse.out" 2>&1; then bad "a missing secret stops the step"; else check "a missing secret stops the step, naming it" has "$WORK/refuse.out" 'REDIS_PASSWORD'; fi
base_env
backup_env
export WALG_LIBSODIUM_KEY=short
if provision --steps backups >"$WORK/refuse2.out" 2>&1; then bad "a bad encryption key stops the backups step"; else ok "a bad encryption key stops the backups step"; fi
check "the key is not printed" lacks "$WORK/refuse2.out" 'short'
base_env
if HOST_ROOT="$WORK/x" bash "$HOST_DIR/provision-single-host.sh" --steps swap >/dev/null 2>&1; then bad "HOST_ROOT refused outside a dry run"; else ok "HOST_ROOT refused outside a dry run"; fi
if provision --steps nope >/dev/null 2>&1; then bad "an unknown step is refused"; else ok "an unknown step is refused"; fi

echo "Retention (owner decisions: 7 days PITR, 30 dailies, 12 monthlies)"
plan="$(jq -r --arg now 1792670400 --arg pitr 7 --arg daily 30 --arg monthly 12 -f "$HOST_DIR/walg-retention.jq" "$HOST_DIR/tests/fixtures/walg-backup-list.json")"
want="mark base_000000010000000000000010
unmark base_000000010000000000000020
mark base_000000010000000000000030
mark base_000000010000000000000040
mark base_000000010000000000000050
mark base_000000010000000000000060
delete-before base_000000010000000000000070"
if [ "$plan" = "$want" ]; then ok "plan: monthlies and dailies marked, PITR anchor kept, older deleted"; else bad "retention plan" "$plan"; fi
plan="$(jq -r --arg now 1792670400 --arg pitr 30 --arg daily 30 --arg monthly 12 -f "$HOST_DIR/walg-retention.jq" "$HOST_DIR/tests/fixtures/walg-backup-list.json" | tail -1)"
check "a longer window keeps more (30 days: anchor on Sep 22 or before)" test "$plan" = "delete-before base_000000010000000000000030"
plan="$(printf '[]' | jq -r --arg now 1792670400 --arg pitr 7 --arg daily 30 --arg monthly 12 -f "$HOST_DIR/walg-retention.jq")"
check "no backups: nothing to do" test -z "$plan"

echo "Nightly backup (stub WAL-G)"
stub="$WORK/stub-walg"
cat >"$stub" <<EOF
#!/usr/bin/env bash
echo "\$*" >>"$WORK/walg.calls"
case "\$*" in *backup-list*) cat "$HOST_DIR/tests/fixtures/walg-backup-list.json" ;; esac
EOF
chmod +x "$stub"
printf 'BACKUPS_ENABLED=true\nBACKUP_PITR_DAYS=7\nBACKUP_DAILY_DAYS=30\nBACKUP_MONTHLY_MONTHS=12\n' >"$WORK/host.env"
if HOST_ENV="$WORK/host.env" WALG_BIN="$stub" WALG_CONFIG=/x/walg.json PG_DATA=/x/data STATE_DIRECTORY="$WORK/state" NOW_EPOCH=1792670400 \
  bash "$HOST_DIR/db-backup.sh" >"$WORK/backup.out" 2>&1; then ok "db-backup.sh runs"; else bad "db-backup.sh runs" "$(cat "$WORK/backup.out")"; fi
calls="$(cat "$WORK/walg.calls" 2>/dev/null)"
check "backup-push first" test "$(head -1 "$WORK/walg.calls")" = "--config /x/walg.json backup-push /x/data"
check "marks before the delete" test "$(grep -n 'backup-mark' "$WORK/walg.calls" | tail -1 | cut -d: -f1)" -lt "$(grep -n 'delete before' "$WORK/walg.calls" | cut -d: -f1)"
check "unmark uses -i" has "$WORK/walg.calls" 'backup-mark -i base_000000010000000000000020'
check "delete before the anchor, confirmed" has "$WORK/walg.calls" 'delete before FIND_FULL base_000000010000000000000070 --confirm$'
check "success recorded for the watch" test "$(cat "$WORK/state/last-success")" = 1792670400
printf 'BACKUPS_ENABLED=false\n' >"$WORK/host-off.env"
if HOST_ENV="$WORK/host-off.env" WALG_BIN="$stub" bash "$HOST_DIR/db-backup.sh" >/dev/null 2>&1; then bad "refused while backups are off"; else ok "refused while backups are off"; fi
[ -n "$calls" ] || true

echo "Restore drill (dry run)"
mkdir -p "$WORK/basebackup"
echo 16 >"$WORK/basebackup/PG_VERSION"
if HOST_ENV="$WORK/host-off.env" DRILL_ROOT="$WORK/drill" bash "$HOST_DIR/restore-drill.sh" --dry-run --source "dir:$WORK/basebackup" >"$WORK/drill.out" 2>&1; then ok "drill from a pg_basebackup copy"; else bad "drill from a pg_basebackup copy" "$(cat "$WORK/drill.out")"; fi
check "drill: scratch cluster with archiving off" has "$WORK/drill.out" 'pg_ctl -D .* -o .*archive_mode=off.* start'
check "drill: on its own port" has "$WORK/drill.out" 'Recovering on port 5433'
check "drill: database renamed before verifying" has "$WORK/drill.out" 'RENAME\\? TO\\? .*app_drill'
check "drill: backup:verify against the copy" has "$WORK/drill.out" 'BACKUP_VERIFY_PORT=5433 php artisan backup:verify --target-database=app_drill --tolerance=0.05'
check "drill: the copy is deleted" has "$WORK/drill.out" 'rm -rf .*drill/'
if HOST_ENV="$WORK/host-off.env" bash "$HOST_DIR/restore-drill.sh" --dry-run >"$WORK/drill2.out" 2>&1; then bad "drill from WAL-G refused while backups are off"; else check "drill from WAL-G refused while backups are off" has "$WORK/drill2.out" 'Backups are off'; fi
if HOST_ENV="$WORK/host-off.env" DRILL_PORT=5432 bash "$HOST_DIR/restore-drill.sh" --dry-run --source "dir:$WORK/basebackup" >/dev/null 2>&1; then bad "drill on the live port refused"; else ok "drill on the live port refused"; fi

echo "Host watch (stub curl, psql, df)"
cat >"$WORK/stub-curl" <<EOF
#!/usr/bin/env bash
case "\$*" in
  *--mail-from*)
    [ "\${STUB_MAIL_FAIL:-0}" = 0 ] || exit 7
    echo "\$*" >>"$WORK/mail.calls"
    while [ \$# -gt 0 ]; do [ "\$1" != --upload-file ] || cat "\$2" >>"$WORK/mail.messages"; shift; done ;;
  *) printf '%s' "\${STUB_READY_CODE:-200}" ;;
esac
EOF
cat >"$WORK/stub-psql" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "${STUB_PG:-5|40|0|0|0|0}"
EOF
cat >"$WORK/stub-df" <<'EOF'
#!/usr/bin/env bash
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda 51200000 1000 1000 %s%% /\n' "${STUB_DISK:-40}"
EOF
chmod +x "$WORK/stub-curl" "$WORK/stub-psql" "$WORK/stub-df"
# The documented staging mail settings: Brevo's relay on 2525 with STARTTLS.
# shellcheck disable=SC2016 # "${APP_NAME}" is written literally, as in .env
printf 'APP_NAME="Test display name"\nMAIL_MAILER=smtp\nMAIL_HOST=smtp-relay.example.test\nMAIL_PORT=2525\nMAIL_USERNAME=owner@example.test\nMAIL_PASSWORD="mailbox-secret-0123456789"\nMAIL_FROM_ADDRESS="alerts@example.test"\nMAIL_FROM_NAME="${APP_NAME}"\n' >"$WORK/app.env"
printf 'API_DOMAIN=api.example.test\nHEALTH_ALERT_EMAIL=owner@example.test\nBACKUPS_ENABLED=false\nPG_DATA=/\n' >"$WORK/watch.env"
watch() {
  HOST_ENV="$WORK/watch.env" APP_ENV_FILE="$WORK/app.env" STATE_DIRECTORY="$WORK/watch-state" CURL_BIN="$WORK/stub-curl" \
    WATCH_PSQL="$WORK/stub-psql" WATCH_DF="$WORK/stub-df" bash "$HOST_DIR/host-watch.sh" "$@"
}
watch >"$WORK/w1.out" 2>&1
check "all well: nothing sent" test ! -e "$WORK/mail.calls"
check "all well: says so" has "$WORK/w1.out" 'All checks pass'
STUB_READY_CODE=503 STUB_DISK=91 watch >"$WORK/w2.out" 2>&1
check "a problem: one email" test "$(wc -l <"$WORK/mail.calls" | tr -d ' ')" = 1
check "the email names each problem" has "$WORK/mail.messages" '^Subject: \[api\.example\.test\] Problem: disk:/ ready'
check "the email says what to do" has "$WORK/mail.messages" 'is 91% full'
check "over STARTTLS on 2525, required" has "$WORK/mail.calls" '--ssl-reqd .*--url smtp://smtp-relay\.example\.test:2525'
check "from the display name in APP_NAME" has "$WORK/mail.messages" '^From: "Test display name" <alerts@example\.test>'
check "the mailbox password never in arguments" lacks "$WORK/mail.calls" 'mailbox-secret'
STUB_READY_CODE=503 STUB_DISK=92 watch >/dev/null 2>&1
check "same problems again: no second email" test "$(wc -l <"$WORK/mail.calls" | tr -d ' ')" = 1
watch >/dev/null 2>&1
check "recovery: one more email" test "$(wc -l <"$WORK/mail.calls" | tr -d ' ')" = 2
check "the recovery email" has "$WORK/mail.messages" '^Subject: \[api\.example\.test\] Recovered: every check passes'
STUB_PG='38|40|900|0|0|0' STUB_MAIL_FAIL=1 watch >"$WORK/w5.out" 2>&1
check "connections and a long transaction found" has "$WORK/w5.out" '38 of 40 connections.*|open for 900 s'
check "a failed email is retried next run (state kept)" test ! -s "$WORK/watch-state/state"
STUB_PG='38|40|900|0|0|0' watch >/dev/null 2>&1
check "the retry is sent" test "$(wc -l <"$WORK/mail.calls" | tr -d ' ')" = 3
printf 'API_DOMAIN=api.example.test\nHEALTH_ALERT_EMAIL=owner@example.test\nBACKUPS_ENABLED=true\nPG_DATA=/\n' >"$WORK/watch.env"
BACKUP_STATE="$WORK/none" STUB_PG='5|40|0|0|1|25' watch --dry-run >"$WORK/w6.out" 2>&1
check "backups on: archiving failure found" has "$WORK/w6.out" 'WAL archiving is failing'
check "backups on: a missing base backup found" has "$WORK/w6.out" 'last base backup is'
STUB_READY_CODE=000 watch --dry-run >"$WORK/w7.out" 2>&1
check "no answer at all is named as such" has "$WORK/w7.out" 'readiness: no answer from https://api\.example\.test/health/ready'
check "dry run sends nothing" test "$(wc -l <"$WORK/mail.calls" | tr -d ' ')" = 3

echo
echo "$passed passed, $failed failed"
[ "$failed" = 0 ]
