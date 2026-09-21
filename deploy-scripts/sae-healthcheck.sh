#!/usr/bin/env bash
set -Eeuo pipefail

# Run inside an SAE container. It intentionally reads secrets only through the
# process environment and never prints their values.

ROLE="${SAE_ROLE:-web}"

fail() {
  printf '[sae-healthcheck] ERROR: %s\n' "$*" >&2
  exit 1
}

log() {
  printf '[sae-healthcheck] %s\n' "$*"
}

check_http() {
  local url="${SAE_HEALTHCHECK_URL:-http://127.0.0.1:${PORT:-80}/up}"
  local host_header="${SAE_HEALTHCHECK_HOST:-${GEOFLOW_NGINX_PRIMARY_HOST:-}}"
  local curl_args=(-fsS --max-time "${SAE_HEALTHCHECK_TIMEOUT_SECONDS:-10}")

  if [[ -n "$host_header" ]]; then
    curl_args+=(-H "Host: ${host_header}")
  fi

  curl "${curl_args[@]}" "$url" >/dev/null || fail "HTTP health endpoint failed: ${url}"
  log "HTTP health endpoint passed"
}

check_database() {
  [[ "${SAE_HEALTHCHECK_DATABASE:-true}" == "true" ]] || return 0

  local status_args=(migrate:status --no-interaction)
  if [[ "${SAE_HEALTHCHECK_ALLOW_PENDING_MIGRATIONS:-false}" != "true" ]]; then
    status_args+=(--pending=1)
  fi

  php artisan "${status_args[@]}" >/dev/null \
    || fail "Laravel cannot read database migration status or pending migrations remain"
  log "Database health passed"
}

check_redis() {
  [[ "${SAE_HEALTHCHECK_REDIS:-true}" == "true" ]] || return 0

  php -r '
if (!class_exists("Redis")) {
    fwrite(STDERR, "phpredis extension is not loaded\n");
    exit(1);
}
$redis = new Redis();
$host = getenv("REDIS_HOST") ?: "127.0.0.1";
$port = (int) (getenv("REDIS_PORT") ?: 6379);
$password = getenv("REDIS_PASSWORD") ?: "";
$username = getenv("REDIS_USERNAME") ?: "";
try {
    $redis->connect($host, $port, 3.0);
    if ($password !== "") {
        $redis->auth($username !== "" ? [$username, $password] : $password);
    }
    $reply = $redis->ping();
    if ($reply !== true && $reply !== "PONG" && $reply !== "+PONG") {
        exit(1);
    }
} catch (Throwable $exception) {
    exit(1);
}
' >/dev/null 2>&1 || fail "Redis PING failed"
  log "Redis health passed"
}

process_contains() {
  local needle="$1"
  local cmdline

  for cmdline in /proc/[0-9]*/cmdline; do
    [[ -r "$cmdline" ]] || continue
    if tr '\0' ' ' < "$cmdline" | grep -F -- "$needle" >/dev/null 2>&1; then
      return 0
    fi
  done

  fail "process is not running: ${needle}"
}

check_processes() {
  [[ "${SAE_HEALTHCHECK_CHECK_PROCESSES:-true}" == "true" ]] || return 0

  case "$ROLE" in
    web)
      process_contains nginx
      process_contains php-fpm
      ;;
    worker)
      process_contains 'queue:work'
      ;;
    ai-quality-front|ai-quality-backfill)
      process_contains 'geoflow:work-ai-quality'
      ;;
    ai-optimization)
      process_contains 'geoflow:work-ai-optimization'
      ;;
    knowledge)
      process_contains 'queue:work'
      ;;
    scheduler)
      process_contains 'schedule:work'
      ;;
    reverb)
      process_contains 'reverb:start'
      ;;
    release)
      :
      ;;
    *)
      fail "unsupported SAE_ROLE=${ROLE}"
      ;;
  esac
}

case "$ROLE" in
  web)
    check_http
    check_database
    check_redis
    check_processes
    ;;
  release)
    check_database
    check_redis
    ;;
  *)
    check_database
    check_redis
    check_processes
    ;;
esac

log "health check passed for role=${ROLE}"
