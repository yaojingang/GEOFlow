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
  # Packagist 中国镜像，加速 composer install（见 https://packagist.phpcomposer.com ）
  COMPOSER_PACKAGIST_MIRROR="${COMPOSER_PACKAGIST_MIRROR:-https://packagist.phpcomposer.com}"
  COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}"
  export COMPOSER_HOME
  mkdir -p "${COMPOSER_HOME}"
  if ! composer config -g repo.packagist composer "${COMPOSER_PACKAGIST_MIRROR}"; then
    echo "[entrypoint] warning: failed to configure composer mirror, continue with default source"
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

# 自动初始化 APP_KEY（仅在 .env 里缺失时生成，避免每次重置密钥）
if [ "${AUTO_GENERATE_APP_KEY:-false}" = "true" ]; then
  if ! grep -Eq '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force --no-interaction
  fi
fi

if [ "${COMPOSER_NEED_POST_INSTALL}" = "true" ]; then
  composer dump-autoload --optimize --no-interaction
fi

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
if [ ! -e public/storage ]; then
  php artisan storage:link --force --no-interaction
fi

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

# 仅首次初始化（compose init 服务）：库尚不可连或尚无 migrations 表时 migrate + seed
if [ "${AUTO_INIT_ONCE:-false}" = "true" ]; then
  if php artisan migrate:status --no-interaction >/dev/null 2>&1; then
    echo "[entrypoint] database already initialized, skip init migrate/seed"
  else
    echo "[entrypoint] first startup initialization: migrate + seed"
    php artisan migrate --force --no-interaction
    php artisan db:seed --force --no-interaction
  fi
fi

# 每次容器启动执行迁移（拉代码/换新镜像后默认需要；设为 false 可关闭）
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  echo "[entrypoint] php artisan migrate --force"
  php artisan migrate --force --no-interaction
fi

# 每次启动是否跑 seed（默认关；仅在你明确要重置演示数据时打开）
if [ "${AUTO_SEED:-false}" = "true" ]; then
  echo "[entrypoint] php artisan db:seed --force"
  php artisan db:seed --force --no-interaction
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
