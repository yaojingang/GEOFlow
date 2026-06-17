#!/usr/bin/env bash
#
# 在本机（Mac/Apple Silicon 亦可）构建 linux/amd64 生产镜像并推送到镜像仓库。
#
# 用法：
#   bash deploy-scripts/build-and-push-amd64-images.sh
#   VERSION=20260617 bash deploy-scripts/build-and-push-amd64-images.sh
#   REGISTRY=registry.cn-beijing.aliyuncs.com NS=geo_flow bash deploy-scripts/build-and-push-amd64-images.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

REGISTRY="${REGISTRY:-registry.cn-beijing.aliyuncs.com}"
NS="${NS:-geo_flow}"
VERSION="${VERSION:-$(date +%Y%m%d)}"
PLATFORM="${DOCKER_PLATFORM:-linux/amd64}"
BUILDER_NAME="${DOCKER_BUILDX_BUILDER:-geoflow-builder}"

COMPOSER_IMAGE="${COMPOSER_IMAGE:-composer:2}"
PHP_FPM_IMAGE="${PHP_FPM_IMAGE:-php:8.4-fpm-bookworm}"
PECL_REDIS_VERSION="${PECL_REDIS_VERSION:-6.3.0}"
NGINX_IMAGE="${NGINX_IMAGE:-nginx:1.31.1-alpine}"

cd "${PROJECT_DIR}"

echo ">>> Registry: ${REGISTRY}"
echo ">>> Namespace: ${NS}"
echo ">>> Version: ${VERSION}"
echo ">>> Platform: ${PLATFORM}"

docker login --username='majin72' "${REGISTRY}"

docker buildx create --name "${BUILDER_NAME}" --use 2>/dev/null || docker buildx use "${BUILDER_NAME}"
docker buildx inspect --bootstrap

echo ">>> 构建并推送 app 镜像"
docker buildx build --platform "${PLATFORM}" \
  -f docker/Dockerfile.prod \
  -t "${REGISTRY}/${NS}/geoflow-app-prod:${VERSION}" \
  -t "${REGISTRY}/${NS}/geoflow-app-prod:latest" \
  --build-arg COMPOSER_IMAGE="${COMPOSER_IMAGE}" \
  --build-arg PHP_FPM_IMAGE="${PHP_FPM_IMAGE}" \
  --build-arg PECL_REDIS_VERSION="${PECL_REDIS_VERSION}" \
  --push .

echo ">>> 构建并推送 web 镜像"
docker buildx build --platform "${PLATFORM}" \
  -f docker/nginx/Dockerfile.prod \
  -t "${REGISTRY}/${NS}/geoflow-web-prod:${VERSION}" \
  -t "${REGISTRY}/${NS}/geoflow-web-prod:latest" \
  --build-arg NGINX_IMAGE="${NGINX_IMAGE}" \
  --push .

echo ">>> 推送完成"
echo "    ${REGISTRY}/${NS}/geoflow-app-prod:${VERSION}"
echo "    ${REGISTRY}/${NS}/geoflow-web-prod:${VERSION}"
echo "    ${REGISTRY}/${NS}/geoflow-app-prod:latest"
echo "    ${REGISTRY}/${NS}/geoflow-web-prod:latest"
