#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

pids=()

stop_services() {
  for pid in "${pids[@]}"; do
    kill "$pid" >/dev/null 2>&1 || true
  done

  wait >/dev/null 2>&1 || true
}

start_service() {
  local name="$1"
  shift

  printf 'Menjalankan %s...\n' "$name"
  "$@" &
  pids+=("$!")
}

trap stop_services EXIT INT TERM

start_service "API Laravel http://127.0.0.1:8000" \
  php backend/artisan serve --host=127.0.0.1 --port=8000

start_service "Frontend Vite" \
  pnpm --dir frontend dev

start_service "Queue Redis" \
  php backend/artisan queue:work redis

start_service "Scheduler" \
  php backend/artisan schedule:work

start_service "Reverb WebSocket ws://127.0.0.1:8080" \
  php backend/artisan reverb:start --host=127.0.0.1 --port=8080

printf '\nService internal aktif.\n'
printf 'Buka alamat Vite dari VITE_DEV_HOST/VITE_DEV_PORT, atau gunakan proxy single-port.\n\n'

wait -n "${pids[@]}"
