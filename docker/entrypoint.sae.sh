#!/usr/bin/env sh
set -eu

cd /var/www/html

ROLE="${SAE_ROLE:-web}"

case "${1:-}" in
  --role)
    [ "$#" -ge 2 ] || { echo "[entrypoint-sae] --role requires a value" >&2; exit 64; }
    ROLE="$2"
    shift 2
    ;;
  --role=*)
    ROLE="${1#--role=}"
    shift
    ;;
  --release)
    ROLE=release
    shift
    ;;
esac

ALLOW_MISSING_ENV_FILE="${GEOFLOW_ALLOW_MISSING_ENV_FILE:-${GEOFLOW_SAE_RUNTIME:-false}}"
if [ ! -f .env ] && [ "$ALLOW_MISSING_ENV_FILE" != "true" ]; then
  echo "[entrypoint-sae] error: inject Laravel environment variables or set GEOFLOW_SAE_RUNTIME=true" >&2
  exit 1
fi

# The platform must provide a stable APP_KEY. A mounted local .env may still
# provide it for an operator-run release, but SAE never generates a key into an
# ephemeral container by default.
if [ -z "${APP_KEY:-}" ] || ! printf '%s' "${APP_KEY:-}" | grep -q '^base64:'; then
  unset APP_KEY
fi
if [ -z "${APP_KEY:-}" ] && { [ ! -f .env ] || ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; }; then
  if [ "${GEOFLOW_SAE_ALLOW_KEY_GENERATION:-false}" = "true" ] && [ -f .env ] && [ -w .env ]; then
    echo "[entrypoint-sae] generating APP_KEY in the explicitly writable .env"
    php artisan key:generate --force --no-interaction
  else
    echo "[entrypoint-sae] error: APP_KEY=base64:... must be injected for SAE" >&2
    exit 1
  fi
fi

prepare_runtime() {
  mkdir -p \
    bootstrap/cache \
    storage/app/public \
    storage/app/public/uploads/images \
    storage/app/tmp \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

  if [ "${AUTO_FIX_STORAGE_PERMISSIONS:-false}" = "true" ] && [ "$(id -u)" = "0" ]; then
    RUNTIME_USER="${RUNTIME_USER:-www-data}"
    RUNTIME_GROUP="${RUNTIME_GROUP:-www-data}"
    echo "[entrypoint-sae] fixing storage permissions for ${RUNTIME_USER}:${RUNTIME_GROUP}"
    chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache
    find storage bootstrap/cache -type d -exec chmod 775 {} +
    find storage bootstrap/cache -type f -exec chmod 664 {} +
  fi

  if [ ! -e public/storage ]; then
    php artisan storage:link --force --no-interaction
  fi
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

      echo "[entrypoint-sae] waiting for ${DB_DRIVER_VALUE} at ${DB_HOST_VALUE}:${DB_PORT_VALUE}"
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
      echo "[entrypoint-sae] skipping database wait for sqlite"
      ;;
    '')
      echo "[entrypoint-sae] skipping database wait because DB_CONNECTION is not injected"
      ;;
    *)
      echo "[entrypoint-sae] skipping database wait for unsupported driver: ${DB_DRIVER_VALUE}"
      ;;
  esac
}

run_release() {
  [ "${SAE_RELEASE_CONFIRM:-false}" = "true" ] || {
    echo "[entrypoint-sae] error: set SAE_RELEASE_CONFIRM=true for a one-shot release task" >&2
    exit 64
  }

  wait_for_database

  if [ "$#" -gt 0 ]; then
    exec "$@"
  fi

  if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    echo "[entrypoint-sae] php artisan migrate --force"
    php artisan migrate --force --no-interaction
  fi

  if [ "${AUTO_INSTALL_ONCE:-false}" = "true" ]; then
    echo "[entrypoint-sae] php artisan geoflow:install"
    php artisan geoflow:install --no-interaction
  fi

  if [ "${AUTO_OPTIMIZE:-false}" = "true" ]; then
    echo "[entrypoint-sae] php artisan optimize"
    php artisan optimize --no-interaction
  fi

  if [ "${AUTO_MIGRATE:-false}" != "true" ] \
    && [ "${AUTO_INSTALL_ONCE:-false}" != "true" ] \
    && [ "${AUTO_OPTIMIZE:-false}" != "true" ]; then
    echo "[entrypoint-sae] error: release task has no enabled action" >&2
    exit 64
  fi
}

validate_upstream() {
  UPSTREAM_VALUE="$1"
  case "$UPSTREAM_VALUE" in
    ''|*[!A-Za-z0-9_.:\[\]-]*)
      echo "[entrypoint-sae] invalid upstream address: ${UPSTREAM_VALUE}" >&2
      exit 64
      ;;
  esac
}

render_nginx_config() {
  command -v envsubst >/dev/null 2>&1 || {
    echo "[entrypoint-sae] error: envsubst is required by the SAE Web image" >&2
    exit 1
  }

  GEOFLOW_NGINX_PRIMARY_HOST="${GEOFLOW_NGINX_PRIMARY_HOST:-localhost}"
  GEOFLOW_NGINX_PRIMARY_ALIASES="${GEOFLOW_NGINX_PRIMARY_ALIASES:-}"
  GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN="${GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN:-invalid}"
  GEOFLOW_NGINX_PUBLIC_SCHEME="${GEOFLOW_NGINX_PUBLIC_SCHEME:-http}"
  GEOFLOW_NGINX_PUBLIC_PORT="${GEOFLOW_NGINX_PUBLIC_PORT:-80}"
  GEOFLOW_PHP_FPM_UPSTREAM="${GEOFLOW_PHP_FPM_UPSTREAM:-127.0.0.1:9000}"
  GEOFLOW_REVERB_UPSTREAM="${GEOFLOW_REVERB_UPSTREAM:-127.0.0.1:18080}"
  GEOFLOW_NGINX_RESOLVER="${GEOFLOW_NGINX_RESOLVER:-127.0.0.11}"
  export GEOFLOW_NGINX_PRIMARY_HOST GEOFLOW_NGINX_PRIMARY_ALIASES \
    GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN GEOFLOW_NGINX_PUBLIC_SCHEME \
    GEOFLOW_NGINX_PUBLIC_PORT

  validate_upstream "$GEOFLOW_PHP_FPM_UPSTREAM"
  validate_upstream "$GEOFLOW_REVERB_UPSTREAM"
  validate_upstream "$GEOFLOW_NGINX_RESOLVER"

  TEMPLATE_COPY="$(mktemp)"
  trap 'rm -f "$TEMPLATE_COPY"' EXIT
  sed \
    -e "s|default app:9000;|default ${GEOFLOW_PHP_FPM_UPSTREAM};|" \
    -e "s|default reverb:18080;|default ${GEOFLOW_REVERB_UPSTREAM};|" \
    /etc/nginx/templates/default.conf.template > "$TEMPLATE_COPY"

  envsubst '${GEOFLOW_NGINX_PRIMARY_HOST} ${GEOFLOW_NGINX_PRIMARY_ALIASES} ${GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN} ${GEOFLOW_NGINX_PUBLIC_SCHEME} ${GEOFLOW_NGINX_PUBLIC_PORT}' \
    < "$TEMPLATE_COPY" > /etc/nginx/conf.d/default.conf
  rm -f "$TEMPLATE_COPY"
  trap - EXIT

  sed \
    -e "s|resolver 127.0.0.11 valid=10s ipv6=off;|resolver ${GEOFLOW_NGINX_RESOLVER} valid=10s ipv6=off;|" \
    /etc/nginx/snippets/geoflow-app.conf.template > /etc/nginx/snippets/geoflow-app.conf

  rm -f /etc/nginx/conf.d/default.conf.default
  nginx -t
}

start_web() {
  render_nginx_config

  php-fpm -t
  php-fpm -F &
  PHP_FPM_PID=$!
  nginx -g 'daemon off;' &
  NGINX_PID=$!

  cleanup() {
    kill "$NGINX_PID" "$PHP_FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    wait "$PHP_FPM_PID" 2>/dev/null || true
  }
  trap 'cleanup; exit 143' TERM INT

  while kill -0 "$PHP_FPM_PID" 2>/dev/null && kill -0 "$NGINX_PID" 2>/dev/null; do
    sleep 1
  done

  if ! kill -0 "$PHP_FPM_PID" 2>/dev/null || ! kill -0 "$NGINX_PID" 2>/dev/null; then
    cleanup
    echo "[entrypoint-sae] web process exited unexpectedly" >&2
    exit 1
  fi
}

prepare_runtime

if [ "$ROLE" = "release" ]; then
  run_release "$@"
  exit 0
fi

if [ "${AUTO_WAIT_FOR_DB:-true}" = "true" ]; then
  wait_for_database
fi

if [ "${AUTO_MIGRATE:-false}" = "true" ] \
  || [ "${AUTO_INSTALL_ONCE:-false}" = "true" ] \
  || [ "${AUTO_OPTIMIZE:-false}" = "true" ]; then
  echo "[entrypoint-sae] error: resident SAE roles cannot run release actions" >&2
  exit 64
fi

if [ "$ROLE" = "web" ]; then
  start_web
  exit 0
fi

if [ "$#" -eq 0 ]; then
  case "$ROLE" in
    worker)
      set -- php artisan queue:work redis --queue=system-updates,geoflow,distribution,theme-replication,default --sleep=1 --tries=1 --timeout=930 --memory=128 --max-jobs=100 --max-time=3600
      ;;
    ai-quality-front)
      set -- php artisan geoflow:work-ai-quality front
      ;;
    ai-quality-backfill)
      set -- php artisan geoflow:work-ai-quality backfill
      ;;
    ai-optimization)
      set -- php artisan geoflow:work-ai-optimization
      ;;
    knowledge)
      set -- php artisan queue:work redis --queue=knowledge --sleep=1 --tries=1 --timeout=210 --memory=128 --max-jobs=20 --max-time=1800
      ;;
    scheduler)
      set -- php artisan schedule:work
      ;;
    reverb)
      set -- php artisan reverb:start --host="${REVERB_SERVER_HOST:-0.0.0.0}"
      ;;
    *)
      echo "[entrypoint-sae] error: no command configured for SAE_ROLE=${ROLE}" >&2
      exit 64
      ;;
  esac
fi

exec "$@"
