#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

[[ -f .env ]] || cp .env.example .env

for command_name in php composer pnpm openssl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "Perintah $command_name belum tersedia. Periksa bagian Kebutuhan di README.md."
    exit 1
  fi
done

composer install --working-dir=backend
pnpm install --frozen-lockfile

if grep -q '^APP_KEY=$' .env; then
  php backend/artisan key:generate
fi

env_value() {
  local file="$1"
  local key="$2"
  sed -n "s/^${key}=//p" "$file" | tail -n 1
}

REVERB_ID="$(env_value .env REVERB_APP_ID)"
REVERB_KEY="$(env_value .env REVERB_APP_KEY)"
REVERB_SECRET="$(env_value .env REVERB_APP_SECRET)"
[[ -n "$REVERB_ID" ]] || REVERB_ID="$(openssl rand -hex 4)"
[[ -n "$REVERB_KEY" ]] || REVERB_KEY="$(openssl rand -hex 16)"
[[ -n "$REVERB_SECRET" ]] || REVERB_SECRET="$(openssl rand -hex 32)"

set_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  php -r '
    [$file, $key, $value] = array_slice($argv, 1);
    $content = file_get_contents($file);
    $line = $key."=".$value;
    $pattern = "/^".preg_quote($key, "/")."=.*$/m";
    $content = preg_match($pattern, $content)
      ? preg_replace($pattern, $line, $content)
      : rtrim($content).PHP_EOL.$line.PHP_EOL;
    file_put_contents($file, $content);
  ' "$file" "$key" "$value"
}

set_env_value .env REVERB_APP_ID "$REVERB_ID"
set_env_value .env REVERB_APP_KEY "$REVERB_KEY"
set_env_value .env REVERB_APP_SECRET "$REVERB_SECRET"
set_env_value .env VITE_REVERB_APP_KEY "$REVERB_KEY"

php backend/artisan storage:link >/dev/null 2>&1 || true

echo
echo "Dependency dan key aplikasi sudah siap."
echo "1. Isi koneksi database dan Redis di .env root."
echo "2. Jalankan: pnpm setup:database"
echo "3. Jalankan layanan sesuai README.md."
