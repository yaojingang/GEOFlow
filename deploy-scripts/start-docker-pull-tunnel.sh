#!/usr/bin/env bash
#
# 在本机（Mac）运行：SSH 反向隧道，把本机 HTTP 代理暴露给 ECS（默认 ECS:17890 → Mac:7890）。
# 配合 pull-images-once-via-tunnel.sh 使用，无需重启 ECS 上的 Docker。
#
set -euo pipefail

ECS_HOST="${ECS_HOST:-}"
LOCAL_PROXY_PORT="${LOCAL_PROXY_PORT:-7890}"
REMOTE_PROXY_PORT="${REMOTE_PROXY_PORT:-17890}"

if [[ -z "${ECS_HOST}" ]]; then
  echo "用法: ECS_HOST=ecs-user@1.2.3.4 $0" >&2
  exit 1
fi

echo ">>> 隧道 ECS 127.0.0.1:${REMOTE_PROXY_PORT} → 本机 127.0.0.1:${LOCAL_PROXY_PORT}"
echo ">>> 保持此窗口打开；在 ECS 执行（端口须与 REMOTE_PROXY_PORT 一致）："
echo "    DOCKER_TUNNEL_PROXY_PORT=${REMOTE_PROXY_PORT} bash deploy-scripts/pull-images-once-via-tunnel.sh --env-file .env.prod"
echo "    或: bash deploy-scripts/pull-images-once-via-tunnel.sh --proxy-port ${REMOTE_PROXY_PORT} --env-file .env.prod"
echo ""

exec ssh -N \
  -o ServerAliveInterval=30 \
  -o ServerAliveCountMax=3 \
  -R "127.0.0.1:${REMOTE_PROXY_PORT}:127.0.0.1:${LOCAL_PROXY_PORT}" \
  "${ECS_HOST}"
