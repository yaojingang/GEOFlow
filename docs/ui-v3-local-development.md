# GEOFlow UI V3 候选验收环境

UI V3 已合入 GEOFlow 主代码库。`18080` 是最终统一入口，`28080` 仅用于切换后的 48 小时候选观察和回退验证。候选环境使用独立 Compose 项目、数据库卷、Redis、端口和浏览器 Session，不写入正式数据库。

## 地址

- 最终后台：`http://localhost:18080/admin/dashboard`
- 候选验收后台：`http://localhost:28080/admin/dashboard`
- 候选 WebSocket：同源路径 `http://localhost:28080/reverb`

## 启动

候选观察期内可使用安全启动脚本。脚本会先检查 `28080`、`35432` 和 `36379`，端口被其他项目占用时会中止启动：

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

只有在候选环境确认无需保留时，才删除候选容器和数据卷：

```bash
docker compose -f docker-compose.yml -f docker-compose.ui-v3.yml down --volumes --remove-orphans
./scripts/ui-v3-up.sh
```

删除命令会清空候选环境数据，`18080` 使用的正式数据卷不会被处理。正式环境与候选环境都保持 `GEOFLOW_SEED_FRONTEND_DEMO=false`，验收数据来自正式数据库的逻辑克隆。

候选命令必须同时提供两个 Compose 文件，确保服务始终使用隔离配置。数据库和 Redis 宿主机端口采用 `35432` 与 `36379`。PostgreSQL 的命名卷直接挂载到 `/var/lib/postgresql/data`，避免产生匿名数据卷。Docker 网络固定使用 `geoflow-ui-v3-network` 和 `10.254.217.0/24`。

应用容器将 `storage/framework/testing` 挂载为容器内 tmpfs，使文件锁与大小写路径测试使用 Linux 文件系统语义，不受 macOS 源码挂载影响。

候选环境的 `.env` 从 `.env.example` 创建，并使用 `.env.ui-v3.example` 中的隔离变量。真实 API 密钥和 Token 不写入候选配置；需要验证真实 Agent 时，通过受控的本地覆盖文件临时连接正式数据克隆，验证完成后立即关闭运行时开关。
