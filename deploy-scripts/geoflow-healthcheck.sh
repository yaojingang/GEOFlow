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
      warn "HTTP health endpoint failed: ${url}. If an external reverse proxy is used, check Nginx/proxy config."
    fi
  else
    warn "curl is not installed; skipping HTTP health endpoint check."
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

  local required=(postgres redis app web queue scheduler reverb)
  local service
  for service in "${required[@]}"; do
    if "${COMPOSE[@]}" ps --status running --services | grep -qx "$service"; then
      log "Service running: ${service}"
    else
      warn "Service is not running: ${service}"
    fi
  done

  check_http "$web_port"

  log "Checking Laravel database connection."
  if "${COMPOSE[@]}" exec -T app php artisan migrate:status --no-interaction >/dev/null; then
    log "Database connection and migration table are reachable."
  else
    warn "Laravel cannot read migration status. Check app logs and database settings."
  fi

  log "Recent application logs:"
  "${COMPOSE[@]}" logs --tail=80 app queue scheduler web || true
}

main "$@"
