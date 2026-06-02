# GEOFlow Docker 生产部署初始化与 500 排查说明

适用场景：在 Ubuntu 24.04 LTS 服务器上使用 Docker、`docker-compose.prod.yml` 和 `.env.prod` 部署 GEOFlow 后，遇到初始化命令找不到、后台 500、首页 500、环境变量看似已填写但未生效等问题。

## 零、先确认当前执行位置

下面的 Docker Compose 命令都应该在服务器的 GEOFlow 项目目录执行，也就是能看到这些文件的位置：

```bash
ls docker-compose.prod.yml .env.prod
```

如果看不到这两个文件，说明当前目录不对，先进入项目目录。

Ubuntu 24.04 LTS 推荐使用 Docker Compose v2，命令形式是 `docker compose`，不是旧版 `docker-compose`。先确认版本：

```bash
docker --version
docker compose version
```

如果普通用户执行 Docker 命令提示权限不足，可以临时加 `sudo`：

```bash
sudo docker compose --env-file .env.prod -f docker-compose.prod.yml ps
```

如果当前是 `root` 用户，或者当前用户已经加入 `docker` 用户组，则不需要加 `sudo`。

建议先定义一个生产环境命令前缀，后面排查会更不容易输错：

```bash
export COMPOSE_PROD='docker compose --env-file .env.prod -f docker-compose.prod.yml'
```

如果你的服务器必须使用 `sudo docker`，则改成：

```bash
export COMPOSE_PROD='sudo docker compose --env-file .env.prod -f docker-compose.prod.yml'
```

后文出现的 `docker logs`、`docker exec`、`docker ps` 也是同理：如果当前用户没有 Docker 权限，就在命令前加 `sudo`。

## 一、初始化命令在哪里执行

Docker 生产部署时，不建议在服务器宿主机上直接执行裸命令：

```bash
php artisan migrate --force
```

原因是 Ubuntu 宿主机上不一定安装了 PHP、Composer 依赖和 Laravel 运行环境。GEOFlow 的 PHP 运行环境在 `app` 容器内。

正确方式是在服务器项目目录执行 Docker Compose 命令，让命令运行到 `app` 容器内：

```bash
$COMPOSE_PROD run --rm app php artisan key:generate --force
$COMPOSE_PROD run --rm app php artisan migrate --force
$COMPOSE_PROD run --rm app php artisan db:seed --force
$COMPOSE_PROD run --rm app php artisan storage:link --force
$COMPOSE_PROD run --rm app php artisan optimize
```

也就是说：命令从容器外的服务器项目目录执行，但实际运行在 `app` 容器内。

也可以先进容器后执行：

```bash
$COMPOSE_PROD exec app sh
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

注意：不要在 `web/nginx` 容器里执行 Laravel 初始化命令，应该在 `app` 容器里执行。

如果用户说“初始化命令没找到”，通常是把 `php artisan ...` 直接复制到了 Ubuntu 宿主机执行。Docker 部署下应改用上面的 `$COMPOSE_PROD run --rm app php artisan ...`。

## 二、初始化变量填了但仍然 500

如果部署日志里仍然显示：

```text
Site: https://your-domain.com
Admin: https://your-domain.com/geo_admin/login
```

说明 `.env.prod` 很可能没有被当前 Docker Compose 进程正确读取，或者修改 `.env.prod` 后没有重建容器。

先在项目目录检查 Compose 实际读取到的配置：

```bash
$COMPOSE_PROD config | grep -E 'APP_URL|DB_HOST|DB_DATABASE|DB_USERNAME|WEB_PORT|ADMIN_BASE_PATH'
```

重点确认：

```env
APP_URL=http://172.29.64.77:18080
ADMIN_BASE_PATH=geo_admin
DB_HOST=postgres
REDIS_HOST=redis
```

这里的 `APP_URL` 要按实际访问方式填写：

- 如果直接用服务器 IP 加端口访问，例如 `http://172.29.64.77:18080/geo_admin/login`，则 `APP_URL=http://172.29.64.77:18080`
- 如果前面有域名和 HTTPS 反向代理，则 `APP_URL=https://你的域名`
- 不要保留 `https://your-domain.com`，这只是示例占位符

如果刚改过 `.env.prod`，需要重建相关容器：

```bash
$COMPOSE_PROD up -d --force-recreate app web queue scheduler
```

然后查看 Laravel 的真实错误日志：

```bash
$COMPOSE_PROD logs --tail=200 app
$COMPOSE_PROD exec app sh
tail -n 200 storage/logs/laravel.log
```

也可以检查初始化容器是否成功执行：

```bash
$COMPOSE_PROD ps -a | grep init
$COMPOSE_PROD logs --tail=200 init
```

如果 `init` 容器已经成功执行，数据库迁移和初始化数据通常已经完成，不需要重复执行 `db:seed`。如果 `init` 没有运行或运行失败，再用 `docker compose ... run --rm app php artisan ...` 手动补执行。

## 三、常见原因速查

| 现象 | 优先检查 |
| --- | --- |
| 后台或首页 500 | `storage/logs/laravel.log` 中的真实错误 |
| 页面仍跳到 `your-domain.com` | `.env.prod` 是否被读取、`APP_URL` 是否正确、容器是否重建 |
| 数据库连接失败 | Docker 模式下 `DB_HOST` 应为 `postgres`，不是 `127.0.0.1` |
| Redis 连接失败 | Docker 模式下 `REDIS_HOST` 应为 `redis` |
| 图片打不开 | 是否执行过 `storage:link`，Nginx 根目录是否指向 `public` |
| 后台路径不对 | `ADMIN_BASE_PATH` 是否与访问路径一致 |

## 四、Ubuntu 24.04 LTS 常见注意点

1. 不要只执行 `docker compose up -d`。生产部署建议每次都带上：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml ...
```

否则 Compose 可能不会按预期读取 `.env.prod`。

2. 如果服务器启用了防火墙，需要放行 `WEB_PORT`。例如当前使用 `18080`：

```bash
sudo ufw allow 18080/tcp
sudo ufw status
```

3. 如果使用云服务器，还要在云厂商安全组里放行对应端口。

4. 如果修改过 `.env.prod` 中的数据库用户名或密码，但 PostgreSQL 数据目录已经初始化过，新的数据库密码不会自动覆盖旧数据目录。新测试环境可以清空数据目录后重来；正式环境不要直接删除数据目录，应先确认旧密码或做备份迁移。

## 五、推荐的首次部署顺序

```bash
$COMPOSE_PROD build
$COMPOSE_PROD up -d postgres redis
$COMPOSE_PROD up -d init
$COMPOSE_PROD logs --tail=200 init
$COMPOSE_PROD up -d app web queue scheduler reverb
```

如果希望一次性启动，也可以执行：

```bash
$COMPOSE_PROD up -d --build
```

首次部署后，如果修改了 `.env.prod`，建议至少重建应用相关容器：

```bash
$COMPOSE_PROD up -d --force-recreate app web queue scheduler reverb
```
