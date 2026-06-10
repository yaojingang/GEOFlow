#!/usr/bin/env bash
#
# 在本机（Mac）运行：用本机网络 docker pull，经 SSH 管道 docker load 到 ECS。
# 完全不改 ECS 的 Docker 配置、不建隧道、不重启 docker。
#
# 注意：ECS 一般为 linux/amd64，本机 Apple Silicon 时必须拉 amd64 层。
#
# 用法：
#   ECS_HOST=ecs-user@1.2.3.4 bash deploy-scripts/sync-images-from-local.sh
#
set -euo pipefail

ECS_HOST="${ECS_HOST:-}"
PLATFORM="${DOCKER_PLATFORM:-linux/amd64}"
ENV_FILE="${ENV_FILE:-.env.prod}"

if [[ -z "${ECS_HOST}" ]]; then
  echo "用法: ECS_HOST=ecs-user@1.2.3.4 $0" >&2
  exit 1
fi

default_images=(
  composer:2
  php:8.4-fpm-bookworm
  nginx:1.31.1-alpine
  pgvector/pgvector:pg18
  redis:8-alpine
)

read_images() {
  if [[ -f "${ENV_FILE}" ]]; then
    grep -E '^(COMPOSER|PHP_FPM|NGINX|PGVECTOR|REDIS)_IMAGE=' "${ENV_FILE}" \
      | cut -d= -f2- | tr -d '"' | awk '!seen[$0]++'
    return
  fi
  printf '%s\n' "${default_images[@]}"
}

sync_one() {
  local image="$1"
  echo ""
  echo ">>> 本机拉取 (${PLATFORM}): ${image}"
  docker pull --platform "${PLATFORM}" "${image}"
  echo ">>> 导入 ECS: ${image}"
  docker save "${image}" | ssh "${ECS_HOST}" sudo docker load
}

mapfile -t IMAGES < <(read_images)
echo ">>> 同步 ${#IMAGES[@]} 个镜像到 ${ECS_HOST}"

for image in "${IMAGES[@]}"; do
  sync_one "${image}"
done

echo ""
echo ">>> 完成。在 ECS 上执行 compose build/up 即可（基础镜像已在本地）。"
