#!/usr/bin/env bash
set -euo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/tppkk-absensi}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

[[ -f "$ENV_FILE" ]] || { echo ".env root tidak ditemukan."; exit 1; }

env_value() {
  local key="$1"
  local default_value="${2:-}"
  php -r '
    [$file, $key, $default] = array_slice($argv, 1);
    $value = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
      $line = trim($line);
      if ($line === "" || str_starts_with($line, "#") || ! str_contains($line, "=")) continue;
      [$name, $raw] = explode("=", $line, 2);
      if (trim($name) !== $key) continue;
      $raw = trim($raw);
      if (strlen($raw) >= 2 && (($raw[0] === chr(34) && substr($raw, -1) === chr(34)) || ($raw[0] === chr(39) && substr($raw, -1) === chr(39)))) {
        $raw = substr($raw, 1, -1);
      }
      $value = $raw;
    }
    echo $value === null || $value === "" ? $default : $value;
  ' "$ENV_FILE" "$key" "$default_value"
}

DB_CONNECTION="$(env_value DB_CONNECTION mariadb)"
[[ "$DB_CONNECTION" == "mariadb" || "$DB_CONNECTION" == "mysql" ]] || {
  echo "Backup otomatis ini hanya untuk MariaDB/MySQL."
  exit 1
}

DB_HOST="$(env_value DB_HOST 127.0.0.1)"
DB_PORT="$(env_value DB_PORT 3306)"
DB_DATABASE="$(env_value DB_DATABASE tppkk_absensi)"
DB_USERNAME="$(env_value DB_USERNAME tppkk_app)"
DB_PASSWORD="$(env_value DB_PASSWORD)"

mkdir -p "$BACKUP_DIR"
client_config="$(mktemp)"
trap 'rm -f "$client_config"' EXIT
cat > "$client_config" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USERNAME
password=$DB_PASSWORD
EOF

timestamp="$(date +%Y%m%d-%H%M%S)"
target="$BACKUP_DIR/${DB_DATABASE}-${timestamp}.sql.gz"
mariadb-dump --defaults-extra-file="$client_config" --single-transaction --quick --routines --events --triggers "$DB_DATABASE" | gzip -9 > "$target"
find "$BACKUP_DIR" -type f -name "${DB_DATABASE}-*.sql.gz" -mtime "+$RETENTION_DAYS" -delete

echo "Backup selesai: $target"
