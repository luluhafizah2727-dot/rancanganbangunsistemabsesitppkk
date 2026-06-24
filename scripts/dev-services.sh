#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

pids=()

env_value() {
  local key="$1"
  local default_value="${2:-}"
  local shell_value="${!key-}"
  local file_value=""

  if [[ -n "$shell_value" ]]; then
    printf '%s' "$shell_value"
    return
  fi

  if [[ -f .env ]]; then
    file_value="$(sed -n "s/^${key}=//p" .env | tail -n 1)"
    file_value="${file_value%\"}"
    file_value="${file_value#\"}"
    file_value="${file_value%\'}"
    file_value="${file_value#\'}"
  fi

  printf '%s' "${file_value:-$default_value}"
}

port_in_use() {
  local port="$1"
  ss -H -ltn "( sport = :${port} )" 2>/dev/null | grep -q .
}

require_free_port() {
  local name="$1"
  local port="$2"

  if port_in_use "$port"; then
    echo
    echo "Port ${port} untuk ${name} sudah dipakai."
    echo "Cek pemakai port: ss -ltnp '( sport = :${port} )'"
    if [[ "$name" == "Reverb WebSocket" ]]; then
      echo "Jika port dipakai proses lain/Windows, ubah REVERB_PORT dan VITE_REVERB_PORT di .env ke port kosong, misalnya 8081."
    fi
    exit 1
  fi
}

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

API_HOST="127.0.0.1"
API_PORT="8000"
VITE_DEV_PORT="$(env_value VITE_DEV_PORT 5173)"
REVERB_HOST="$(env_value REVERB_HOST 127.0.0.1)"
REVERB_PORT="$(env_value REVERB_PORT 8080)"

require_free_port "API Laravel" "$API_PORT"
require_free_port "Frontend Vite" "$VITE_DEV_PORT"
require_free_port "Reverb WebSocket" "$REVERB_PORT"

start_service "API Laravel http://${API_HOST}:${API_PORT}" \
  php backend/artisan serve --host="$API_HOST" --port="$API_PORT"

start_service "Frontend Vite" \
  pnpm --dir frontend dev

start_service "Queue Redis" \
  php backend/artisan queue:work redis

start_service "Scheduler" \
  php backend/artisan schedule:work

start_service "Reverb WebSocket ws://${REVERB_HOST}:${REVERB_PORT}" \
  php backend/artisan reverb:start --host="$REVERB_HOST" --port="$REVERB_PORT"

printf '\nService internal aktif.\n'
printf 'Buka alamat Vite dari VITE_DEV_HOST/VITE_DEV_PORT, atau gunakan proxy single-port.\n\n'

wait -n "${pids[@]}"
