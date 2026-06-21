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

if [[ "${RESTART_SERVICES:-false}" == "true" ]]; then
  sudo systemctl restart tppkk-queue tppkk-reverb
  sudo systemctl reload apache2
fi

restore_application
maintenance_enabled=false
trap - EXIT

echo "Deployment aplikasi selesai."
