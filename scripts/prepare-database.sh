#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo ".env root belum ada. Jalankan pnpm setup terlebih dahulu."
  exit 1
fi

env_value() {
  local key="$1"
  local default_value="${2:-}"
  php -r '
    [$file, $key, $default] = array_slice($argv, 1);
    $value = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
      $line = trim($line);
      if ($line === "" || str_starts_with($line, "#") || ! str_contains($line, "=")) {
        continue;
      }
      [$name, $raw] = explode("=", $line, 2);
      if (trim($name) !== $key) {
        continue;
      }
      $raw = trim($raw);
      $first = $raw[0] ?? "";
      $last = substr($raw, -1);
      if (($first === chr(34) && $last === chr(34)) || ($first === chr(39) && $last === chr(39))) {
        $raw = substr($raw, 1, -1);
      }
      $value = $raw;
    }
    echo $value === null || $value === "" ? $default : $value;
  ' "$ENV_FILE" "$key" "$default_value"
}

sql_literal() {
  php -r 'echo "'\''".str_replace("'\''", "'\'''\''", $argv[1])."'\''";' "$1"
}

pgsql_identifier() {
  php -r 'echo "\"".str_replace("\"", "\"\"", $argv[1])."\"";' "$1"
}

mysql_identifier() {
  php -r 'echo "`".str_replace("`", "``", $argv[1])."`";' "$1"
}

mysql_literal() {
  php -r 'echo "'\''".str_replace(["\\\\", "'\''"], ["\\\\\\\\", "'\'''\''"], $argv[1])."'\''";' "$1"
}

DB_CONNECTION="$(env_value DB_CONNECTION mariadb)"
DB_HOST="$(env_value DB_HOST 127.0.0.1)"
DB_PORT="$(env_value DB_PORT "$([[ "$DB_CONNECTION" == mysql || "$DB_CONNECTION" == mariadb ]] && echo 3306 || echo 5432)")"
DB_DATABASE="$(env_value DB_DATABASE tppkk_absensi)"
DB_USERNAME="$(env_value DB_USERNAME tppkk_app)"
DB_PASSWORD="$(env_value DB_PASSWORD "")"
DB_CREATE_DATABASE="$(env_value DB_CREATE_DATABASE true)"
DB_ADMIN_USE_SUDO="$(env_value DB_ADMIN_USE_SUDO false)"

run_sudo() {
  if [[ -n "${SUDO_PASSWORD:-}" ]]; then
    printf '%s\n' "$SUDO_PASSWORD" | sudo -S -p '' -v
  fi

  sudo "$@"
}

if [[ "$DB_DATABASE" == "" || "$DB_USERNAME" == "" ]]; then
  echo "DB_DATABASE dan DB_USERNAME wajib diisi di .env root."
  exit 1
fi

try_pgsql_app_connection() {
  PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc 'select 1' >/dev/null 2>&1
}

try_mysql_app_connection() {
  MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" -e 'select 1' >/dev/null 2>&1
}

prepare_pgsql() {
  command -v psql >/dev/null 2>&1 || {
    echo "psql belum tersedia. Install postgresql-client terlebih dahulu."
    exit 1
  }

  if try_pgsql_app_connection; then
    echo "Database PostgreSQL sudah bisa diakses oleh user aplikasi."
    return
  fi

  if [[ "$DB_CREATE_DATABASE" != "true" ]]; then
    echo "Database belum bisa diakses dan DB_CREATE_DATABASE=false."
    exit 1
  fi

  local admin_user admin_password admin_database db_exists role_exists db_ident user_ident password_literal database_literal username_literal
  admin_user="$(env_value DB_ADMIN_USERNAME postgres)"
  admin_password="$(env_value DB_ADMIN_PASSWORD "")"
  admin_database="$(env_value DB_ADMIN_DATABASE postgres)"
  db_ident="$(pgsql_identifier "$DB_DATABASE")"
  user_ident="$(pgsql_identifier "$DB_USERNAME")"
  password_literal="$(sql_literal "$DB_PASSWORD")"
  database_literal="$(sql_literal "$DB_DATABASE")"
  username_literal="$(sql_literal "$DB_USERNAME")"

  echo "Menyiapkan PostgreSQL database ${DB_DATABASE}..."

  pgsql_admin_psql() {
    if [[ "$DB_ADMIN_USE_SUDO" == "true" ]]; then
      run_sudo -u "$admin_user" psql -p "$DB_PORT" -d "$admin_database" "$@"
    else
      PGPASSWORD="$admin_password" psql -h "$DB_HOST" -p "$DB_PORT" -U "$admin_user" -d "$admin_database" "$@"
    fi
  }

  role_exists="$(pgsql_admin_psql -tAc "select 1 from pg_roles where rolname = ${username_literal}" 2>/dev/null || true)"
  if [[ "$role_exists" != "1" ]]; then
    pgsql_admin_psql -v ON_ERROR_STOP=1 -c "create role ${user_ident} login password ${password_literal};"
  elif [[ "$DB_PASSWORD" != "" ]]; then
    pgsql_admin_psql -v ON_ERROR_STOP=1 -c "alter role ${user_ident} with password ${password_literal};"
  fi

  db_exists="$(pgsql_admin_psql -tAc "select 1 from pg_database where datname = ${database_literal}" 2>/dev/null || true)"
  if [[ "$db_exists" != "1" ]]; then
    pgsql_admin_psql -v ON_ERROR_STOP=1 -c "create database ${db_ident} owner ${user_ident};"
  fi

  pgsql_admin_psql -v ON_ERROR_STOP=1 -c "grant all privileges on database ${db_ident} to ${user_ident};" >/dev/null
  try_pgsql_app_connection || {
    echo "Database sudah disiapkan, tetapi user aplikasi masih belum bisa terhubung. Periksa DB_HOST, DB_PORT, DB_USERNAME, dan DB_PASSWORD."
    exit 1
  }
}

prepare_mysql() {
  command -v mysql >/dev/null 2>&1 || {
    echo "mysql client belum tersedia. Install mariadb-client atau mysql-client terlebih dahulu."
    exit 1
  }

  if try_mysql_app_connection; then
    echo "Database MariaDB/MySQL sudah bisa diakses oleh user aplikasi."
    return
  fi

  if [[ "$DB_CREATE_DATABASE" != "true" ]]; then
    echo "Database belum bisa diakses dan DB_CREATE_DATABASE=false."
    exit 1
  fi

  local admin_user admin_password db_ident username_literal password_literal
  admin_user="$(env_value DB_ADMIN_USERNAME root)"
  admin_password="$(env_value DB_ADMIN_PASSWORD "")"
  db_ident="$(mysql_identifier "$DB_DATABASE")"
  username_literal="$(mysql_literal "$DB_USERNAME")"
  password_literal="$(mysql_literal "$DB_PASSWORD")"

  echo "Menyiapkan MariaDB/MySQL database ${DB_DATABASE}..."

  mysql_admin_client() {
    if [[ "$DB_ADMIN_USE_SUDO" == "true" ]]; then
      run_sudo mysql "$@"
    else
      MYSQL_PWD="$admin_password" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$admin_user" "$@"
    fi
  }

  mysql_admin_client <<SQL
CREATE DATABASE IF NOT EXISTS ${db_ident} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS ${username_literal}@'localhost' IDENTIFIED BY ${password_literal};
CREATE USER IF NOT EXISTS ${username_literal}@'%' IDENTIFIED BY ${password_literal};
GRANT ALL PRIVILEGES ON ${db_ident}.* TO ${username_literal}@'localhost';
GRANT ALL PRIVILEGES ON ${db_ident}.* TO ${username_literal}@'%';
FLUSH PRIVILEGES;
SQL

  try_mysql_app_connection || {
    echo "Database sudah disiapkan, tetapi user aplikasi masih belum bisa terhubung. Periksa DB_HOST, DB_PORT, DB_USERNAME, dan DB_PASSWORD."
    exit 1
  }
}

case "$DB_CONNECTION" in
  pgsql|postgres|postgresql)
    prepare_pgsql
    ;;
  mysql|mariadb)
    prepare_mysql
    ;;
  sqlite)
    mkdir -p "$ROOT_DIR/backend/database"
    touch "$ROOT_DIR/backend/database/database.sqlite"
    echo "SQLite database siap di backend/database/database.sqlite."
    ;;
  *)
    echo "DB_CONNECTION ${DB_CONNECTION} belum didukung oleh setup otomatis."
    exit 1
    ;;
esac

echo "Database siap untuk migrasi dan seed."
