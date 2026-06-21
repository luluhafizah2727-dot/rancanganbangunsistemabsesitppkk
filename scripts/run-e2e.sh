#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR/frontend"

pnpm preview >/tmp/tppkk-vite-preview.log 2>&1 &
preview_pid=$!

cleanup() {
  kill "$preview_pid" >/dev/null 2>&1 || true
  wait "$preview_pid" >/dev/null 2>&1 || true
  rm -f /tmp/tppkk-vite-preview.log
}
trap cleanup EXIT INT TERM

for _ in $(seq 1 50); do
  if ss -ltn | grep -q '127.0.0.1:4173'; then
    pnpm exec playwright test "$@"
    exit $?
  fi
  sleep 0.1
done

cat /tmp/tppkk-vite-preview.log
echo "Vite preview tidak aktif pada port 4173."
exit 1
