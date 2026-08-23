# GEOFlow UI V3 本地开发环境

UI V3 使用独立源码目录、Compose 项目、应用镜像、网络、数据库卷、Redis、端口和浏览器 Session。当前稳定环境继续运行在 `http://localhost:18080`。

## 地址

- UI V3 后台：`http://localhost:28080/admin/dashboard`
- UI V3 试点：`http://localhost:28080/previews/geoflow-admin-ui-v3-pilot/index.html`
- 当前稳定后台：`http://localhost:18080/admin/dashboard`

## 启动

推荐使用安全启动脚本。脚本会先检查 `28080`、`28081`、`35432` 和 `36379`，端口被其他项目占用时会中止启动：

```bash
./scripts/ui-v3-up.sh
```

也可以在 UI V3 仓库根目录直接执行 Compose 命令：

```bash
docker compose -f docker-compose.yml -f docker-compose.ui-v3.yml up -d app
```

该命令只启动应用及其必要依赖。需要实时广播或后台任务时，再显式启动对应服务：

```bash
docker compose -f docker-compose.yml -f docker-compose.ui-v3.yml up -d reverb queue knowledge-queue system-update-queue scheduler
```

## 状态和停止

```bash
docker compose -f docker-compose.yml -f docker-compose.ui-v3.yml ps
docker compose -f docker-compose.yml -f docker-compose.ui-v3.yml stop
```

需要重建全新演示数据时，先停止 UI V3，再仅删除 UI V3 的容器和数据卷：

```bash
docker compose -f docker-compose.yml -f docker-compose.ui-v3.yml down --volumes --remove-orphans
./scripts/ui-v3-up.sh
```

重建命令会清空 UI V3 演示数据，当前稳定环境及其数据卷不会被处理。

全部命令必须同时提供两个 Compose 文件，确保服务始终使用 UI V3 隔离配置。数据库和 Redis 宿主机端口采用 `35432` 与 `36379`，避开本机已有的 TokEMS 服务。PostgreSQL 的命名卷直接挂载到 `/var/lib/postgresql/data`，确保重建时不会遗留匿名数据卷。Docker 网络固定使用 `geoflow-ui-v3-network` 和 `10.254.217.0/24`，避开已耗尽的默认地址池。

应用容器将 `storage/framework/testing` 挂载为容器内 tmpfs，使文件锁与大小写路径测试使用 Linux 文件系统语义，不受 macOS 源码挂载影响。

UI V3 的 `.env` 从 `.env.example` 创建，并使用 `.env.ui-v3.example` 中的隔离变量。真实 API 密钥、Token 和当前稳定环境数据库不得复制到此环境。
