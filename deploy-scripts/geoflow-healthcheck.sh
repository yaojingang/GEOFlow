#!/usr/bin/env bash
set -Eeuo pipefail

# GEOFlow production Docker healthcheck helper.
# Run from the repository root or set GEOFLOW_APP_DIR=/path/to/GEOFlow.

APP_DIR="${GEOFLOW_APP_DIR:-$(pwd)}"

log() {
  printf '\033[1;34m[geoflow-check]\033[0m %s\n' "$*"
}

warn() {
  printf '\033[1;33m[warn]\033[0m %s\n' "$*" >&2
}

fail() {
  printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2
  exit 1
}

detect_docker_command() {
  if docker info >/dev/null 2>&1; then
    DOCKER_CMD=(docker)
  elif command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
    DOCKER_CMD=(sudo docker)
  else
    fail "Docker is not available to this user."
  fi

  if ! "${DOCKER_CMD[@]}" compose version >/dev/null 2>&1; then
    fail "Docker Compose v2 plugin is required."
  fi
}

read_env_value() {
  local key="$1"
  local file="${APP_DIR}/.env.prod"
  grep "^${key}=" "$file" 2>/dev/null | tail -n1 | cut -d= -f2-
}

check_http() {
  local web_port="$1"
  local url="http://127.0.0.1:${web_port}/up"

  if command -v curl >/dev/null 2>&1; then
    if curl -fsS --max-time 10 "$url" >/dev/null; then
      log "HTTP health endpoint passed: ${url}"
    else
      fail "HTTP health endpoint failed: ${url}. Check Nginx and external proxy configuration."
    fi
  else
    fail "curl is required for the HTTP health endpoint check."
  fi
}

main() {
  [ -d "$APP_DIR" ] || fail "APP_DIR does not exist: ${APP_DIR}"
  [ -f "${APP_DIR}/docker-compose.prod.yml" ] || fail "docker-compose.prod.yml not found in ${APP_DIR}"
  [ -f "${APP_DIR}/.env.prod" ] || fail ".env.prod not found in ${APP_DIR}"

  detect_docker_command
  cd "$APP_DIR"

  COMPOSE=("${DOCKER_CMD[@]}" compose --env-file .env.prod -f docker-compose.prod.yml)
  local web_port
  web_port="$(read_env_value WEB_PORT)"
  web_port="${web_port:-18080}"

  log "Checking container status."
  "${COMPOSE[@]}" ps

  if "${DOCKER_CMD[@]}" container inspect geoflow-system-update-queue-prod >/dev/null 2>&1; then
    fail "Retired system update worker is still present: geoflow-system-update-queue-prod"
  fi

  local required=(postgres redis app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb)
  local service missing_services=()
  for service in "${required[@]}"; do
    if "${COMPOSE[@]}" ps --status running --services | grep -qx "$service"; then
      log "Service running: ${service}"
    else
      warn "Service is not running: ${service}"
      missing_services+=("$service")
    fi
  done
  if [ "${#missing_services[@]}" -gt 0 ]; then
    fail "Required services are not running: ${missing_services[*]}"
  fi

  local expected_quality_replicas quality_running_count
  expected_quality_replicas="$(read_env_value AI_QUALITY_QUEUE_REPLICAS)"
  expected_quality_replicas="${expected_quality_replicas:-2}"
  if ! [[ "$expected_quality_replicas" =~ ^[1-9][0-9]*$ ]]; then
    fail "AI_QUALITY_QUEUE_REPLICAS must be a positive integer."
  fi
  quality_running_count="$("${COMPOSE[@]}" ps --status running -q ai-quality-queue | awk 'NF { count++ } END { print count + 0 }')"
  if [ "$quality_running_count" -lt "$expected_quality_replicas" ]; then
    fail "AI quality worker capacity is below target: ${quality_running_count}/${expected_quality_replicas} replicas are running."
  fi
  log "AI quality worker capacity passed: ${quality_running_count}/${expected_quality_replicas} replicas."

  if [ "${GEOFLOW_SKIP_HTTP_CHECK:-0}" = "1" ]; then
    log "HTTP health check deferred until maintenance mode is lifted."
  else
    check_http "$web_port"
  fi

  log "Checking Laravel database connection."
  if "${COMPOSE[@]}" exec -T app php artisan migrate:status --pending=1 --no-interaction >/dev/null; then
    log "Database connection is reachable and no migrations are pending."
  else
    fail "Laravel cannot read migration status or still has pending migrations. Run the gated migration step before releasing services."
  fi

  log "Converging expired AI quality checks."
  "${COMPOSE[@]}" exec -T app php artisan geoflow:converge-ai-quality --json

  log "Validating AI optimization queue configuration."
  "${COMPOSE[@]}" exec -T app php artisan geoflow:work-ai-optimization --validate

  log "Checking AI quality worker heartbeats and front-queue probe."
  "${COMPOSE[@]}" exec -T app php artisan geoflow:ai-quality-health --json --probe --wait=10

  log "Recent application logs:"
  "${COMPOSE[@]}" logs --tail=80 app queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler web || true
}

main "$@"
