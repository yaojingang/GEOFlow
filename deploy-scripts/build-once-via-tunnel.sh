#!/usr/bin/env bash
#
# 一次性 docker compose build：仅当前命令走隧道代理，不重启 Docker daemon。
# BuildKit 读取临时 DOCKER_CONFIG 中的 proxies，不影响已运行容器。
#
# 用法：
#   bash deploy-scripts/build-once-via-tunnel.sh --env-file .env.prod
#   DOCKER_TUNNEL_PROXY_PORT=18888 bash deploy-scripts/build-once-via-tunnel.sh
#   bash deploy-scripts/build-once-via-tunnel.sh --proxy-port 18888 -f docker-compose.prod.yml
#
set -euo pipefail

ENV_FILE=".env.prod"
COMPOSE_FILE="docker-compose.prod.yml"
PROXY_PORT="${DOCKER_TUNNEL_PROXY_PORT:-${REMOTE_PROXY_PORT:-17890}}"
PROXY_URL="http://127.0.0.1:${PROXY_PORT}"
TMP_DOCKER_CONFIG=""

cleanup() {
  if [[ -n "${TMP_DOCKER_CONFIG}" && -d "${TMP_DOCKER_CONFIG}" ]]; then
    rm -rf "${TMP_DOCKER_CONFIG}"
  fi
}
trap cleanup EXIT

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env-file)
      ENV_FILE="$2"
      shift 2
      ;;
    -f)
      COMPOSE_FILE="$2"
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

if ! curl -x "${PROXY_URL}" -sS --max-time 15 -o /dev/null https://registry-1.docker.io/v2/; then
  echo "隧道不可用。请先在本机运行 start-docker-pull-tunnel.sh" >&2
  exit 1
fi

TMP_DOCKER_CONFIG="$(mktemp -d)"
cat >"${TMP_DOCKER_CONFIG}/config.json" <<EOF
{
  "proxies": {
    "default": {
      "httpProxy": "${PROXY_URL}",
      "httpsProxy": "${PROXY_URL}",
      "noProxy": "localhost,127.0.0.1,::1"
    }
  }
}
EOF

echo ">>> 一次性构建（拉镜像 + 构建容器内网络均走 ${PROXY_URL}，不重启 docker）"
export DOCKER_CONFIG="${TMP_DOCKER_CONFIG}"

# DOCKER_CONFIG 仅影响 registry 拉取；RUN 内 apk/apt/pecl/composer 需 build-arg 传入代理
sudo -E env \
  DOCKER_CONFIG="${DOCKER_CONFIG}" \
  DOCKER_BUILD_NETWORK="host" \
  DOCKER_BUILD_HTTP_PROXY="${PROXY_URL}" \
  DOCKER_BUILD_HTTPS_PROXY="${PROXY_URL}" \
  docker-compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" build

echo ">>> 构建完成。启动: sudo docker-compose --env-file ${ENV_FILE} -f ${COMPOSE_FILE} up -d"
