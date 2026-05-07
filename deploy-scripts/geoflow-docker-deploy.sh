#!/usr/bin/env bash
set -Eeuo pipefail

# GEOFlow production Docker one-click deployment helper.
# It performs host preflight checks, prepares .env.prod, deploys the
# docker-compose.prod.yml stack, seeds the default admin, and runs a healthcheck.

SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
REPO_URL="${GEOFLOW_REPO_URL:-https://github.com/yaojingang/GEOFlow.git}"
BRANCH="${GEOFLOW_BRANCH:-main}"
APP_DIR="${GEOFLOW_APP_DIR:-/opt/geoflow}"
NONINTERACTIVE="${GEOFLOW_NONINTERACTIVE:-0}"
YES="${GEOFLOW_YES:-0}"
INSTALL_DOCKER="${GEOFLOW_INSTALL_DOCKER:-auto}"
SELF_DELETE="${GEOFLOW_SELF_DELETE:-0}"

log() {
  printf '\033[1;34m[geoflow]\033[0m %s\n' "$*"
}

warn() {
  printf '\033[1;33m[warn]\033[0m %s\n' "$*" >&2
}

fail() {
  printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2
  exit 1
}

on_error() {
  local line="$1"
  fail "Deployment failed near line ${line}. Check the logs above, then rerun this script."
}
trap 'on_error $LINENO' ERR

command_exists() {
  command -v "$1" >/dev/null 2>&1
}

as_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  elif command_exists sudo; then
    sudo "$@"
  else
    fail "Root permission is required for this step. Re-run as root or install sudo."
  fi
}

ask_yes_no() {
  local prompt="$1"
  local default="${2:-n}"

  if [ "$YES" = "1" ] || [ "$NONINTERACTIVE" = "1" ]; then
    [ "$default" = "y" ]
    return
  fi

  local suffix="[y/N]"
  [ "$default" = "y" ] && suffix="[Y/n]"

  local answer
  read -r -p "${prompt} ${suffix} " answer
  answer="${answer:-$default}"
  case "$answer" in
    y|Y|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

prompt_value() {
  local var_name="$1"
  local prompt="$2"
  local default="$3"
  local current="${!var_name:-}"

  if [ -n "$current" ]; then
    printf '%s' "$current"
    return
  fi

  if [ "$NONINTERACTIVE" = "1" ]; then
    printf '%s' "$default"
    return
  fi

  local answer
  read -r -p "${prompt} [${default}]: " answer
  printf '%s' "${answer:-$default}"
}

random_secret() {
  if command_exists openssl; then
    openssl rand -hex 24
  else
    od -An -N24 -tx1 /dev/urandom | tr -d ' \n'
    printf '\n'
  fi
}

detect_primary_ip() {
  if command_exists ip; then
    ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}'
  elif command_exists hostname; then
    hostname -I 2>/dev/null | awk '{print $1}'
  fi
}

install_base_packages() {
  local missing=()
  for cmd in git curl openssl; do
    command_exists "$cmd" || missing+=("$cmd")
  done
  [ "${#missing[@]}" -eq 0 ] && return

  log "Installing base packages: ${missing[*]}"
  if command_exists apt-get; then
    as_root apt-get update
    as_root apt-get install -y ca-certificates curl git openssl
  elif command_exists dnf; then
    as_root dnf install -y ca-certificates curl git openssl
  elif command_exists yum; then
    as_root yum install -y ca-certificates curl git openssl
  else
    fail "Cannot install required packages automatically. Please install: ${missing[*]}"
  fi
}

install_docker_if_needed() {
  if command_exists docker; then
    return
  fi

  if [ "$INSTALL_DOCKER" = "0" ]; then
    fail "Docker is not installed. Install Docker and Docker Compose plugin first."
  fi

  if [ "$INSTALL_DOCKER" = "1" ] || ask_yes_no "Docker is not installed. Install Docker now?" "y"; then
    log "Installing Docker using the official convenience script."
    curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
    as_root sh /tmp/get-docker.sh
    rm -f /tmp/get-docker.sh
  else
    fail "Docker is required for the production deployment script."
  fi
}

detect_docker_command() {
  if docker info >/dev/null 2>&1; then
    DOCKER_CMD=(docker)
  elif command_exists sudo && sudo docker info >/dev/null 2>&1; then
    DOCKER_CMD=(sudo docker)
  else
    fail "Docker is installed but not usable by this user. Add the user to the docker group or run with sudo/root."
  fi

  if ! "${DOCKER_CMD[@]}" compose version >/dev/null 2>&1; then
    fail "Docker Compose v2 plugin is required. Please install 'docker compose'."
  fi
}

check_resources() {
  log "Running server preflight checks."

  local cpu_count mem_mb disk_mb
  cpu_count="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)"
  mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
  disk_mb="$(df -Pm "$(dirname "$APP_DIR")" 2>/dev/null | awk 'NR==2 {print $4}' || echo 0)"

  log "CPU: ${cpu_count} cores; Memory: ${mem_mb} MB; Free disk near app path: ${disk_mb} MB."
  [ "$cpu_count" -lt 2 ] && warn "Recommended minimum is 2 CPU cores."
  [ "$mem_mb" -lt 2048 ] && warn "Recommended minimum is 2 GB RAM plus swap; 4 GB+ is better for production."
  [ "$disk_mb" -lt 20480 ] && warn "Recommended minimum is 20 GB free disk; 40 GB+ is better for production."

  if [ "$mem_mb" -gt 0 ] && [ "$mem_mb" -lt 4096 ]; then
    warn "Low-memory server detected. Consider enabling 2 GB swap before building Docker images."
  fi
}

check_ports() {
  local web_port="$1"
  local reverb_port="$2"

  if command_exists ss; then
    if ss -ltn | awk '{print $4}' | grep -Eq "[:.]${web_port}$"; then
      warn "Port ${web_port} already appears to be in use. Change GEOFLOW_WEB_PORT if deployment fails."
    fi
    if ss -ltn | awk '{print $4}' | grep -Eq "[:.]${reverb_port}$"; then
      warn "Port ${reverb_port} already appears to be in use. Change GEOFLOW_REVERB_PORT if deployment fails."
    fi
  fi
}

clone_or_update_repo() {
  log "Preparing GEOFlow source at ${APP_DIR}."
  if [ -d "${APP_DIR}/.git" ]; then
    git -C "$APP_DIR" fetch origin "$BRANCH"
    git -C "$APP_DIR" checkout "$BRANCH"
    git -C "$APP_DIR" pull --ff-only origin "$BRANCH"
  elif [ -e "$APP_DIR" ] && [ "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 2>/dev/null | wc -l | tr -d ' ')" != "0" ]; then
    fail "${APP_DIR} exists and is not an empty Git checkout. Set GEOFLOW_APP_DIR to another path."
  else
    as_root mkdir -p "$(dirname "$APP_DIR")"
    if [ -w "$(dirname "$APP_DIR")" ]; then
      git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
    else
      as_root git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
    fi
  fi

  if [ "$(id -u)" -ne 0 ]; then
    as_root chown -R "$(id -u):$(id -g)" "$APP_DIR"
  fi
}

set_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  local tmp
  tmp="$(mktemp)"

  if [ -f "$file" ] && grep -q "^${key}=" "$file"; then
    awk -v k="$key" -v v="$value" '
      BEGIN { done=0 }
      $0 ~ "^" k "=" { print k "=" v; done=1; next }
      { print }
      END { if (!done) print k "=" v }
    ' "$file" > "$tmp"
    mv "$tmp" "$file"
  else
    [ -f "$file" ] && cp "$file" "$tmp" || : > "$tmp"
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    mv "$tmp" "$file"
  fi
}

get_env_value() {
  local file="$1"
  local key="$2"
  [ -f "$file" ] || return 0
  awk -F= -v k="$key" '$1 == k { value=substr($0, length(k) + 2) } END { print value }' "$file"
}

prepare_env() {
  cd "$APP_DIR"
  if [ ! -f .env.prod ]; then
    cp .env.prod.example .env.prod
  else
    log ".env.prod already exists; preserving existing values unless explicitly set by this script."
  fi

  local default_ip current_app_url current_admin_path current_web_port current_reverb_port
  local app_url admin_path web_port reverb_port db_password redis_password reverb_secret
  default_ip="$(detect_primary_ip || true)"
  default_ip="${default_ip:-127.0.0.1}"

  current_web_port="$(get_env_value .env.prod WEB_PORT)"
  current_reverb_port="$(get_env_value .env.prod REVERB_EXPOSE_PORT)"
  current_app_url="$(get_env_value .env.prod APP_URL)"
  current_admin_path="$(get_env_value .env.prod ADMIN_BASE_PATH)"

  web_port="$(prompt_value GEOFLOW_WEB_PORT "Public web port" "${current_web_port:-18080}")"
  reverb_port="$(prompt_value GEOFLOW_REVERB_PORT "Public Reverb port" "${current_reverb_port:-18081}")"
  app_url="$(prompt_value GEOFLOW_APP_URL "Public APP_URL, including protocol and optional subdirectory" "${current_app_url:-http://${default_ip}:${web_port}}")"
  admin_path="$(prompt_value GEOFLOW_ADMIN_BASE_PATH "Admin base path without leading slash" "${current_admin_path:-geo_admin}")"

  db_password="${GEOFLOW_DB_PASSWORD:-$(get_env_value .env.prod DB_PASSWORD)}"
  redis_password="${GEOFLOW_REDIS_PASSWORD:-$(get_env_value .env.prod REDIS_PASSWORD)}"
  reverb_secret="${GEOFLOW_REVERB_SECRET:-$(get_env_value .env.prod REVERB_APP_SECRET)}"
  db_password="${db_password:-$(random_secret)}"
  redis_password="${redis_password:-$(random_secret)}"
  reverb_secret="${reverb_secret:-$(random_secret)}"

  check_ports "$web_port" "$reverb_port"

  set_env_value .env.prod APP_ENV production
  set_env_value .env.prod APP_DEBUG false
  set_env_value .env.prod APP_URL "$app_url"
  set_env_value .env.prod TRUSTED_PROXIES "${GEOFLOW_TRUSTED_PROXIES:-*}"
  set_env_value .env.prod BOOST_BROWSER_LOGS_WATCHER false
  set_env_value .env.prod ADMIN_BASE_PATH "$admin_path"
  set_env_value .env.prod DB_CONNECTION pgsql
  set_env_value .env.prod DB_HOST postgres
  set_env_value .env.prod DB_PORT 5432
  set_env_value .env.prod DB_DATABASE "${GEOFLOW_DB_DATABASE:-geo_flow}"
  set_env_value .env.prod DB_USERNAME "${GEOFLOW_DB_USERNAME:-geo_user}"
  set_env_value .env.prod DB_PASSWORD "$db_password"
  set_env_value .env.prod REDIS_HOST redis
  set_env_value .env.prod REDIS_PASSWORD "$redis_password"
  set_env_value .env.prod WEB_PORT "$web_port"
  set_env_value .env.prod REVERB_EXPOSE_PORT "$reverb_port"
  set_env_value .env.prod REVERB_APP_SECRET "$reverb_secret"
  set_env_value .env.prod SESSION_LIFETIME 43200
  set_env_value .env.prod GEOFLOW_SESSION_TIMEOUT 2592000
  set_env_value .env.prod AUTO_MIGRATE false
  set_env_value .env.prod AUTO_OPTIMIZE true

  log "Production environment prepared."
}

deploy_stack() {
  cd "$APP_DIR"
  COMPOSE=("${DOCKER_CMD[@]}" compose --env-file .env.prod -f docker-compose.prod.yml)

  log "Building production images."
  "${COMPOSE[@]}" build

  log "Starting PostgreSQL and Redis."
  "${COMPOSE[@]}" up -d postgres redis

  log "Running initialization and database migrations."
  "${COMPOSE[@]}" up init

  log "Starting GEOFlow services."
  "${COMPOSE[@]}" up -d app web queue scheduler reverb

  log "Seeding default admin account if it does not exist."
  "${COMPOSE[@]}" run --rm app php artisan db:seed --force

  log "Clearing and rebuilding Laravel caches."
  "${COMPOSE[@]}" run --rm app php artisan optimize:clear
  "${COMPOSE[@]}" run --rm app php artisan optimize
}

run_healthcheck() {
  cd "$APP_DIR"
  if [ -x deploy-scripts/geoflow-healthcheck.sh ]; then
    GEOFLOW_APP_DIR="$APP_DIR" bash deploy-scripts/geoflow-healthcheck.sh
  else
    warn "Healthcheck script is missing; skipping."
  fi
}

print_summary() {
  cd "$APP_DIR"
  local app_url admin_path
  app_url="$(grep '^APP_URL=' .env.prod | cut -d= -f2-)"
  admin_path="$(grep '^ADMIN_BASE_PATH=' .env.prod | cut -d= -f2-)"

  printf '\n'
  log "Deployment completed."
  printf 'Site:  %s\n' "$app_url"
  printf 'Admin: %s/%s/login\n' "${app_url%/}" "$admin_path"
  printf 'Default admin username: admin\n'
  printf 'Default admin password: password\n'
  printf 'Security note: change the default admin password immediately after first login.\n'
}

self_delete_if_requested() {
  if [ "$SELF_DELETE" != "1" ]; then
    return
  fi

  if [ -f "$SCRIPT_PATH" ]; then
    rm -f "$SCRIPT_PATH"
    log "Deployment script removed: ${SCRIPT_PATH}"
  fi
}

main() {
  install_base_packages
  check_resources
  install_docker_if_needed
  detect_docker_command
  clone_or_update_repo
  prepare_env
  deploy_stack
  run_healthcheck
  print_summary
  self_delete_if_requested
}

main "$@"
