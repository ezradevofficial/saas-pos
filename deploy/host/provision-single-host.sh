#!/usr/bin/env bash
# Provisions the single starting host (ADR 025, NFR-01, NFR-06, NFR-07,
# TEN-01): Ubuntu 24.04 LTS with PostgreSQL 16 (PGDG), Redis, PHP 8.3-FPM,
# nginx with Let's Encrypt, Horizon and the scheduler under systemd, ufw,
# fail2ban, unattended-upgrades, swap, and the host watch. Database
# backups with WAL-G stay off until BACKUPS_ENABLED=true (go-live).
#
# Idempotent: run it again after any change; it rewrites only files whose
# content changed and restarts only what those changes need. It never drops
# a database, a role, a table or a file it did not write, takes every secret
# from the environment, and prints none.
#
# Usage, as root, from a checkout of the repository on the host:
#   sudo -E bash deploy/host/provision-single-host.sh [--dry-run] [--steps a,b,c]
#
# Steps, in order (default: all): swap packages firewall fail2ban upgrades
#   app-dirs php redis tools backups postgres db-roles nginx services
#
# The environment it reads is listed in docs/deploy-linode.md ("Provisioning
# the host"). --dry-run prints every command and file it would change;
# with HOST_ROOT=<dir> as well, files are written under <dir> instead (tests).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
# shellcheck source=deploy/host/lib.sh
. "$HERE/lib.sh"
TEMPLATES="$HERE/templates"

ALL_STEPS="swap packages firewall fail2ban upgrades app-dirs php redis tools backups postgres db-roles nginx services"
STEPS="$ALL_STEPS"
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --steps) STEPS="$(printf '%s' "${2:?--steps needs a list}" | tr ',' ' ')"; shift ;;
    --steps=*) STEPS="$(printf '%s' "${1#--steps=}" | tr ',' ' ')" ;;
    -h | --help) sed -n '2,24p' "$0"; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
  shift
done
export DRY_RUN
[ -z "$HOST_ROOT" ] || is_dry_run || die "HOST_ROOT is only for dry runs (--dry-run)."
for step in $STEPS; do
  case " $ALL_STEPS " in *" $step "*) ;; *) die "Unknown step: $step (steps: $ALL_STEPS)" ;; esac
done

# --- Settings (environment, with defaults) ------------------------------

DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_PATH="${DEPLOY_PATH:-/srv/platform}"
DB_NAME="${DB_NAME:-app}"
DB_ADMIN_USER="${DB_ADMIN_USER:-db_admin}"
BACKUPS_ENABLED="${BACKUPS_ENABLED:-false}"

PG_VERSION=16
PG_CONF_DIR="${PG_CONF_DIR:-/etc/postgresql/$PG_VERSION/main}"
PG_DATA="${PG_DATA:-/var/lib/postgresql/$PG_VERSION/main}"
PG_PORT="${PG_PORT:-5432}"
PG_SOCKET_DIR="${PG_SOCKET_DIR:-/var/run/postgresql}"
PG_OS_USER="${PG_OS_USER:-postgres}"
PG_SUPERUSER="${PG_SUPERUSER:-postgres}"
PG_MAX_CONNECTIONS="${PG_MAX_CONNECTIONS:-40}"
# shared: the database shares the host with the app (the starting host).
# dedicated: a database server of its own (Scale out, docs/deploy-linode.md).
PG_PROFILE="${PG_PROFILE:-shared}"
# Scale out only: the private subnet allowed to reach 5432, and the address
# to listen on. Empty: localhost only and 5432 closed.
PG_ALLOW_SUBNET="${PG_ALLOW_SUBNET:-}"
PG_LISTEN_ADDRESS="${PG_LISTEN_ADDRESS:-}"
PG_SSL_CERT_FILE="${PG_SSL_CERT_FILE:-/etc/ssl/certs/ssl-cert-snakeoil.pem}"
PG_SSL_KEY_FILE="${PG_SSL_KEY_FILE:-/etc/ssl/private/ssl-cert-snakeoil.key}"

SWAP_SIZE_MB="${SWAP_SIZE_MB:-2048}"
SWAPPINESS="${SWAPPINESS:-10}"
REDIS_MAXMEMORY="${REDIS_MAXMEMORY:-192mb}"
CERT_NAME="${CERT_NAME:-platform}"

# Retention (owner decisions, ADR 025): point-in-time recovery window,
# daily base backups and monthly base backups kept.
BACKUP_PITR_DAYS="${BACKUP_PITR_DAYS:-7}"
BACKUP_DAILY_DAYS="${BACKUP_DAILY_DAYS:-30}"
BACKUP_MONTHLY_MONTHS="${BACKUP_MONTHLY_MONTHS:-12}"
# Host watch thresholds.
WATCH_DISK_PCT="${WATCH_DISK_PCT:-80}"
WATCH_CONNECTIONS_PCT="${WATCH_CONNECTIONS_PCT:-80}"
WATCH_LONG_TX_SECONDS="${WATCH_LONG_TX_SECONDS:-600}"
WATCH_BACKUP_MAX_AGE_HOURS="${WATCH_BACKUP_MAX_AGE_HOURS:-26}"

case "$BACKUPS_ENABLED" in true | false) ;; *) die "BACKUPS_ENABLED must be true or false." ;; esac
case "$PG_PROFILE" in shared | dedicated) ;; *) die "PG_PROFILE must be shared or dedicated." ;; esac
[[ "$DB_NAME" =~ ^[a-z_][a-z0-9_]*$ ]] || die "Invalid DB_NAME: use lowercase letters, digits and _."
[[ "$DB_ADMIN_USER" =~ ^[a-z_][a-z0-9_]*$ ]] || die "Invalid DB_ADMIN_USER: use lowercase letters, digits and _."
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || die "Invalid DEPLOY_USER."
case "$DEPLOY_PATH" in /*) ;; *) die "DEPLOY_PATH must be absolute." ;; esac
for n in PG_MAX_CONNECTIONS SWAP_SIZE_MB SWAPPINESS BACKUP_PITR_DAYS BACKUP_DAILY_DAYS BACKUP_MONTHLY_MONTHS WATCH_DISK_PCT WATCH_CONNECTIONS_PCT WATCH_LONG_TX_SECONDS WATCH_BACKUP_MAX_AGE_HOURS; do
  [[ "${!n}" =~ ^[0-9]+$ ]] || die "$n must be a whole number."
done

# The host's memory in MB, rounded up to a whole GB (a "2 GB" Linode reports
# a little less). HOST_RAM_MB overrides it.
host_ram_mb() {
  if [ -n "${HOST_RAM_MB:-}" ]; then
    printf '%s' "$HOST_RAM_MB"
    return
  fi
  local kb
  kb="$(awk '/^MemTotal:/ { print $2 }' /proc/meminfo 2>/dev/null || true)"
  [ -n "$kb" ] || die "Cannot read /proc/meminfo; set HOST_RAM_MB."
  printf '%s' $(((kb / 1024 + 1023) / 1024 * 1024))
}

need_root() {
  is_dry_run && return 0
  [ "$(id -u)" = 0 ] || die "Step $1 needs root (sudo -E bash $0)."
}

apt_install() {
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"
}

systemd_reload() { run systemctl daemon-reload; }

# --- Steps ---------------------------------------------------------------

step_swap() {
  need_root swap
  log "Swap: ${SWAP_SIZE_MB} MB file, swappiness $SWAPPINESS"
  if probe swapon --show=NAME --noheadings 2>/dev/null | grep -q .; then
    note "swap already active"
  elif [ -e /swapfile ] && ! is_dry_run; then
    run swapon /swapfile
  else
    run fallocate -l "${SWAP_SIZE_MB}M" /swapfile
    run chmod 600 /swapfile
    run mkswap /swapfile
    run swapon /swapfile
  fi
  append_once /etc/fstab '/swapfile none swap sw 0 0'
  printf '# ADR 025: swap only under memory pressure.\nvm.swappiness = %s\n' "$SWAPPINESS" |
    write_file /etc/sysctl.d/90-app.conf 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || run sysctl -p /etc/sysctl.d/90-app.conf
}

step_packages() {
  need_root packages
  log "Packages (Ubuntu 24.04 and the PostgreSQL apt repository)"
  if ! is_dry_run; then
    # shellcheck disable=SC1091
    . /etc/os-release
    if [ "${ID:-}" != ubuntu ] || [ "${VERSION_ID:-}" != 24.04 ]; then
      die "This script is for Ubuntu 24.04 LTS (found ${PRETTY_NAME:-unknown})."
    fi
  fi
  run apt-get update -q
  apt_install ca-certificates curl gnupg jq unzip ufw fail2ban unattended-upgrades ssl-cert

  local key=/etc/apt/keyrings/pgdg.asc
  if [ ! -s "$(host_path "$key")" ]; then
    local tmp
    tmp="$(mktemp)"
    run curl -fsSL -o "$tmp" https://www.postgresql.org/media/keys/ACCC4CF8.asc
    if ! is_dry_run; then
      local fpr
      fpr="$(gpg --show-keys --with-colons "$tmp" | awk -F: '/^fpr/ { print $10; exit }')"
      [ "$fpr" = "$PGDG_KEY_FINGERPRINT" ] || die "The PostgreSQL signing key has fingerprint $fpr, expected $PGDG_KEY_FINGERPRINT."
    fi
    write_file "$key" 0644 root:root <"$tmp"
    rm -f "$tmp"
  fi
  write_file /etc/apt/sources.list.d/pgdg.sources 0644 root:root <<EOF
Types: deb
URIs: https://apt.postgresql.org/pub/repos/apt
Suites: noble-pgdg
Components: main
Signed-By: $key
EOF
  run apt-get update -q
  # New clusters get data checksums: postgresql-common is installed first,
  # so the cluster that postgresql-16 creates uses this setting.
  apt_install postgresql-common
  local cc=/etc/postgresql-common/createcluster.conf
  if ! grep -qsx "initdb_options = '--data-checksums'" "$(host_path "$cc")"; then
    if [ -f "$(host_path "$cc")" ] && ! is_dry_run; then
      sed -i '/^initdb_options/d' "$cc"
    fi
    append_once "$cc" "initdb_options = '--data-checksums'"
  fi
  apt_install "postgresql-$PG_VERSION" "postgresql-client-$PG_VERSION" redis-server nginx certbot \
    php8.3-fpm php8.3-cli php8.3-pgsql php8.3-intl php8.3-mbstring php8.3-bcmath php8.3-zip \
    php8.3-gd php8.3-curl php8.3-xml composer
  if ! is_dry_run; then
    local sums
    sums="$(runuser -u postgres -- "/usr/lib/postgresql/$PG_VERSION/bin/pg_controldata" "$PG_DATA" 2>/dev/null | awk -F: '/checksum version/ { gsub(/ /, "", $2); print $2 }')"
    [ "$sums" != 0 ] || warn "The cluster has no data checksums (it existed before this script). Enabling them needs pg_checksums --enable with the cluster stopped; see docs/deploy-linode.md."
  fi
}

step_firewall() {
  need_root firewall
  log "Firewall (ufw): 22, 80 and 443 only"
  run ufw default deny incoming
  run ufw default allow outgoing
  run ufw allow 22/tcp
  run ufw allow 80/tcp
  run ufw allow 443/tcp
  if [ -n "$PG_ALLOW_SUBNET" ]; then
    run ufw allow from "$PG_ALLOW_SUBNET" to any port 5432 proto tcp
  fi
  # A rule opening 5432 to everyone (left by hand) is closed.
  if probe ufw status 2>/dev/null | grep -Eq '^5432(/tcp)? +ALLOW +Anywhere'; then
    run ufw delete allow 5432/tcp || true
    run ufw delete allow 5432 || true
  fi
  run ufw --force enable
}

step_fail2ban() {
  need_root fail2ban
  log "fail2ban for sshd"
  write_file /etc/fail2ban/jail.d/sshd.local 0644 root:root <<'EOF'
# ADR 025: ban an address for an hour after 5 failed SSH sign-ins in 10 minutes.
[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
EOF
  [ "$FILE_CHANGED" = 0 ] || run systemctl restart fail2ban
  run systemctl enable --now fail2ban
}

step_upgrades() {
  need_root upgrades
  log "unattended-upgrades: Ubuntu security updates, no automatic reboot"
  write_file /etc/apt/apt.conf.d/20auto-upgrades 0644 root:root <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
  # PostgreSQL minor releases (PGDG) restart the database: applied by hand
  # in a quiet window (docs/deploy-linode.md), not here. Reboots too.
  write_file /etc/apt/apt.conf.d/52app-unattended 0644 root:root <<'EOF'
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
EOF
  run systemctl enable --now unattended-upgrades
}

step_app_dirs() {
  need_root app-dirs
  log "Deploy user $DEPLOY_USER and $DEPLOY_PATH"
  if ! probe id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    run adduser --disabled-password --gecos "" "$DEPLOY_USER"
  fi
  ensure_dir "$DEPLOY_PATH" 0755 "$DEPLOY_USER:$DEPLOY_USER"
  ensure_dir "$DEPLOY_PATH/api" 0755 "$DEPLOY_USER:$DEPLOY_USER"
  ensure_dir "$DEPLOY_PATH/web" 0755 "$DEPLOY_USER:$DEPLOY_USER"
  if [ -n "${DEPLOY_AUTHORIZED_KEY:-}" ]; then
    local home="/home/$DEPLOY_USER" auth
    auth="$(host_path "$home/.ssh/authorized_keys")"
    ensure_dir "$home/.ssh" 0700 "$DEPLOY_USER:$DEPLOY_USER"
    if ! grep -qsF "$DEPLOY_AUTHORIZED_KEY" "$auth"; then
      if is_dry_run && [ -z "$HOST_ROOT" ]; then
        printf '[dry-run] add the deploy key to %s\n' "$home/.ssh/authorized_keys"
      else
        printf '%s\n' "$DEPLOY_AUTHORIZED_KEY" >>"$auth"
        chmod 600 "$auth"
        [ -n "$HOST_ROOT" ] || chown "$DEPLOY_USER:$DEPLOY_USER" "$auth"
      fi
    fi
  fi
  # The API's .env: root-owned, readable by the app's user only (PHP-FPM,
  # Horizon and the deploy's artisan commands run as that user), never in
  # git. The owner writes it (docs/deploy-linode.md); this only fixes its mode.
  local env="$DEPLOY_PATH/api/.env"
  if [ -f "$(host_path "$env")" ]; then
    run chown "root:$DEPLOY_USER" "$env"
    run chmod 0640 "$env"
  else
    note "$env does not exist yet: write it before the first deploy (docs/deploy-linode.md)"
  fi
}

step_php() {
  need_root php
  local ram children
  ram="$(host_ram_mb)"
  # About 340 MB of RAM per PHP-FPM worker budget: 6 on a 2 GB host.
  children=$((ram / 340))
  [ "$children" -ge 4 ] || children=4
  children="${FPM_MAX_CHILDREN:-$children}"
  log "PHP 8.3: production settings, FPM pool of $children workers as $DEPLOY_USER"
  local fpm_ini cli_ini pool
  fpm_ini="$(render_template "$TEMPLATES/php-app.ini.tmpl" PHP_MEMORY_LIMIT=256M)"
  cli_ini="$(render_template "$TEMPLATES/php-app.ini.tmpl" PHP_MEMORY_LIMIT=-1)"
  pool="$(render_template "$TEMPLATES/php-fpm-pool.conf.tmpl" DEPLOY_USER="$DEPLOY_USER" FPM_MAX_CHILDREN="$children")"
  local changed=0
  printf '%s\n' "$fpm_ini" | write_file /etc/php/8.3/fpm/conf.d/99-app.ini 0644 root:root
  changed=$((changed + FILE_CHANGED))
  printf '%s\n' "$cli_ini" | write_file /etc/php/8.3/cli/conf.d/99-app.ini 0644 root:root
  printf '%s\n' "$pool" | write_file /etc/php/8.3/fpm/pool.d/app.conf 0644 root:root
  changed=$((changed + FILE_CHANGED))
  # The stock pool would claim the same socket: set aside, not deleted.
  if [ -f "$(host_path /etc/php/8.3/fpm/pool.d/www.conf)" ]; then
    run mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled
    changed=1
  fi
  run php-fpm8.3 -t
  [ "$changed" = 0 ] || run systemctl restart php8.3-fpm
  run systemctl enable --now php8.3-fpm
}

step_redis() {
  need_root redis
  require_secret REDIS_PASSWORD 24
  require_plain_secret REDIS_PASSWORD
  log "Redis: localhost only, password, maxmemory $REDIS_MAXMEMORY, no eviction"
  local conf
  conf="$(render_template "$TEMPLATES/redis.conf.tmpl" REDIS_REQUIREPASS="$REDIS_PASSWORD" REDIS_MAXMEMORY="$REDIS_MAXMEMORY")"
  printf '%s\n' "$conf" | write_file /etc/redis/app.conf 0640 redis:redis
  local changed="$FILE_CHANGED"
  # Last in redis.conf, so these settings win.
  append_once /etc/redis/redis.conf 'include /etc/redis/app.conf'
  [ "$FILE_CHANGED" = 0 ] || changed=1
  [ "$changed" = 0 ] || run systemctl restart redis-server
  run systemctl enable --now redis-server
}

install_walg() {
  local bin=/usr/local/bin/wal-g
  if probe "$bin" --version 2>/dev/null | grep -q "$WALG_VERSION"; then
    note "WAL-G $WALG_VERSION already installed"
    return 0
  fi
  local dir url
  dir="$(mktemp -d)"
  url="https://github.com/wal-g/wal-g/releases/download/$WALG_VERSION/$WALG_ASSET"
  run curl -fsSL -o "$dir/$WALG_ASSET" "$url"
  if ! is_dry_run; then
    [ "$(sha256_of "$dir/$WALG_ASSET")" = "$WALG_SHA256" ] || die "WAL-G download does not match the pinned checksum: not installed."
    tar -xzf "$dir/$WALG_ASSET" -C "$dir"
  fi
  run install -m 0755 -o root -g root "$dir/${WALG_ASSET%.tar.gz}" "$bin"
  rm -rf "$dir"
}

step_tools() {
  if [ "$BACKUPS_ENABLED" != true ]; then
    log "Server tools: none needed while backups are off"
    return 0
  fi
  need_root tools
  log "Server tools: WAL-G $WALG_VERSION (pinned, checksum checked)"
  install_walg
}

# The scripts the timers run, copied to /usr/local/lib/app-host.
install_host_scripts() {
  ensure_dir /usr/local/lib/app-host 0755 root:root
  local f
  for f in lib.sh db-backup.sh walg-retention.jq host-watch.sh restore-drill.sh; do
    write_file "/usr/local/lib/app-host/$f" 0755 root:root <"$HERE/$f"
  done
}

write_host_env() {
  ensure_dir /etc/app-host 0755 root:root
  # Not secret, but the alert address is personal data: root only.
  write_file /etc/app-host/host.env 0600 root:root <<EOF
# ADR 025: settings for the host's own scripts (written by provision-single-host.sh).
DEPLOY_USER=$DEPLOY_USER
DEPLOY_PATH=$DEPLOY_PATH
DB_NAME=$DB_NAME
API_DOMAIN=${API_DOMAIN:-}
BACKUPS_ENABLED=$BACKUPS_ENABLED
PG_VERSION=$PG_VERSION
PG_DATA=$PG_DATA
PG_PORT=$PG_PORT
PG_SOCKET_DIR=$PG_SOCKET_DIR
WALG_CONFIG=/etc/wal-g/walg.json
BACKUP_PITR_DAYS=$BACKUP_PITR_DAYS
BACKUP_DAILY_DAYS=$BACKUP_DAILY_DAYS
BACKUP_MONTHLY_MONTHS=$BACKUP_MONTHLY_MONTHS
HEALTH_ALERT_EMAIL=${HEALTH_ALERT_EMAIL:-}
WATCH_DISK_PCT=$WATCH_DISK_PCT
WATCH_CONNECTIONS_PCT=$WATCH_CONNECTIONS_PCT
WATCH_LONG_TX_SECONDS=$WATCH_LONG_TX_SECONDS
WATCH_BACKUP_MAX_AGE_HOURS=$WATCH_BACKUP_MAX_AGE_HOURS
EOF
}

step_backups() {
  need_root backups
  install_host_scripts
  write_host_env
  if [ "$BACKUPS_ENABLED" != true ]; then
    log "Backups: off (BACKUPS_ENABLED=false): no WAL archiving, no base backups. Turn on before go-live (docs/backups.md)."
    if [ -f "$(host_path /etc/systemd/system/app-db-backup.timer)" ]; then
      run systemctl disable --now app-db-backup.timer
    fi
    return 0
  fi
  require_env OBJECT_STORAGE_CLUSTER BACKUP_BUCKET BACKUP_ACCESS_KEY_ID
  require_secret BACKUP_SECRET_ACCESS_KEY 20
  [[ "${WALG_LIBSODIUM_KEY:-}" =~ ^[0-9a-f]{64}$ ]] || die "Set WALG_LIBSODIUM_KEY to 32 random bytes in hex (openssl rand -hex 32); keep it in the password manager: without it no backup can be restored."
  [[ "$OBJECT_STORAGE_CLUSTER" =~ ^[a-z0-9-]+$ ]] || die "OBJECT_STORAGE_CLUSTER is a Linode Object Storage cluster id, e.g. de-fra-1."
  [[ "$BACKUP_BUCKET" =~ ^[a-z0-9][a-z0-9.-]+$ ]] || die "Invalid BACKUP_BUCKET."
  require_plain_secret BACKUP_ACCESS_KEY_ID
  require_plain_secret BACKUP_SECRET_ACCESS_KEY
  log "Backups: on. WAL-G to s3://$BACKUP_BUCKET/db on $OBJECT_STORAGE_CLUSTER; retention ${BACKUP_PITR_DAYS} d PITR, $BACKUP_DAILY_DAYS dailies, $BACKUP_MONTHLY_MONTHS monthlies"
  local walg
  walg="$(render_template "$TEMPLATES/walg.json.tmpl" \
    BACKUP_BUCKET="$BACKUP_BUCKET" OBJECT_STORAGE_CLUSTER="$OBJECT_STORAGE_CLUSTER" \
    BACKUP_ACCESS_KEY_ID="$BACKUP_ACCESS_KEY_ID" BACKUP_SECRET_ACCESS_KEY="$BACKUP_SECRET_ACCESS_KEY" \
    WALG_LIBSODIUM_KEY="$WALG_LIBSODIUM_KEY" PG_PORT="$PG_PORT" PG_DATA="$PG_DATA")"
  ensure_dir /etc/wal-g 0750 root:postgres
  printf '%s\n' "$walg" | write_file /etc/wal-g/walg.json 0640 root:postgres
  local unit
  for unit in app-db-backup.service app-db-backup.timer; do
    write_file "/etc/systemd/system/$unit" 0644 root:root <"$TEMPLATES/systemd/$unit"
  done
  systemd_reload
  run systemctl enable --now app-db-backup.timer
}

# postgresql.conf settings, sized from the host's memory (ADR 025).
render_pg_conf() {
  local ram="$1" sb ecs mwm wm listen archive_mode archive_command
  if [ "$PG_PROFILE" = shared ]; then
    sb=$((ram / 8))        # 256 MB on 2 GB: the app needs the rest
    ecs=$((ram * 3 / 8))   # 768 MB on 2 GB
    mwm=$((ram / 32))      # 64 MB on 2 GB
    wm=$((ram / 512))      # 4 MB on 2 GB
  else
    sb=$((ram / 4))
    ecs=$((ram * 3 / 4))
    mwm=$((ram / 16))
    wm=$((ram / 256))
  fi
  [ "$mwm" -le 1024 ] || mwm=1024
  [ "$wm" -ge 2 ] || wm=2
  [ "$wm" -le 64 ] || wm=64
  listen="localhost"
  [ -z "$PG_LISTEN_ADDRESS" ] || listen="localhost,$PG_LISTEN_ADDRESS"
  if [ "$BACKUPS_ENABLED" = true ]; then
    archive_mode=on
    archive_command="/usr/local/bin/wal-g --config /etc/wal-g/walg.json wal-push %p"
  else
    # Backups off: no archiving at all, so no WAL piles up waiting for it.
    archive_mode=off
    archive_command=""
  fi
  cat <<EOF
# ADR 025, NFR-01, NFR-07: written by deploy/host/provision-single-host.sh
# for a ${ram} MB host (profile $PG_PROFILE). Edit the script, not this file.

listen_addresses = '$listen'
port = $PG_PORT
max_connections = $PG_MAX_CONNECTIONS
superuser_reserved_connections = 3
password_encryption = scram-sha-256
ssl = on
ssl_cert_file = '$PG_SSL_CERT_FILE'
ssl_key_file = '$PG_SSL_KEY_FILE'
ssl_min_protocol_version = 'TLSv1.2'

# Memory
shared_buffers = ${sb}MB
effective_cache_size = ${ecs}MB
work_mem = ${wm}MB
maintenance_work_mem = ${mwm}MB
# Full tenant exports and the isolation fixture lock every tenant table in
# one transaction (ADR 002, ADR 015).
max_locks_per_transaction = 256
# A forgotten prepared transaction would hold back every till (ADR 004).
max_prepared_transactions = 0

# Storage (SSD)
random_page_cost = 1.1
effective_io_concurrency = 200
jit = off

# WAL and archiving (WAL-G, docs/backups.md)
wal_level = replica
wal_compression = on
max_wal_size = 1GB
min_wal_size = 80MB
checkpoint_completion_target = 0.9
archive_mode = $archive_mode
archive_command = '$archive_command'
archive_timeout = 60

# Sessions: the runtime connection sets its own, shorter timeouts
# (DB_IDLE_IN_TRANSACTION_TIMEOUT, DB_STATEMENT_TIMEOUT); this is the floor
# for every other client.
idle_in_transaction_session_timeout = '5min'

# Logging
log_min_duration_statement = 1000
log_lock_waits = on
log_temp_files = 10240
log_checkpoints = on
log_timezone = 'UTC'
timezone = 'UTC'
EOF
}

render_pg_hba() {
  cat <<EOF
# ADR 025, TEN-01: written by deploy/host/provision-single-host.sh.
# The superuser only through the local socket as the postgres OS user; every
# TCP client over TLS with scram-sha-256; everything else refused.
# TYPE   DATABASE     USER            ADDRESS          METHOD
local    all          $PG_SUPERUSER                    peer
local    replication  $PG_SUPERUSER                    peer
local    all          all                              scram-sha-256
host     all          $PG_SUPERUSER   0.0.0.0/0        reject
host     all          $PG_SUPERUSER   ::/0             reject
hostssl  all          all             127.0.0.1/32     scram-sha-256
hostssl  all          all             ::1/128          scram-sha-256
EOF
  if [ -n "$PG_ALLOW_SUBNET" ]; then
    printf 'hostssl  all          all             %-16s scram-sha-256\n' "$PG_ALLOW_SUBNET"
  fi
  cat <<'EOF'
host     all          all             0.0.0.0/0        reject
host     all          all             ::/0             reject
EOF
}

step_postgres() {
  need_root postgres
  local ram conf hba
  ram="$(host_ram_mb)"
  log "PostgreSQL $PG_VERSION: settings for ${ram} MB ($PG_PROFILE), localhost${PG_ALLOW_SUBNET:+ and $PG_ALLOW_SUBNET}, archiving $([ "$BACKUPS_ENABLED" = true ] && echo on || echo off)"
  conf="$(render_pg_conf "$ram")"
  hba="$(render_pg_hba)"
  printf '%s\n' "$conf" | write_file "$PG_CONF_DIR/conf.d/app.conf" 0644 "$PG_OS_USER:$PG_OS_USER"
  local restart="$FILE_CHANGED"
  if [ -f "$(host_path "$PG_CONF_DIR/pg_hba.conf")" ] && [ ! -f "$(host_path "$PG_CONF_DIR/pg_hba.conf.orig")" ]; then
    run cp -p "$PG_CONF_DIR/pg_hba.conf" "$PG_CONF_DIR/pg_hba.conf.orig"
  fi
  printf '%s\n' "$hba" | write_file "$PG_CONF_DIR/pg_hba.conf" 0640 "$PG_OS_USER:$PG_OS_USER"
  local reload="$FILE_CHANGED"
  if [ "$restart" = 1 ]; then
    # shared_buffers, max_locks_per_transaction and archive_mode need a restart.
    run systemctl restart "postgresql@$PG_VERSION-main"
  elif [ "$reload" = 1 ]; then
    run systemctl reload "postgresql@$PG_VERSION-main"
  fi
  run systemctl enable --now "postgresql@$PG_VERSION-main"
}

# Superuser psql through the local socket (peer authentication).
psql_super() {
  as_user "$PG_OS_USER" psql -X -q -v ON_ERROR_STOP=1 -h "$PG_SOCKET_DIR" -p "$PG_PORT" -U "$PG_SUPERUSER" -d postgres "$@"
}

step_db_roles() {
  require_secret DB_ADMIN_PASSWORD 16
  require_secret APP_PASSWORD 16
  require_secret APP_OWNER_PASSWORD 16
  require_secret APP_PLATFORM_PASSWORD 16
  [ "$PG_OS_USER" = "$(id -un)" ] || need_root db-roles
  log "Database: cluster admin $DB_ADMIN_USER (NOSUPERUSER CREATEDB CREATEROLE BYPASSRLS), then provision-db.sh for $DB_NAME"
  export DB_ADMIN_PASSWORD
  # The password travels in the environment and is read with \getenv, so it
  # never appears in a process listing or the output.
  psql_super -v admin="$DB_ADMIN_USER" <<'SQL'
\getenv admin_pw DB_ADMIN_PASSWORD
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER CREATEDB CREATEROLE BYPASSRLS', :'admin')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'admin') \gexec
ALTER ROLE :"admin" LOGIN NOSUPERUSER CREATEDB CREATEROLE BYPASSRLS PASSWORD :'admin_pw';
SQL
  # As the cluster admin, over TLS on localhost: the same path as a managed
  # cluster's admin (deploy rehearsal 2026-10-22).
  PGHOST=127.0.0.1 PGPORT="$PG_PORT" PGUSER="$DB_ADMIN_USER" PGPASSWORD="$DB_ADMIN_PASSWORD" PGSSLMODE=require \
    DB_NAME="$DB_NAME" APP_PASSWORD="$APP_PASSWORD" APP_OWNER_PASSWORD="$APP_OWNER_PASSWORD" \
    APP_PLATFORM_PASSWORD="$APP_PLATFORM_PASSWORD" \
    run bash "$REPO/api/database/scripts/provision-db.sh"
}

# nginx -t. A dry run with HOST_ROOT runs NGINX_TEST_CMD instead when it is
# set (tests: a stub that reads the files under HOST_ROOT).
nginx_test() {
  if is_dry_run && [ -n "$HOST_ROOT" ] && [ -n "${NGINX_TEST_CMD:-}" ]; then
    printf '[dry-run] nginx -t (NGINX_TEST_CMD)\n'
    HOST_ROOT="$HOST_ROOT" "$NGINX_TEST_CMD"
    return
  fi
  run nginx -t
}

# nginx_apply CHANGED: tests the files written since snapshot_begin, then
# reloads nginx if anything changed. If the test fails, every one of those
# files is put back as it was and nginx is not reloaded (NFR-01: a failed
# run never leaves nginx with a config it cannot load).
nginx_apply() {
  if ! nginx_test; then
    snapshot_restore
    snapshot_end
    die "nginx -t failed on the new configuration: the previous files are back and nginx was not reloaded. Fix the templates in deploy/host/templates, then run the nginx step again."
  fi
  snapshot_end
  if [ "$1" != 0 ]; then
    run systemctl reload nginx
  fi
}

NGINX_HTTPS_FILES="/etc/nginx/snippets/app-web-headers.conf /etc/nginx/sites-available/app /etc/nginx/sites-enabled/app"

# Writes the HTTPS site and its headers snippet. Adds to NGINX_CHANGED.
write_nginx_https() {
  local web_names="$1" headers https
  headers="$(render_template "$TEMPLATES/nginx-web-headers.conf.tmpl" API_DOMAIN="$API_DOMAIN")"
  https="$(render_template "$TEMPLATES/nginx-https.conf.tmpl" WEB_SERVER_NAMES="$web_names" API_DOMAIN="$API_DOMAIN" CERT_NAME="$CERT_NAME" DEPLOY_PATH="$DEPLOY_PATH")"
  # shellcheck disable=SC2086 # one path per word
  snapshot_path $NGINX_HTTPS_FILES
  printf '%s\n' "$headers" | write_file /etc/nginx/snippets/app-web-headers.conf 0644 root:root
  NGINX_CHANGED=$((NGINX_CHANGED + FILE_CHANGED))
  printf '%s\n' "$https" | write_file /etc/nginx/sites-available/app 0644 root:root
  NGINX_CHANGED=$((NGINX_CHANGED + FILE_CHANGED))
  symlink /etc/nginx/sites-available/app /etc/nginx/sites-enabled/app
  NGINX_CHANGED=$((NGINX_CHANGED + FILE_CHANGED))
}

step_nginx() {
  need_root nginx
  require_env APP_DOMAIN API_DOMAIN LETSENCRYPT_EMAIL
  local web_names="$APP_DOMAIN${CONSOLE_DOMAIN:+ $CONSOLE_DOMAIN}"
  local all_names="$web_names $API_DOMAIN"
  local live="/etc/letsencrypt/live/$CERT_NAME/fullchain.pem" had_cert=0 http
  log "nginx: $all_names (certificate $CERT_NAME)"
  ensure_dir /var/www/acme 0755 root:root
  if [ -f "$(host_path "$live")" ]; then
    had_cert=1
  fi
  # Every file is written, then tested together: an HTTPS site enabled by an
  # earlier run is rewritten from the current template before nginx -t, so a
  # template change converges in one run (seen on staging, 2026-10-22).
  snapshot_begin
  snapshot_path /etc/nginx/conf.d/tls.conf /etc/nginx/nginx.conf /etc/nginx/sites-available/app-http \
    /etc/nginx/sites-enabled/app-http /etc/nginx/sites-enabled/default
  NGINX_CHANGED=0
  write_file /etc/nginx/conf.d/tls.conf 0644 root:root <"$TEMPLATES/nginx-tls.conf.tmpl"
  NGINX_CHANGED=$((NGINX_CHANGED + FILE_CHANGED))
  # Ubuntu's nginx.conf sets ssl_protocols (TLS 1.0 to 1.3) and
  # ssl_prefer_server_ciphers in the http block. With conf.d/tls.conf the
  # second one stops nginx, and the protocol lists add up, turning TLS 1.0
  # and 1.1 back on. Comment both out there; tls.conf is the only source.
  if grep -Eq '^[[:space:]]*(ssl_protocols|ssl_prefer_server_ciphers)[[:space:]]' "$(host_path /etc/nginx/nginx.conf)" 2>/dev/null; then
    run sed -i -E 's/^([[:space:]]*)(ssl_protocols|ssl_prefer_server_ciphers)([[:space:]])/\1# \2\3/' /etc/nginx/nginx.conf
    NGINX_CHANGED=$((NGINX_CHANGED + 1))
  fi
  http="$(render_template "$TEMPLATES/nginx-http.conf.tmpl" SERVER_NAMES="$all_names")"
  printf '%s\n' "$http" | write_file /etc/nginx/sites-available/app-http 0644 root:root
  NGINX_CHANGED=$((NGINX_CHANGED + FILE_CHANGED))
  symlink /etc/nginx/sites-available/app-http /etc/nginx/sites-enabled/app-http
  NGINX_CHANGED=$((NGINX_CHANGED + FILE_CHANGED))
  # Ubuntu's welcome page: the link goes, the file stays.
  if [ -L "$(host_path /etc/nginx/sites-enabled/default)" ]; then
    run rm /etc/nginx/sites-enabled/default
    NGINX_CHANGED=$((NGINX_CHANGED + 1))
  fi
  if [ "$had_cert" = 1 ]; then
    write_nginx_https "$web_names"
  fi
  nginx_apply "$NGINX_CHANGED"
  run systemctl enable --now nginx

  local domains="" name issued=0
  for name in $all_names; do domains="$domains -d $name"; done
  if [ "$had_cert" = 0 ] || ! probe openssl x509 -in "$live" -noout -text 2>/dev/null | grep -q "DNS:$API_DOMAIN"; then
    # shellcheck disable=SC2086 # one -d per name
    run certbot certonly --webroot -w /var/www/acme --cert-name "$CERT_NAME" --expand \
      -m "$LETSENCRYPT_EMAIL" --agree-tos --no-eff-email -n $domains
    issued=1
  fi
  write_file /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh 0755 root:root <<'EOF'
#!/bin/sh
# ADR 025: pick up a renewed certificate.
systemctl reload nginx
EOF
  if [ ! -f "$(host_path "$live")" ]; then
    is_dry_run || die "No certificate at $live: check that $all_names point at this host, then run the nginx step again."
    note "the HTTPS site is written once the certificate exists"
    return 0
  fi
  # A first certificate: the HTTPS site goes in now. An expanded one: reload
  # so nginx serves it (the site itself was written above).
  if [ "$had_cert" = 0 ] || [ "$issued" = 1 ]; then
    snapshot_begin
    NGINX_CHANGED="$issued"
    write_nginx_https "$web_names"
    nginx_apply "$NGINX_CHANGED"
  fi
}

step_services() {
  need_root services
  [ -n "${HEALTH_ALERT_EMAIL:-}" ] || warn "HEALTH_ALERT_EMAIL is not set: the host watch runs but emails nobody."
  log "systemd: Horizon, the scheduler (every minute) and the host watch (every 5 minutes)"
  install_host_scripts
  write_host_env
  local unit content
  for unit in app-horizon.service app-scheduler.service app-scheduler.timer app-host-watch.service app-host-watch.timer; do
    content="$(render_template "$TEMPLATES/systemd/$unit" DEPLOY_USER="$DEPLOY_USER" DEPLOY_PATH="$DEPLOY_PATH")"
    printf '%s\n' "$content" | write_file "/etc/systemd/system/$unit" 0644 root:root
  done
  systemd_reload
  # Before the first deploy there is no artisan: the units' condition skips them.
  run systemctl enable app-horizon.service
  if [ -f "$(host_path "$DEPLOY_PATH/api/artisan")" ]; then
    run systemctl restart app-horizon.service
  fi
  run systemctl enable --now app-scheduler.timer app-host-watch.timer
}

for step in $STEPS; do
  case "$step" in
    swap) step_swap ;;
    packages) step_packages ;;
    firewall) step_firewall ;;
    fail2ban) step_fail2ban ;;
    upgrades) step_upgrades ;;
    app-dirs) step_app_dirs ;;
    php) step_php ;;
    redis) step_redis ;;
    tools) step_tools ;;
    backups) step_backups ;;
    postgres) step_postgres ;;
    db-roles) step_db_roles ;;
    nginx) step_nginx ;;
    services) step_services ;;
  esac
done
log "Done ($STEPS). Next: docs/deploy-linode.md, \"After provisioning\"."
