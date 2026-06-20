#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
  echo "[entrypoint-prod] error: .env is required inside the container"
  exit 1
fi

# Docker 环境变量优先级高于 .env。空值或无效 APP_KEY 会覆盖 .env 中的有效密钥，
# 因此生产入口也需要先移除无效环境变量，再按需生成密钥。
if [ -z "${APP_KEY:-}" ] || ! printf '%s' "${APP_KEY:-}" | grep -q '^base64:'; then
  unset APP_KEY
fi

# .env.prod 为可写挂载时，无密钥则自动生成（宿主机可无 PHP）。
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "[entrypoint-prod] php artisan key:generate --force"
  php artisan key:generate --force --no-interaction
fi

mkdir -p \
  bootstrap/cache \
  storage/app/public \
  storage/app/public/uploads/images \
  storage/app/tmp \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs

if [ "${AUTO_FIX_STORAGE_PERMISSIONS:-true}" = "true" ]; then
  if [ "$(id -u)" = "0" ]; then
    RUNTIME_USER="${RUNTIME_USER:-www-data}"
    RUNTIME_GROUP="${RUNTIME_GROUP:-www-data}"

    echo "[entrypoint-prod] fixing storage permissions for ${RUNTIME_USER}:${RUNTIME_GROUP}"
    chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache
    find storage bootstrap/cache -type d -exec chmod 775 {} \;
    find storage bootstrap/cache -type f -exec chmod 664 {} \;
  else
    echo "[entrypoint-prod] skip permission fix: container is not running as root"
  fi
fi

if [ ! -e public/storage ]; then
  php artisan storage:link --force --no-interaction
fi

run_geoflow_install() {
  echo "[entrypoint-prod] php artisan geoflow:install"
  php artisan geoflow:install --no-interaction
}

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

if [ "${AUTO_INSTALL_ONCE:-false}" = "true" ]; then
  run_geoflow_install
fi

if [ "${AUTO_OPTIMIZE:-true}" = "true" ]; then
  echo "[entrypoint-prod] php artisan optimize"
  php artisan optimize --no-interaction
fi

exec "$@"
