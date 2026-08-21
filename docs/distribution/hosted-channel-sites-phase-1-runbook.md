# GEOFlow 托管渠道站点阶段一运行手册

## 适用范围

阶段一支持一个任务绑定一个托管站点，任务发布范围固定为 `distribution_only`。托管站点与主站共享应用、数据库、媒体文件和中央后台。批量建站、多站点容量池、自定义顶级域名、DNS 自动化和 Search Console 自动化留到后续阶段。

## 发布前配置

首次发布代码和迁移时保持功能关闭：

```dotenv
GEOFLOW_HOSTED_SITES_ENABLED=false
GEOFLOW_PRIMARY_HOSTS=example.com,www.example.com
GEOFLOW_HOSTED_SITE_ROOT_DOMAINS=sites.example.com

GEOFLOW_NGINX_PRIMARY_HOST=example.com
GEOFLOW_NGINX_PRIMARY_ALIASES=www.example.com
GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN=sites.example.com
GEOFLOW_NGINX_PUBLIC_SCHEME=https
GEOFLOW_NGINX_PUBLIC_PORT=443

TRUSTED_PROXIES=REMOTE_ADDR
SESSION_DOMAIN=null

REVERB_HOST=example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=example.com,www.example.com

GEOFLOW_HOSTED_SITE_INDEX_OBSERVATION_MINUTES=30
```

`GEOFLOW_PRIMARY_HOSTS` 使用英文逗号分隔，`GEOFLOW_NGINX_PRIMARY_ALIASES` 使用空格分隔。主站 Host 与托管根域及其子域不能重叠。阶段一固定使用 HTTPS 443。生产环境启用托管功能时，配置检查会校验主站、根域、Host-only Cookie、可信代理、公开协议与端口、Reverb 内部端口与路径的一致性，配置错误会阻止应用启动。

外层 CDN 或负载均衡终止 TLS 时，Nginx 通过 `GEOFLOW_NGINX_PUBLIC_SCHEME` 和 `GEOFLOW_NGINX_PUBLIC_PORT` 向 Laravel 传递受控的公开地址。公网客户端提交的转发协议不会直接成为可信配置。Compose 内置 Nginx 使用 `TRUSTED_PROXIES=REMOTE_ADDR` 信任当前直接上游；接入 CDN 或额外代理时，再加入经过核实的实际地址或 CIDR。

`REVERB_ALLOWED_ORIGINS` 必须覆盖每一个可承载后台的主站 hostname，不带 `https://` 和路径。浏览器通过主站的 `/reverb` 入口建立连接，Reverb 容器端口不对公网发布。

DNS 为托管根域配置泛解析，例如 `*.sites.example.com`。TLS 证书覆盖同一个泛域名。Nginx 提供三个入口：默认 Host 拒绝、主站完整入口、托管泛域名公开入口。已知站点的静态文件和 `/storage/*` 也经过站点解析；未知 Host 和功能关闭时均返回 404。

## 部署与进程重载

先发布代码并执行迁移：

```bash
php artisan migrate --force
```

生产库产生托管业务数据后，应用回滚保留新增表、归属记录、访问日志和线索数据，不执行托管迁移的 `down()`。

Docker Compose 使用常驻 `queue:work` 和 `schedule:work`。环境变量或配置变化后，重新创建相关服务：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --force-recreate \
  app web queue knowledge-queue system-update-queue scheduler reverb
```

裸机或虚拟机部署需要重载 PHP-FPM，并重启实际使用的队列和调度服务。下面的服务名是示例，执行前替换为服务器上的真实名称：

```bash
php artisan optimize:clear
php artisan optimize
sudo systemctl reload php8.4-fpm
sudo supervisorctl restart geoflow-queue:*
sudo systemctl restart geoflow-scheduler.service
sudo systemctl restart geoflow-reverb.service
```

使用 Horizon 的独立部署可执行 `php artisan horizon:terminate`，由进程管理器拉起新进程。Compose 标准拓扑不运行 Horizon，`horizon:terminate` 不会重启 Compose 的 `queue:work`。

队列继续使用 `distribution`。分发 Job 超时为 60 秒，主 Supervisor 或 Compose Worker 超时为 660 秒，Redis 和 database 队列的 `retry_after` 为 960 秒。Horizon 部署的 `redis:distribution` 等待告警阈值为 60 秒。

调度器每 5 分钟执行：

```bash
php artisan hosted-sites:reconcile --limit=500
```

调度配置包含 `onOneServer()` 和 `withoutOverlapping(10)`。功能关闭时 reconcile 返回零修改。

## 首个灰度站点

按以下顺序执行：

1. 保持功能关闭，完成 DNS、通配符 TLS、可信代理和 Nginx 三入口配置。验证主站正常，任意托管 Host 返回 404，外部 HTTPS 生成的链接不带内部 `:80`。
2. 将 `GEOFLOW_HOSTED_SITES_ENABLED` 改为 `true`，按上一节重建 Web、队列、调度和 Reverb 进程。
3. 打开“分发管理 > 托管渠道站点”创建灰度站点。新站固定为 `paused + maintenance + noindex + pending`，也不会生成渠道密钥。
4. 填写站点名称、简介、关于页、公开联系邮箱、认证主题、时区、日发布上限和允许使用的有效线索表单。公开联系邮箱与有效联系表单至少配置一项。新站不会继承主站 Logo、图标、版权、备案、轮播、首页模块、广告、自定义 HTML、脚本和统计代码，站点身份信息由运营人员单独配置。
5. 执行维护态预检。生产默认启用网络探针，会验证公共 DNS、TLS、503、noindex 和配置完整性。

   ```bash
   php artisan hosted-sites:preflight alpha.sites.example.com --dry-run
   php artisan hosted-sites:preflight alpha.sites.example.com
   ```

6. 在后台激活站点。系统先以 `paused + online + noindex` 执行在线探针，检查首页、canonical、JSON-LD、关于页、robots、Sitemap、白名单表单和认证主题资源。检查通过后切换为 `active`；检查失败会自动返回 `paused + maintenance + noindex`。
7. 给一个 `distribution_only` 任务绑定该托管渠道，发布测试文章。检查归属、分发、文章页 canonical、访问日志和线索归属。
8. 保持 `noindex` 完成灰度。文章数量达到门槛、在线预检新鲜、上线观察窗口完成且窗口内没有 5xx 时，超级管理员勾选内容、版权、合规确认后开放索引。观察窗口默认 30 分钟，由 `GEOFLOW_HOSTED_SITE_INDEX_OBSERVATION_MINUTES` 配置。

## 公开页面与数据隔离

托管域名开放：首页、搜索、分类、文章、关于页、白名单表单、`robots.txt`、`sitemap.xml`、`/sitemaps/pages-*.xml` 和 `/archive` 兼容跳转。后台、API、Horizon、Reverb、Broadcasting 和调试入口均返回 404。

文章查询由当前 profile 的已发布归属限定。首页模块、推荐、热门、导航、分类、文章详情和 Sitemap 共用相同范围。Blade 只渲染控制器或 View Composer 预加载的数据。站点设置和主题缓存键包含 profile ID 与设置版本。

`noindex` 页面返回 `X-Robots-Tag: noindex, nofollow` 并输出 robots meta，`robots.txt` 禁止抓取。开放索引后，`sitemap.xml` 返回 Sitemap 索引，每个分片最多 50,000 个 URL；分片只包含当前站点可见文章和托管域名绝对地址。Sitemap 使用动态查询和 `no-cache`，文章发布、更新与下架立即反映到结果中。

## 运维命令

```bash
# 检查一个站点，--all 可检查全部站点
php artisan hosted-sites:preflight alpha.sites.example.com --dry-run

# 补偿请求、预留、崩溃窗口和功能开关暂停的队列记录
php artisan hosted-sites:reconcile --limit=500 --dry-run
php artisan hosted-sites:reconcile --limit=500

# 清理一个站点的解析与设置缓存，--all 可清理全部站点
php artisan hosted-sites:invalidate-cache alpha.sites.example.com --dry-run
php artisan hosted-sites:invalidate-cache alpha.sites.example.com
```

业务发布失败会写入脱敏错误，更新文章归属与分配请求，并按指数退避规则重试。连续终态失败达到阈值后，站点进入冷却期。功能开关关闭产生的暂停记录保持 `queued`，不累计业务失败；重新启用后由 reconcile 恢复派发。

后台详情页展示今日容量、绑定任务、最近健康检查、最近发布、连续失败、访问与线索归属、最近分配请求及错误。预检失败项同时显示在后台、渠道错误和 CLI 输出中。

## 验收清单

- 主站首页、登录、后台、API 和 Reverb 工作正常。
- 主站 WebSocket Origin 使用主站 hostname 并能完成握手。
- 已知托管域名只开放公开白名单路径。
- 未知、嵌套、保留前缀域名及其静态资源返回 404。
- 未受信代理提供的 `X-Forwarded-Host` 不改变站点，受信代理可转发已知托管 Host。
- 外部 HTTPS 下的 canonical、表单、资源和跳转使用 `https://host`，不出现内部 `:80`。
- 主站与两个托管站之间没有文章、分类、设置、主题、缓存和表单串站。
- 会话 Cookie 没有 `Domain` 属性。
- 维护站返回 503，归档站返回 410，两者都禁止索引。
- 在线激活探针失败会自动回到维护状态。
- 在线激活过程中只有携带一次性内部令牌的技术探针可查看页面；进程中断时公众仍得到 503。
- `noindex` 和 `index` 状态下的 robots、Sitemap 索引、分片和 canonical 符合预期。
- 开放索引前已完成配置的观察窗口，且最近窗口内没有 5xx 记录。
- 一篇文章只产生一个托管归属，日发布上限不会被并发请求突破。
- 暂停渠道后不再产生新归属，归档会取消在途请求并解除任务绑定。
- 硬删除只能在归档后执行，删除影响确认包含 profile、归属、请求、访问日志和线索。

## PostgreSQL 并发验证

并发套件使用独立 PostgreSQL 测试库，验证 `FOR UPDATE`、唯一归属、日容量、跨站内容指纹唯一冲突和分配/归档锁顺序。默认端口及账号写在 `phpunit.postgresql.xml`，可通过同名环境变量覆盖。

```bash
vendor/bin/phpunit -c phpunit.postgresql.xml
```

该套件独立于默认 SQLite 功能测试，CI 和预生产门禁需要单独执行。

## 回滚

### Docker Compose

1. 功能仍开启时，在后台将全部托管站切到维护状态，确认站点均为 `paused + maintenance + noindex`。
2. 等待正在发送的托管分发收敛。发生紧急故障时，先停止包含 `distribution` 的队列和调度器：

   ```bash
   docker compose --env-file .env.prod -f docker-compose.prod.yml stop scheduler queue
   ```

3. 将 `GEOFLOW_HOSTED_SITES_ENABLED` 改为 `false`。
4. 重新创建所有读取应用配置的常驻服务：

   ```bash
   docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --force-recreate \
     app web queue knowledge-queue system-update-queue scheduler reverb
   ```

5. 验证托管动态页面和静态资源均返回 404，主站与 Reverb 正常。保留新增表和全部托管业务数据。

### 裸机或虚拟机

先通过实际进程管理器停止队列和调度，随后关闭功能开关，执行 `optimize:clear` 与 `optimize`，再重载 PHP-FPM、队列、调度和 Reverb。Horizon 拓扑在关闭开关前执行 `horizon:pause`，配置更新后执行 `horizon:terminate` 并等待进程管理器拉起。

恢复功能时先确认 DNS、TLS 和入口配置，再开启开关并重载全部进程，最后执行一次 `hosted-sites:reconcile`。开关暂停的队列记录会恢复派发，站点仍需由运营人员明确激活。

单站故障可直接暂停渠道或切到维护状态。索引异常可改回 `noindex`，无需关闭整项功能。
