#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
  echo "[entrypoint-prod] error: .env is required inside the container"
  exit 1
fi

# .env.prod 为可写挂载时，无密钥则自动生成（宿主机可无 PHP）。
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "[entrypoint-prod] php artisan key:generate --force"
  php artisan key:generate --force --no-interaction
fi

mkdir -p \
  bootstrap/cache \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs

if [ ! -e public/storage ]; then
  php artisan storage:link --force --no-interaction
fi

if [ "${AUTO_WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-}" = "pgsql" ]; then
  DB_HOST_VALUE="${DB_HOST:-postgres}"
  DB_PORT_VALUE="${DB_PORT:-5432}"
  DB_USER_VALUE="${DB_USERNAME:-postgres}"
  DB_NAME_VALUE="${DB_DATABASE:-postgres}"

  echo "[entrypoint-prod] waiting for postgres at ${DB_HOST_VALUE}:${DB_PORT_VALUE}"
  until pg_isready -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" -d "${DB_NAME_VALUE}" >/dev/null 2>&1; do
    sleep 2
  done
fi

if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
  echo "[entrypoint-prod] php artisan migrate --force"
  php artisan migrate --force --no-interaction
fi

if [ "${AUTO_OPTIMIZE:-true}" = "true" ]; then
  echo "[entrypoint-prod] php artisan optimize"
  php artisan optimize --no-interaction
fi

exec "$@"
