#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_args=(
  --project-directory "$repo_root"
  -f "$repo_root/docker-compose.yml"
  -f "$repo_root/docker-compose.ui-v3.yml"
)

declare -a required_ports=(28080 35432 36379)

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker 未安装或未加入 PATH。" >&2
  exit 1
fi

if ! docker info >/dev/null 2>&1; then
  echo "Docker Desktop 尚未运行。" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "缺少 Python 3，无法执行隔离配置校验。" >&2
  exit 1
fi

for port in "${required_ports[@]}"; do
  ui_v3_owner=false
  foreign_owner=false

  while IFS= read -r container_id; do
    [[ -z "$container_id" ]] && continue
    project_name="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id")"

    if [[ "$project_name" == "geoflow-ui-v3" ]]; then
      ui_v3_owner=true
    else
      foreign_owner=true
      container_name="$(docker inspect --format '{{ .Name }}' "$container_id" | sed 's#^/##')"
      echo "端口 $port 已被容器 $container_name 使用，UI V3 启动已中止。" >&2
    fi
  done < <(docker ps --filter "publish=$port" --format '{{.ID}}')

  if [[ "$foreign_owner" == true ]]; then
    exit 1
  fi

  if [[ "$ui_v3_owner" == false ]] && command -v lsof >/dev/null 2>&1; then
    if lsof -nP -iTCP:"$port" -sTCP:LISTEN -t >/dev/null 2>&1; then
      echo "端口 $port 已被本机进程使用，UI V3 启动已中止。" >&2
      exit 1
    fi
  fi
done

docker compose "${compose_args[@]}" config --quiet
docker compose "${compose_args[@]}" config --format json | python3 -c '
import json
import sys

config = json.load(sys.stdin)
errors = []

if config.get("name") != "geoflow-ui-v3":
    errors.append("Compose 项目名必须为 geoflow-ui-v3")

for service_name, service in config.get("services", {}).items():
    container_name = service.get("container_name", "")
    if not container_name.startswith("geoflow-ui-v3-"):
        errors.append(f"服务 {service_name} 的容器名未使用 geoflow-ui-v3 前缀")
    if service_name not in {"postgres", "redis", "assets"} and service.get("image") != "geoflow-ui-v3-app:latest":
        errors.append(f"服务 {service_name} 未使用 geoflow-ui-v3-app:latest")

for volume_name, volume in config.get("volumes", {}).items():
    resolved_name = volume.get("name", volume_name)
    if not resolved_name.startswith("geoflow-ui-v3-"):
        errors.append(f"数据卷 {resolved_name} 未使用 geoflow-ui-v3 前缀")

for network_name, network in config.get("networks", {}).items():
    resolved_name = network.get("name", network_name)
    if not resolved_name.startswith("geoflow-ui-v3-"):
        errors.append(f"网络 {resolved_name} 未使用 geoflow-ui-v3 前缀")

if errors:
    print("\n".join(errors), file=sys.stderr)
    raise SystemExit(1)
'
docker compose "${compose_args[@]}" up -d app

echo "UI V3 已启动：http://localhost:28080/admin/dashboard"
