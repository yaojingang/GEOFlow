#!/usr/bin/env bash
#
# 在 ECS 一次性拉取基础镜像，经 SSH 隧道走本机代理。
# 不修改 Docker daemon、不重启 docker，不影响正在运行的容器。
#
# 前置：本机已运行 start-docker-pull-tunnel.sh
#
# 用法：
#   bash deploy-scripts/pull-images-once-via-tunnel.sh --env-file .env.prod
#   DOCKER_TUNNEL_PROXY_PORT=18888 bash deploy-scripts/pull-images-once-via-tunnel.sh
#   bash deploy-scripts/pull-images-once-via-tunnel.sh --proxy-port 18888
#
# 端口须与本机 start-docker-pull-tunnel.sh 的 REMOTE_PROXY_PORT 一致（默认 17890）。
#
set -euo pipefail

ENV_FILE=".env.prod"
PROXY_PORT="${DOCKER_TUNNEL_PROXY_PORT:-${REMOTE_PROXY_PORT:-17890}}"
PROXY_URL="http://127.0.0.1:${PROXY_PORT}"
SKOPEO="${SKOPEO:-skopeo}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env-file)
      ENV_FILE="$2"
      shift 2
      ;;
    --proxy-port)
      PROXY_PORT="$2"
      PROXY_URL="http://127.0.0.1:${PROXY_PORT}"
      shift 2
      ;;
    *)
      echo "未知参数: $1" >&2
      exit 1
      ;;
  esac
done

ensure_skopeo() {
  if command -v "${SKOPEO}" >/dev/null 2>&1; then
    return 0
  fi
  echo ">>> 未找到 skopeo，尝试安装（经隧道）..."
  if command -v dnf >/dev/null 2>&1; then
    sudo env HTTP_PROXY="${PROXY_URL}" HTTPS_PROXY="${PROXY_URL}" dnf install -y skopeo
  elif command -v yum >/dev/null 2>&1; then
    sudo env HTTP_PROXY="${PROXY_URL}" HTTPS_PROXY="${PROXY_URL}" yum install -y skopeo
  elif command -v apt-get >/dev/null 2>&1; then
    sudo env HTTP_PROXY="${PROXY_URL}" HTTPS_PROXY="${PROXY_URL}" apt-get update
    sudo env HTTP_PROXY="${PROXY_URL}" HTTPS_PROXY="${PROXY_URL}" apt-get install -y skopeo
  else
    echo "请手动安装 skopeo，或改用 deploy-scripts/sync-images-from-local.sh" >&2
    exit 1
  fi
}

check_tunnel() {
  if ! curl -x "${PROXY_URL}" -sS --max-time 15 -o /dev/null https://registry-1.docker.io/v2/; then
    echo "隧道不可用。请在本机执行: ECS_HOST=... bash deploy-scripts/start-docker-pull-tunnel.sh" >&2
    exit 1
  fi
  echo ">>> 隧道代理可用: ${PROXY_URL}"
}

read_images_from_env() {
  local images=()
  if [[ -f "${ENV_FILE}" ]]; then
    while IFS= read -r line; do
      case "${line}" in
        COMPOSER_IMAGE=*|PHP_FPM_IMAGE=*|NGINX_IMAGE=*|PGVECTOR_IMAGE=*|REDIS_IMAGE=*)
          local value="${line#*=}"
          value="${value%\"}"
          value="${value#\"}"
          [[ -n "${value}" ]] && images+=("${value}")
          ;;
      esac
    done <"${ENV_FILE}"
  fi
  if [[ ${#images[@]} -eq 0 ]]; then
    images=(
      composer:2
      php:8.4-fpm-bookworm
      nginx:1.31.1-alpine
      pgvector/pgvector:pg18
      redis:8-alpine
    )
  fi
  printf '%s\n' "${images[@]}" | awk '!seen[$0]++'
}

pull_one() {
  local image="$1"
  echo ""
  echo ">>> 拉取: ${image}"
  sudo env HTTP_PROXY="${PROXY_URL}" HTTPS_PROXY="${PROXY_URL}" \
    "${SKOPEO}" copy --retry-times 3 \
    "docker://${image}" "docker-daemon:${image}"
}

check_tunnel
ensure_skopeo

mapfile -t IMAGES < <(read_images_from_env)
echo ">>> 将拉取 ${#IMAGES[@]} 个镜像（来自 ${ENV_FILE}）"

for image in "${IMAGES[@]}"; do
  pull_one "${image}"
done

echo ""
echo ">>> 完成。可继续构建（仍可用一次性构建脚本，无需重启 docker）："
echo "    bash deploy-scripts/build-once-via-tunnel.sh --env-file ${ENV_FILE}"
