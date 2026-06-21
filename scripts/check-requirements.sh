#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

failures=0
warnings=0

ok() {
  printf 'OK   %s\n' "$1"
}

warn() {
  warnings=$((warnings + 1))
  printf 'WARN %s\n' "$1"
}

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

has_command() {
  command -v "$1" >/dev/null 2>&1
}

require_command() {
  if has_command "$1"; then
    ok "$1 tersedia"
  else
    fail "$1 belum tersedia"
  fi
}

require_command php
require_command composer
require_command node
require_command pnpm
require_command openssl

if has_command php; then
  php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
    && ok "PHP minimal 8.3 terpenuhi ($(php -r 'echo PHP_VERSION;'))" \
    || fail "PHP minimal 8.3 belum terpenuhi ($(php -r 'echo PHP_VERSION;'))"

  required_extensions=(
    bcmath
    ctype
    curl
    dom
    fileinfo
    gd
    intl
    mbstring
    openssl
    redis
    tokenizer
    xml
    zip
  )

  for extension in "${required_extensions[@]}"; do
    php -m | grep -qi "^${extension}$" \
      && ok "Ekstensi PHP ${extension} aktif" \
      || fail "Ekstensi PHP ${extension} belum aktif"
  done

  if php -m | grep -qi '^pdo_pgsql$' || php -m | grep -qi '^pdo_mysql$'; then
    ok "Driver database PHP tersedia (pdo_pgsql atau pdo_mysql)"
  else
    fail "Aktifkan pdo_pgsql untuk PostgreSQL atau pdo_mysql untuk MariaDB/MySQL"
  fi
fi

if has_command node; then
  node -e 'const major = Number(process.versions.node.split(".")[0]); process.exit(major >= 24 ? 0 : 1)' \
    && ok "Node.js minimal 24 terpenuhi ($(node -v))" \
    || fail "Node.js minimal 24 belum terpenuhi ($(node -v))"
fi

if has_command redis-cli; then
  redis-cli ping >/dev/null 2>&1 \
    && ok "Redis merespons PING" \
    || warn "Redis belum merespons PING. Pastikan Redis berjalan sebelum menjalankan aplikasi."
else
  warn "redis-cli tidak tersedia. Redis tetap wajib berjalan pada runtime."
fi

if has_command pg_isready; then
  pg_isready -q \
    && ok "PostgreSQL lokal terdeteksi" \
    || warn "PostgreSQL lokal belum terdeteksi. Abaikan jika memakai MariaDB/MySQL atau database remote."
fi

if has_command mysqladmin; then
  mysqladmin ping --silent >/dev/null 2>&1 \
    && ok "MariaDB/MySQL lokal terdeteksi" \
    || warn "MariaDB/MySQL lokal belum terdeteksi. Abaikan jika memakai PostgreSQL atau database remote."
fi

[[ -f .env ]] && ok ".env root tersedia" || warn ".env root belum ada. Jalankan pnpm setup."
[[ -f composer.lock || -f backend/composer.lock ]] && ok "Composer lockfile tersedia" || warn "composer.lock belum terlihat"
[[ -f pnpm-lock.yaml ]] && ok "pnpm-lock.yaml tersedia" || warn "pnpm-lock.yaml belum terlihat"

for directory in backend/storage backend/bootstrap/cache; do
  if [[ -d "$directory" && -w "$directory" ]]; then
    ok "$directory bisa ditulis"
  else
    fail "$directory harus ada dan bisa ditulis"
  fi
done

printf '\nRingkasan: %d gagal, %d peringatan.\n' "$failures" "$warnings"

if (( failures > 0 )); then
  exit 1
fi
