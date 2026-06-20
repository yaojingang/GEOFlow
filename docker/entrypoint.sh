#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Docker 环境变量优先级高于 .env。空值或无效 APP_KEY 会覆盖 .env 中的有效密钥，
# 并导致 composer 脚本或 artisan 首次启动失败，因此尽早移除无效环境变量。
if [ -z "${APP_KEY:-}" ] || ! printf '%s' "${APP_KEY:-}" | grep -q '^base64:'; then
  unset APP_KEY
fi

COMPOSER_NEED_POST_INSTALL=false
COMPOSER_ON_START="${COMPOSER_ON_START:-true}"
RUN_COMPOSER=false
if [ ! -f vendor/autoload.php ]; then
  RUN_COMPOSER=true
elif [ "${COMPOSER_ON_START}" = "true" ]; then
  RUN_COMPOSER=true
fi

if [ "${RUN_COMPOSER}" = "true" ]; then
  COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}"
  export COMPOSER_HOME
  mkdir -p "${COMPOSER_HOME}"
  if [ -n "${COMPOSER_PACKAGIST_MIRROR:-}" ]; then
    if ! composer config -g repo.packagist composer "${COMPOSER_PACKAGIST_MIRROR}"; then
      echo "[entrypoint] warning: failed to configure composer mirror, continue with default source"
    fi
  fi
  echo "[entrypoint] composer install (COMPOSER_ON_START=${COMPOSER_ON_START}, vendor missing=$([ ! -f vendor/autoload.php ] && echo yes || echo no))"
  # 无有效 APP_KEY 时 composer 脚本会调 artisan（package:discover），易失败且留不下 vendor/autoload.php
  if grep -Eq '^APP_KEY=base64:' .env 2>/dev/null; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
  else
    composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader
    COMPOSER_NEED_POST_INSTALL=true
  fi
fi

# .env 为可写挂载时，无密钥则自动生成（宿主机可无 PHP）。
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "[entrypoint] php artisan key:generate --force"
  php artisan key:generate --force --no-interaction
fi

if [ "${COMPOSER_NEED_POST_INSTALL}" = "true" ]; then
  composer dump-autoload --optimize --no-interaction
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

    echo "[entrypoint] fixing storage permissions for ${RUNTIME_USER}:${RUNTIME_GROUP}"
    chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache
    find storage bootstrap/cache -type d -exec chmod 775 {} \;
    find storage bootstrap/cache -type f -exec chmod 664 {} \;
  else
    echo "[entrypoint] skip permission fix: container is not running as root"
  fi
fi

if [ ! -e public/storage ]; then
  php artisan storage:link --force --no-interaction
fi

run_geoflow_install() {
  echo "[entrypoint] php artisan geoflow:install"
  php artisan geoflow:install --no-interaction
}

if [ "${DB_CONNECTION:-}" = "pgsql" ]; then
  DB_HOST_VALUE="${DB_HOST:-postgres}"
  DB_PORT_VALUE="${DB_PORT:-5432}"
  DB_USER_VALUE="${DB_USERNAME:-postgres}"
  DB_NAME_VALUE="${DB_DATABASE:-postgres}"

  echo "[entrypoint] waiting for postgres at ${DB_HOST_VALUE}:${DB_PORT_VALUE}"
  until pg_isready -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" -d "${DB_NAME_VALUE}" >/dev/null 2>&1; do
    sleep 2
  done
fi

INIT_RAN_MIGRATE=false

# 仅首次初始化（compose init 服务）：迁移可重复执行，安装填充由 geoflow:install 判断空库后只跑一次
if [ "${AUTO_INIT_ONCE:-false}" = "true" ]; then
  echo "[entrypoint] init service: migrate + geoflow:install"
  php artisan migrate --force --no-interaction
  INIT_RAN_MIGRATE=true
  run_geoflow_install
fi

if [ "${AUTO_INSTALL_ONCE:-false}" = "true" ]; then
  run_geoflow_install
fi

# 每次容器启动执行迁移（拉代码/换新镜像后默认需要；设为 false 可关闭）
if [ "${AUTO_MIGRATE:-true}" = "true" ] && [ "${INIT_RAN_MIGRATE}" != "true" ]; then
  echo "[entrypoint] php artisan migrate --force"
  php artisan migrate --force --no-interaction
fi

# 缓存 config / events / routes / views（需有效 APP_KEY；设为 false 可跳过，便于本地排障）
if [ "${AUTO_OPTIMIZE:-false}" = "true" ]; then
  if grep -Eq '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "[entrypoint] php artisan optimize"
    php artisan optimize --no-interaction || echo "[entrypoint] warning: php artisan optimize failed, continuing"
  else
    echo "[entrypoint] skip php artisan optimize (no valid APP_KEY in .env)"
  fi
fi

exec "$@"
