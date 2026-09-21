#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ] \
  && [ "${GEOFLOW_ALLOW_MISSING_ENV_FILE:-${GEOFLOW_SAE_RUNTIME:-false}}" != "true" ]; then
  echo "[entrypoint-prod] error: .env is required unless GEOFLOW_ALLOW_MISSING_ENV_FILE=true"
  exit 1
fi

# A release task is the only SAE role allowed to run migration/install/bootstrap
# work. The release wrapper uses the same runtime preparation as this entrypoint,
# but is deliberately invoked as a separate one-shot process.
if [ "${GEOFLOW_SAE_RUNTIME:-false}" = "true" ] \
  && [ "${SAE_ROLE:-}" = "release" ] \
  && [ -x /usr/local/bin/geoflow-entrypoint-sae-release ]; then
  exec /usr/local/bin/geoflow-entrypoint-sae-release "$@"
fi

if [ "${GEOFLOW_SAE_RUNTIME:-false}" = "true" ] \
  && [ "${SAE_ROLE:-}" != "release" ] \
  && { [ "${AUTO_MIGRATE:-false}" = "true" ] \
    || [ "${AUTO_INSTALL_ONCE:-false}" = "true" ] \
    || [ "${AUTO_OPTIMIZE:-false}" = "true" ]; }; then
  echo "[entrypoint-prod] error: SAE resident roles must not enable AUTO_MIGRATE, AUTO_INSTALL_ONCE, or AUTO_OPTIMIZE; run the release entrypoint instead"
  exit 1
fi

# Docker 环境变量优先级高于 .env。空值或无效 APP_KEY 会覆盖 .env 中的有效密钥，
# 因此生产入口也需要先移除无效环境变量，再按需生成密钥。
if [ -z "${APP_KEY:-}" ] || ! printf '%s' "${APP_KEY:-}" | grep -q '^base64:'; then
  unset APP_KEY
fi

# 优先使用已注入的密钥；缺少密钥时才读取或初始化可写的 .env.prod。
if [ -z "${APP_KEY:-}" ] && [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "[entrypoint-prod] php artisan key:generate --force"
  php artisan key:generate --force --no-interaction
fi

if [ -z "${APP_KEY:-}" ] && [ ! -f .env ]; then
  echo "[entrypoint-prod] error: APP_KEY=base64:... must be injected when .env is not mounted"
  exit 1
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
    find storage bootstrap/cache -type d -exec chmod 775 {} +
    find storage bootstrap/cache -type f -exec chmod 664 {} +
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

wait_for_database() {
  DB_DRIVER_VALUE="${DB_CONNECTION:-}"

  case "$DB_DRIVER_VALUE" in
    pgsql|mysql)
      DB_HOST_VALUE="${DB_HOST:-}"
      DB_PORT_VALUE="${DB_PORT:-}"

      if [ -z "$DB_HOST_VALUE" ]; then
        if [ "$DB_DRIVER_VALUE" = "pgsql" ]; then
          DB_HOST_VALUE=postgres
        else
          DB_HOST_VALUE=mysql
        fi
      fi
      if [ -z "$DB_PORT_VALUE" ]; then
        if [ "$DB_DRIVER_VALUE" = "pgsql" ]; then
          DB_PORT_VALUE=5432
        else
          DB_PORT_VALUE=3306
        fi
      fi

      echo "[entrypoint-prod] waiting for ${DB_DRIVER_VALUE} at ${DB_HOST_VALUE}:${DB_PORT_VALUE}"
      until php -r '
$driver = getenv("DB_CONNECTION") ?: "pgsql";
$host = getenv("DB_HOST") ?: ($driver === "pgsql" ? "postgres" : "mysql");
$port = getenv("DB_PORT") ?: ($driver === "pgsql" ? "5432" : "3306");
$database = getenv("DB_DATABASE") ?: "";
$username = getenv("DB_USERNAME") ?: "";
$password = getenv("DB_PASSWORD") ?: "";
$dsn = $driver === "pgsql"
    ? "pgsql:host={$host};port={$port};dbname={$database}"
    : "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query("SELECT 1");
} catch (Throwable $exception) {
    exit(1);
}
' >/dev/null 2>&1; do
        sleep 2
      done
      ;;
    sqlite)
      echo "[entrypoint-prod] skipping database wait for sqlite"
      ;;
    '')
      echo "[entrypoint-prod] skipping database wait because DB_CONNECTION is not injected"
      ;;
    *)
      echo "[entrypoint-prod] skipping database wait for unsupported driver: ${DB_DRIVER_VALUE}"
      ;;
  esac
}

if [ "${AUTO_WAIT_FOR_DB:-true}" = "true" ]; then
  wait_for_database
fi

if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
  echo "[entrypoint-prod] php artisan migrate --force"
  php artisan migrate --force --no-interaction
fi

if [ "${AUTO_INSTALL_ONCE:-false}" = "true" ]; then
  run_geoflow_install
fi

if [ "${AUTO_OPTIMIZE:-false}" = "true" ]; then
  echo "[entrypoint-prod] php artisan optimize"
  php artisan optimize --no-interaction
fi

exec "$@"
