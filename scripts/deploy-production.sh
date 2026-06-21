#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -f .env ]]; then
  echo ".env root belum tersedia. Salin .env.example dan isi konfigurasi deployment."
  exit 1
fi

for command_name in php composer pnpm; do
  command -v "$command_name" >/dev/null 2>&1 || {
    echo "Perintah $command_name belum tersedia."
    exit 1
  }
done

maintenance_enabled=false
if php backend/artisan migrate:status >/dev/null 2>&1; then
  php backend/artisan down --retry=30 || true
  maintenance_enabled=true
fi

restore_application() {
  if [[ "$maintenance_enabled" == "true" ]]; then
    php backend/artisan up >/dev/null 2>&1 || true
  fi
}
trap restore_application EXIT

composer_args=(install --working-dir=backend --optimize-autoloader --no-interaction)
if [[ "${INSTALL_DEV_DEPENDENCIES:-false}" != "true" ]]; then
  composer_args+=(--no-dev)
fi
composer "${composer_args[@]}"
pnpm install --frozen-lockfile
pnpm build

php backend/artisan migrate --force
php backend/artisan storage:link >/dev/null 2>&1 || true
php backend/artisan optimize:clear
php backend/artisan optimize
php backend/artisan schedule:interrupt >/dev/null 2>&1 || true

if [[ "${CONFIGURE_PHPMYADMIN_BASIC_AUTH:-false}" == "true" ]]; then
  command -v htpasswd >/dev/null 2>&1 || {
    echo "Perintah htpasswd belum tersedia. Install apache2-utils terlebih dahulu."
    exit 1
  }

  pma_user="${PHPMYADMIN_BASIC_USER:-pmaadmin}"
  pma_secret_file="${PHPMYADMIN_BASIC_PASSWORD_FILE:-/root/tppkk-phpmyadmin-basic-auth-password}"
  pma_htpasswd_file="${PHPMYADMIN_HTPASSWD_FILE:-/etc/apache2/tppkk-phpmyadmin.htpasswd}"

  if [[ ! -f "$pma_secret_file" ]]; then
    sudo install -d -o root -g root -m 700 "$(dirname "$pma_secret_file")"
    openssl rand -base64 24 | sudo tee "$pma_secret_file" >/dev/null
    sudo chmod 600 "$pma_secret_file"
    sudo chown root:root "$pma_secret_file"
  fi

  pma_password="$(sudo cat "$pma_secret_file" | tr -d '\n')"
  pma_tmp="$(mktemp)"
  printf '%s\n' "$pma_password" | htpasswd -iB -c "$pma_tmp" "$pma_user" >/dev/null
  sudo install -o root -g www-data -m 640 "$pma_tmp" "$pma_htpasswd_file"
  rm -f "$pma_tmp"
  echo "Basic Auth phpMyAdmin siap. Username: $pma_user. Password tersimpan di $pma_secret_file."
fi

if [[ "${RESTART_SERVICES:-false}" == "true" ]]; then
  sudo systemctl restart tppkk-queue tppkk-reverb
  sudo systemctl reload apache2
fi

restore_application
maintenance_enabled=false
trap - EXIT

echo "Deployment aplikasi selesai."
