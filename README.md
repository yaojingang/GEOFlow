# geo.youngtuo.win

geo.youngtuo.win 是给客户演示和交付用的 GEO 项目控制台，当前版本已从旧 Laravel 运行时重整为 Next.js。主域名继续使用 `https://geo.youngtuo.win`，主攻豆包答案可见度，并把客户操作步骤、资料整理、问题集、内容生产、发布监测和项目 Agent 收进一个工作台。

## 交付结构

- `app/`：Next.js App Router 页面、API、SEO 文件。
- `src/components/`：客户展示端和工作台复用组件。
- `src/data/workspace.ts`：项目导航、客户 9 步流程、技能矩阵、配置项文案。
- `src/lib/agent.ts`：工作台 Agent 的讲解/控制权限逻辑。
- `prisma/schema.prisma`：后续接入真实资料库、社交账号、分析工具和报告的数据库模型。
- `legacy/laravel-app/`：旧 Laravel 应用代码归档，保留用于查阅，不再作为默认运行入口。

## 客户操作闭环

1. 录入域名、品牌名、主营产品和目标地区，得到项目基础档案。
2. 上传官网、产品文档、案例、FAQ、报价和资质资料，得到可信资料库。
3. 配置小红书、抖音、公众号、B 站等社交账号，得到内容出口清单。
4. 配置 GA4、百度统计、Search Console 或同类分析工具，得到监测口径。
5. 生成客户常问问题集，得到豆包检索问题池。
6. 在豆包、DeepSeek、Kimi 等平台采样，得到品牌提及和竞品对照。
7. 生成豆包优先的答案素材、FAQ、短视频脚本和官网段落，得到可发布内容。
8. 发布到官网和社交账号，得到外部信源。
9. 每周复测并输出报告，得到下一轮优化清单。

## 本地开发

```bash
npm install
npm run dev -- --hostname 127.0.0.1 --port 18080
```

访问：

- 客户展示端：`http://127.0.0.1:18080`
- 项目工作台：`http://127.0.0.1:18080/workspace`
- 配置中心：`http://127.0.0.1:18080/workspace/settings`
- GetNote 工具：`http://127.0.0.1:18080/getnote`

## GetNote API

GetNote 可把文章、网页、小红书/抖音/YouTube 链接和 PDF/DOCX/TXT/MD/HTML/JSON/CSV 等文件转成 Markdown 笔记。其他项目通过 Workspace API Token 调用：

```text
POST http://localhost:18080/api/v1/getnote/generate
```

Token 在 `/workspace/settings` 创建，scope 选择 `getnote:generate`。完整 curl 和 JavaScript 示例见 `docs/getnote-api.md`。

机器可读规范：

```text
GET http://localhost:18080/api/v1/getnote/openapi.json
```

可运行示例：

```bash
GEO_API_TOKEN=geo_xxx node examples/getnote-api-client.mjs "把这段文字转成笔记。" > note.md
```

## 生产部署

```bash
cp .env.example .env
vi .env
docker compose build web
docker compose up -d postgres redis web worker
```

默认端口是 `18080`，Cloudflare Tunnel / 反向代理继续指向：

```text
http://100.84.235.123:18080
```

## 配置说明

- 域名：`NEXT_PUBLIC_SITE_URL=https://geo.youngtuo.win`
- 豆包：配置 `DOUBAO_API_KEY`、`DOUBAO_BASE_URL`、`DOUBAO_MODEL`
- 定时监测：配置 `CRON_SECRET` 后，由 Cloudflare Cron、系统计划任务或其他受信任调度器调用 `/api/cron/monitor`；步骤见 `docs/monitor-cron.md`
- 分析工具：没有现成账号时先按工作台设置页生成配置指导；已有账号时录入 Property ID 或站点 ID 后再评估是否需要新建
- 社交账号：先录入口径和主页链接，后续再接入真实发布 API
- Agent 控制：默认只讲解项目；必须在设置页开启控制权限后，才能允许改资料、生成内容、发布和重跑任务

## 验证

```bash
npm run lint
npm run build
npx prisma generate
```
