# shellcheck shell=bash
# shellcheck disable=SC2034 # the settings below are read by the scripts that source this file
# Shared helpers for the single-host scripts (ADR 025, NFR-01, NFR-07).
# Sourced, never run. Written for bash 3.2 as well as 5.x, so the tests and
# the local rehearsal run on a developer's Mac too.
#
# DRY_RUN=1   print every command instead of running it, and write no file
#             outside HOST_ROOT
# HOST_ROOT   (with DRY_RUN=1 only) a scratch directory: files the scripts
#             would write under / are written under it instead, so tests can
#             read them. Ownership is not changed there.
#
# No helper ever prints a file's content or a variable's value, so secrets
# taken from the environment never reach the terminal or the journal.

# The pinned server tool (not an app dependency). The checksum is the release's
# published SHA-256, cross-checked with GitHub's asset digest on 2026-10-22.
# To upgrade: change the version and the checksum together, from the
# release's own checksum file, and rehearse the restore drill.
WALG_VERSION="v3.0.9"
WALG_ASSET="wal-g-pg-24.04-amd64.tar.gz"
WALG_SHA256="444ce216230c254edef803e098a59a1746806da3e0d55671d7b0f0082db14ef4"
# The PostgreSQL apt repository's signing key (apt.postgresql.org, ACCC4CF8).
PGDG_KEY_FINGERPRINT="B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8"

DRY_RUN="${DRY_RUN:-0}"
HOST_ROOT="${HOST_ROOT:-}"

log() { printf '==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf 'Warning: %s\n' "$*" >&2; }
die() {
  printf 'Error: %s\n' "$*" >&2
  exit 1
}

is_dry_run() { [ "$DRY_RUN" = 1 ]; }

# The path a system file is written to: under HOST_ROOT in a sandboxed dry run.
host_path() { printf '%s%s' "$HOST_ROOT" "$1"; }

# Runs a command, or prints it in a dry run. Arguments are printed quoted,
# so never pass a secret as an argument: give it through the environment.
run() {
  if is_dry_run; then
    printf '[dry-run]'
    printf ' %q' "$@"
    printf '\n'
    return 0
  fi
  "$@"
}

# Runs a command only outside a dry run, with no output in a dry run. For
# reads that a dry run cannot answer (state of a service, a package).
probe() {
  is_dry_run && return 1
  "$@"
}

require_env() {
  local var value
  for var in "$@"; do
    value="${!var:-}"
    [ -n "$value" ] || die "Set $var (see docs/deploy-linode.md, Environment for the provisioning script)."
  done
}

# require_secret VAR MIN_LENGTH: set and long enough; never printed.
require_secret() {
  local var="$1" min="$2" value
  value="${!var:-}"
  if [ "${#value}" -lt "$min" ]; then
    die "Set $var to a generated secret of at least $min characters."
  fi
}

# Secrets must not contain characters that break the files they go into
# (quotes, backslashes, newlines). Generated with openssl rand -hex or
# -base64 they never do.
require_plain_secret() {
  local var="$1" value
  value="${!var:-}"
  case "$value" in
    *\"* | *\'* | *\\* | *$'\n'* | *' '*)
      die "$var contains a quote, backslash, space or newline; generate it with openssl rand -hex 32."
      ;;
  esac
}

# write_file PATH MODE OWNER:GROUP < content
# Writes only when the content differs. Sets FILE_CHANGED=1 when it did (or
# would) change the file, else 0. Never prints the content.
FILE_CHANGED=0
write_file() {
  local path="$1" mode="$2" owner="$3" target tmp
  target="$(host_path "$path")"
  tmp="$(mktemp)"
  cat >"$tmp"
  if [ -f "$target" ] && cmp -s "$tmp" "$target"; then
    rm -f "$tmp"
    FILE_CHANGED=0
    return 0
  fi
  FILE_CHANGED=1
  if is_dry_run && [ -z "$HOST_ROOT" ]; then
    printf '[dry-run] write %s (mode %s, %s)\n' "$path" "$mode" "$owner"
    rm -f "$tmp"
    return 0
  fi
  mkdir -p "$(dirname "$target")"
  if [ -n "$HOST_ROOT" ]; then
    install -m "$mode" "$tmp" "$target"
  else
    install -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$target"
  fi
  rm -f "$tmp"
  note "wrote $path"
}

# ensure_dir PATH MODE OWNER:GROUP
ensure_dir() {
  local path="$1" mode="$2" owner="$3" target
  target="$(host_path "$path")"
  if [ -n "$HOST_ROOT" ]; then
    mkdir -p "$target" && chmod "$mode" "$target"
    return 0
  fi
  run install -d -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$path"
}

# render_template FILE KEY=VALUE... : replaces @KEY@ in FILE with VALUE.
render_template() {
  local file="$1" content pair key value
  shift
  content="$(cat "$file")"
  for pair in "$@"; do
    key="${pair%%=*}"
    value="${pair#*=}"
    content="${content//@${key}@/$value}"
  done
  if printf '%s' "$content" | grep -Eq '@[A-Z][A-Z0-9_]*@'; then
    die "$file has a placeholder with no value: $(printf '%s' "$content" | grep -Eo '@[A-Z][A-Z0-9_]*@' | head -1)"
  fi
  printf '%s\n' "$content"
}

# The epoch now, overridable for tests.
now_epoch() { printf '%s' "${NOW_EPOCH:-$(date -u +%s)}"; }

# date_from_epoch EPOCH FORMAT, on GNU (Ubuntu) and BSD (macOS) date.
date_from_epoch() {
  date -u -d "@$1" "$2" 2>/dev/null || date -u -r "$1" "$2"
}

# sha256_of FILE
sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

# Runs a command as another OS user (postgres, the deploy user), or directly
# when that is already who we are. Keeps the environment (secrets travel in
# it, never in arguments).
as_user() {
  local user="$1"
  shift
  if [ "$(id -un)" = "$user" ]; then
    run "$@"
  else
    run runuser -u "$user" -- "$@"
  fi
}

# append_once FILE LINE: appends LINE unless FILE already has it (whole
# line). Sets FILE_CHANGED.
append_once() {
  local path="$1" line="$2" target
  target="$(host_path "$path")"
  FILE_CHANGED=0
  if grep -qsxF -- "$line" "$target"; then
    return 0
  fi
  FILE_CHANGED=1
  if is_dry_run && [ -z "$HOST_ROOT" ]; then
    printf '[dry-run] append to %s: %s\n' "$path" "$line"
    return 0
  fi
  mkdir -p "$(dirname "$target")"
  printf '%s\n' "$line" >>"$target"
  note "appended to $path"
}

# symlink TARGET LINK: makes LINK point at TARGET. Sets FILE_CHANGED.
symlink() {
  local to="$1" link="$2" target
  target="$(host_path "$link")"
  FILE_CHANGED=0
  if [ -L "$target" ] && [ "$(readlink "$target")" = "$to" ]; then
    return 0
  fi
  FILE_CHANGED=1
  if is_dry_run && [ -z "$HOST_ROOT" ]; then
    printf '[dry-run] ln -sfn %s %s\n' "$to" "$link"
    return 0
  fi
  mkdir -p "$(dirname "$target")"
  ln -sfn "$to" "$target"
}
