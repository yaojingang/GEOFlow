# GEOFlow 通用 API 分发渠道开发方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use `executing-plans` or `subagent-driven-development` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在现有 GEOFlow 分发管理中新增“通用 API 渠道”，让文章发布、更新、删除和站点设置同步可以通过可配置 HTTP API 对接第三方 CMS、自动化平台和自研内容系统。

**Architecture:** 沿用当前 `DistributionPublisherInterface`、`DistributionPublisherManager`、`DistributionPayloadBuilder` 和 `ArticleDistribution` 队列机制，新增 `generic_http_api` 渠道类型和 `GenericHttpApiPublisher`。第一版不追求“自动适配所有平台”，而是提供稳定的 GEOFlow 标准载荷、认证配置、端点配置、响应字段映射和可测试的通用 HTTP 分发能力。

**Tech Stack:** Laravel 12、Eloquent、Blade、Laravel HTTP Client、PHPUnit Feature/Unit Tests、现有分发队列 `distribution`。

---

## 1. 背景判断

当前分发能力已经具备可扩展基础：

- `DistributionPublisherInterface` 已统一抽象 `health`、`publish`、`update`、`delete`、`syncSiteSettings`。
- `DistributionPublisherManager` 当前按 `channel_type` 分派到 `geoflow_agent` 或 `wordpress_rest`。
- `DistributionPayloadBuilder` 已生成统一文章载荷，包含 Markdown、HTML、分类、作者、任务和图片资产。
- `DistributionChannel.channel_config` 是 JSON 字段，可承载不同渠道的配置，不需要第一版新增大量数据表。
- `ArticleDistribution` 已负责队列状态、远程 ID、远程 URL、尝试次数、错误信息和分发日志。

因此建议新增一种渠道类型：`generic_http_api`，后台名称为“通用 API”。

## 2. 推荐边界

### 2.1 要做

- 新增“通用 API”渠道类型。
- 支持把文章发布、更新、删除发送到外部 HTTP API。
- 支持健康检查 endpoint。
- 支持站点设置同步 endpoint，第一版可选。
- 支持常见认证方式：
  - 无认证
  - Bearer Token
  - Basic Auth
  - Header API Key
  - GEOFlow HMAC 签名
- 支持每个动作配置独立 HTTP 方法和路径。
- 支持响应字段映射：
  - `remote_id`
  - `remote_url`
  - 可选 `remote_status`
- 支持请求预览和测试连接。
- 保持现有分发队列、日志、重试策略和任务绑定逻辑。

### 2.2 不做

- 不做“自动识别所有平台 API”。
- 不做复杂 OAuth 授权流程。
- 不在第一版实现脚本式字段转换器。
- 不把 WordPress 迁移成通用 API；WordPress 仍保留原生 Connector，因为图片、分类、标签和设置同步有平台语义。
- 不新增独立插件市场或动态 PHP 插件加载机制。

## 3. 产品设计

### 3.1 渠道类型

后台“新建分发渠道”增加第三个选项：

1. GEOFlow Agent
2. WordPress REST
3. 通用 API

通用 API 文案建议：

- 名称：`通用 API`
- 描述：`通过可配置 HTTP API 将文章发布、更新和删除同步到第三方系统、自动化平台或自研 CMS。`

### 3.2 通用 API 配置区

选择“通用 API”后展示以下模块。

#### 基础 endpoint

- API 基础地址：如 `https://example.com/api`
- 健康检查路径：如 `/health`
- 发布路径：如 `/articles`
- 更新路径：如 `/articles/{remote_id}` 或 `/articles/{slug}`
- 删除路径：如 `/articles/{remote_id}`
- 站点设置同步路径：可选；如 `/site-settings`，留空则跳过远程设置同步

健康检查、发布、更新和删除路径为必填；后台只接受路径，不接受完整 URL，完整 API 地址由“API 基础地址 + 路径”拼接得到。

路径变量支持：

- `{article_id}`
- `{slug}`
- `{remote_id}`
- `{channel_id}`

#### HTTP 方法

每个动作可配置方法，但按动作限制到可真实携带载荷的安全集合：

- 发布：默认 `POST`，可选 `PUT` / `PATCH`
- 更新：默认 `POST`，可选 `PUT` / `PATCH`
- 删除：默认 `DELETE`，可选 `POST`
- 健康检查：默认 `GET`，可选 `POST`
- 站点设置同步：默认 `POST`，可选 `PUT` / `PATCH`

#### 认证方式

认证类型：

- `none`
- `bearer`
- `basic`
- `header_key`
- `hmac`

敏感值保存到 `distribution_channel_secrets.secret_ciphertext`。

非敏感配置保存到 `distribution_channels.channel_config`，例如 header 名称、用户名、签名 header 名称、超时时间等。

#### 响应映射

默认映射：

- 远程 ID：`id`
- 远程链接：`url`

允许用户填写点路径：

- `data.id`
- `data.url`
- `post.id`
- `post.link`

如果外部接口不返回 ID，系统仍可标记成功，但 `remote_id` 为空；后续更新/删除若依赖 `{remote_id}`，需要提示配置不可用。

#### 成功状态码

默认成功状态码：

- `200`
- `201`
- `202`
- `204`

后台允许用逗号配置：`200,201,202,204`。

### 3.3 渠道详情页

通用 API 渠道详情页展示：

- 渠道类型：通用 API
- API 基础地址
- 认证方式
- 支持动作：发布、更新、删除、站点设置同步
- 健康检查状态
- 最近请求摘要
- 最近分发日志
- 示例 Payload
- 测试连接按钮
- 发送测试文章按钮，第一版可只做测试连接，测试文章可作为后续增强。

### 3.4 分发日志

日志继续使用 `distribution_logs`，但通用 API 的上下文中应记录：

- `http_method`
- `endpoint`
- `status_code`
- `response_summary`
- `remote_id_path`
- `remote_url_path`
- `auth_type`

敏感 header、token、password、Authorization 内容不能进入日志。

## 4. 技术设计

### 4.1 新增渠道类型

扩展 `DistributionChannel::channelType()`：

- 允许 `generic_http_api`

扩展 `DistributionController::validateChannel()`：

- `channel_type` 增加 `generic_http_api`
- 对通用 API 配置做条件校验

扩展 `DistributionPublisherManager`：

- `generic_http_api` 分派到 `GenericHttpApiPublisher`

### 4.2 配置结构

`channel_config` 建议结构：

```json
{
  "generic_auth_type": "bearer",
  "generic_basic_username": "",
  "generic_header_name": "X-API-Key",
  "generic_hmac_key_id_header": "X-GEOFlow-Key-Id",
  "generic_hmac_signature_header": "X-GEOFlow-Signature",
  "generic_hmac_timestamp_header": "X-GEOFlow-Timestamp",
  "generic_timeout_seconds": 30,
  "generic_success_statuses": [200, 201, 202, 204],
  "generic_health_method": "GET",
  "generic_health_path": "/health",
  "generic_publish_method": "POST",
  "generic_publish_path": "/articles",
  "generic_update_method": "POST",
  "generic_update_path": "/articles/{remote_id}",
  "generic_delete_method": "DELETE",
  "generic_delete_path": "/articles/{remote_id}",
  "generic_settings_method": "POST",
  "generic_settings_path": "",
  "generic_remote_id_path": "id",
  "generic_remote_url_path": "url",
  "generic_payload_wrapper": "none"
}
```

第一版 `generic_payload_wrapper` 只支持：

- `none`：直接发送 GEOFlow 标准载荷
- `data`：发送 `{ "data": <payload> }`

不做任意字段模板转换。

### 4.3 密钥策略

复用 `distribution_channel_secrets`：

- Bearer Token：`secret_ciphertext` 保存 token
- Basic Auth：`key_id` 可保存用户名标识，`secret_ciphertext` 保存密码；用户名仍建议存 `channel_config.generic_basic_username`
- Header API Key：`secret_ciphertext` 保存 API key
- HMAC：`key_id` 保存 key id，`secret_ciphertext` 保存签名密钥
- 无认证：不创建 secret，或创建 scopes 为空的 secret；建议不创建 secret，Publisher 根据认证方式判断

建议 scopes：

- `generic.http`
- `article.publish`
- `article.update`
- `article.delete`
- `site.settings.update`
- `health.check`

### 4.4 标准 Payload

沿用 `DistributionPayloadBuilder` 当前输出，版本保持 `1.0`。通用 API 第一版直接发送完整载荷：

```json
{
  "version": "1.0",
  "source": "geoflow",
  "event": "article.publish",
  "article": {
    "id": 123,
    "title": "文章标题",
    "slug": "article-slug",
    "excerpt": "摘要",
    "content": "Markdown 正文",
    "content_format": "markdown",
    "content_html": "<p>HTML 正文</p>",
    "hero_image_url": "https://example.com/image.jpg",
    "keywords": "关键词",
    "meta_description": "SEO 描述",
    "status": "published",
    "published_at": "2026-05-30T00:00:00Z",
    "updated_at": "2026-05-30T00:00:00Z",
    "category": {
      "id": 1,
      "name": "分类",
      "slug": "category"
    },
    "author": {
      "id": 1,
      "name": "作者"
    },
    "task": {
      "id": 57,
      "name": "任务名称"
    }
  },
  "assets": {
    "images": []
  }
}
```

### 4.5 请求 Header

所有通用 API 请求默认加：

- `Content-Type: application/json`
- `Accept: application/json`
- `User-Agent: GEOFlow/2.x`
- `X-GEOFlow-Event`
- `X-GEOFlow-Idempotency-Key`
- `X-GEOFlow-Payload-SHA256`

HMAC 模式可复用现有 `DistributionSigningService`，但要注意通用 API 的 path 是用户配置路径，不是固定 `/geoflow-agent/v1/...`。

### 4.6 响应解析

新增 `GenericHttpResponseMapper`：

- 输入：HTTP JSON 响应、配置的字段路径
- 输出：
  - `remote_id`
  - `remote_url`
  - `remote_meta`

点路径规则：

- `id` 读取 `$json['id']`
- `data.id` 读取 `$json['data']['id']`
- 字段不存在时返回空字符串，不抛异常

### 4.7 错误处理

通用 API Publisher 需要对以下情况给出明确错误：

- endpoint 为空
- URL scheme 不是 `http` / `https`
- update/delete 使用 `{remote_id}` 但当前分发记录没有 `remote_id`
- HTTP 状态码不在成功状态码列表
- 响应不是 JSON，但需要字段映射
- 请求超时
- DNS/连接失败

错误摘要最多保留 500 字符，不记录敏感 header。

### 4.8 安全约束

- 只允许 `http` 和 `https`。
- 默认不跟随过多重定向，最多 3 次。
- 超时时间限制 5 到 120 秒，默认 30 秒。
- Authorization、API Key、Basic Password、HMAC Secret 不写入日志。
- 配置展示时敏感值只显示“已配置”，编辑时留空表示不变。
- 对私网地址不强制拦截，因为用户可能部署在内网；但 UI 要提示“请只配置可信接口，外部 API 将接收文章正文和图片资产”。

## 5. 文件改动规划

### 5.1 后端服务

- 修改：`app/Models/DistributionChannel.php`
  - 支持 `generic_http_api`
  - 增加 `isGenericHttpApi()`
  - 增加 `resolvedGenericHttpConfig()`

- 修改：`app/Services/GeoFlow/DistributionPublisherManager.php`
  - 注入 `GenericHttpApiPublisher`
  - `match` 增加 `generic_http_api`

- 新增：`app/Services/GeoFlow/GenericHttpApiPublisher.php`
  - 实现 `DistributionPublisherInterface`
  - 负责 publish/update/delete/health/syncSiteSettings

- 新增：`app/Services/GeoFlow/GenericHttpRequestFactory.php`
  - 根据认证方式构造 Laravel HTTP Client
  - 注入认证 header
  - 控制 timeout、acceptJson、content type

- 新增：`app/Services/GeoFlow/GenericHttpResponseMapper.php`
  - 解析远程 ID、远程 URL 和响应元信息

- 可选新增：`app/Services/GeoFlow/GenericHttpEndpointResolver.php`
  - 替换 `{slug}`、`{remote_id}` 等路径变量
  - 校验缺失变量

### 5.2 Controller

- 修改：`app/Http/Controllers/Admin/DistributionController.php`
  - `validateChannel()` 增加通用 API 字段
  - `normalizeChannelConfig()` 支持 `generic_http_api`
  - `store()` 支持通用 API secret 创建
  - `update()` 支持敏感值留空不变、填写则轮换
  - `createChannelSecret()` 或新增 `createGenericHttpSecret()`

### 5.3 Blade UI

- 修改：`resources/views/admin/distribution/create.blade.php`
  - 增加通用 API 渠道类型卡片
  - 增加通用 API 配置面板

- 修改：`resources/views/admin/distribution/edit.blade.php`
  - 增加通用 API 配置面板
  - 敏感值显示“已配置，留空不修改”

- 修改：`resources/views/admin/distribution/show.blade.php`
  - 通用 API 渠道显示 API 配置摘要、响应映射、示例 Payload
  - 不展示 Agent 包下载模块
  - 不展示 WordPress 专属引导

- 修改：`resources/views/admin/distribution/index.blade.php`
  - 通用 API 类型名称和描述本地化

### 5.4 翻译

- 修改：
  - `lang/zh_CN/admin.php`
  - `lang/en/admin.php`
  - `lang/pt_BR/admin.php`

新增文案：

- 渠道类型
- 通用 API 表单字段
- 认证方式
- 响应映射
- 验证错误
- 测试连接结果

### 5.5 测试

- 修改：`tests/Unit/DistributionPublisherManagerTest.php`
  - 增加通用 API publisher 分派测试

- 新增：`tests/Unit/GenericHttpResponseMapperTest.php`
  - 测试点路径解析
  - 测试字段缺失返回空

- 新增或修改：`tests/Unit/GenericHttpApiPublisherTest.php`
  - 测试 Bearer/Header/HMAC 请求 header
  - 测试 publish 成功映射 remote_id/remote_url
  - 测试 update/delete 缺失 remote_id 报错
  - 测试失败状态码错误摘要

- 修改：`tests/Feature/AdminDistributionPageTest.php`
  - 测试创建通用 API 渠道
  - 测试编辑通用 API 渠道保留敏感值
  - 测试详情页显示通用 API 配置，不显示 Agent 包和 WordPress 引导
  - 测试任务分发通用 API 成功记录远程链接

## 6. 分阶段开发计划

### Phase 1：模型、配置和基础 UI

- [ ] 扩展 `DistributionChannel` 支持 `generic_http_api`。
- [ ] 扩展渠道创建/编辑校验。
- [ ] 增加通用 API 表单字段和翻译。
- [ ] 增加创建/编辑/详情页的基本展示。
- [ ] 跑 `php artisan test tests/Feature/AdminDistributionPageTest.php`。

交付标准：

- 后台可以创建、编辑、查看通用 API 渠道。
- 敏感值不会明文回显。
- 现有 GEOFlow Agent 和 WordPress 页面不回归。

### Phase 2：通用 HTTP Publisher

- [ ] 新增 `GenericHttpResponseMapper`。
- [ ] 新增 `GenericHttpRequestFactory`。
- [ ] 新增 `GenericHttpApiPublisher`。
- [ ] 接入 `DistributionPublisherManager`。
- [ ] 用 `Http::fake()` 写发布、更新、删除、健康检查测试。

交付标准：

- 文章发布能 POST 到配置的外部 API。
- 成功响应能写入 `remote_id` 和 `remote_url`。
- 失败响应能进入现有失败/重试流程。

### Phase 3：测试连接、预览和日志增强

- [ ] 通用 API 健康检查展示 endpoint、状态码和响应摘要。
- [ ] 渠道详情页展示示例 Payload。
- [ ] 分发日志 context 增加通用 API 请求摘要。
- [ ] 敏感 header 脱敏。

交付标准：

- 管理员在保存渠道后能理解“会发什么、发到哪里、返回什么算成功”。
- 出错时能通过日志定位接口地址、状态码和响应摘要。

### Phase 4：文档和回归测试

- [ ] 更新分发管理文档。
- [ ] 更新 README 功能说明中的分发能力。
- [ ] 补充通用 API 接收方示例。
- [ ] 跑完整测试 `php artisan test`。

交付标准：

- 第三方开发者可以按文档写一个接收端。
- 后台用户可以按 UI 创建通用 API 渠道并完成一次测试分发。

## 7. 建议的第一版默认值

```text
认证方式：Bearer Token
发布方法：POST
发布路径：/articles
更新方法：POST
更新路径：/articles/{remote_id}
删除方法：DELETE
删除路径：/articles/{remote_id}
健康检查方法：GET
健康检查路径：/health
成功状态码：200,201,202,204
remote_id 映射：id
remote_url 映射：url
超时：30 秒
Payload 包装：none
```

## 8. 验收用例

### 8.1 后台配置

- 管理员可以创建通用 API 渠道。
- 管理员可以选择认证方式并保存。
- 编辑时敏感值不明文显示。
- 留空敏感值不会清空已有密钥。
- 更换敏感值会撤销旧 secret 并创建新 secret。

### 8.2 分发行为

- 发布文章时，请求发送到 `publish_path`。
- 更新文章时，请求发送到 `update_path`。
- 删除远端副本时，请求发送到 `delete_path`。
- 响应中的 `remote_id` 和 `remote_url` 被正确写回。
- HTTP 500 或非成功状态码会标记失败并记录摘要。

### 8.3 兼容性

- GEOFlow Agent 渠道仍可创建、下载站点包、分发文章。
- WordPress REST 渠道仍可创建、同步图片、分类和标签。
- 任务创建/编辑页面仍能选择三类渠道。
- 数据分析和分发队列页面能正常显示通用 API 渠道。

## 9. 风险和取舍

### 9.1 最大风险：用户期待“万能适配”

通用 API 不能自动理解所有第三方平台的字段语义。第一版应明确定位为：

> 适配能接收 HTTP JSON 的系统；复杂平台用原生 Connector。

### 9.2 第二风险：配置复杂

如果把字段模板、条件转换、OAuth、媒体上传都放进第一版，UI 会失控。第一版只做 endpoint、认证、响应映射和标准载荷。

### 9.3 第三风险：安全与日志泄露

文章正文、图片资产和认证信息都属于敏感数据。必须做到：

- 不记录认证值。
- 不回显密钥。
- 错误摘要截断。
- UI 明确提示外部接口会收到完整文章内容。

## 10. 未来增强

- 预设模板：
  - n8n Webhook
  - Make Webhook
  - Zapier Webhook
  - Pipedream Webhook
  - Ghost Admin API
  - Strapi
  - Directus
- 字段映射模板：
  - `article.title` → `title`
  - `article.content_html` → `content`
  - `article.slug` → `slug`
- 媒体上传子流程。
- OAuth 2.0 授权型 Connector。
- Connector SDK 文档。

## 11. 自检结果

### 11.1 覆盖检查

- 渠道类型扩展：已覆盖。
- 后台配置 UI：已覆盖。
- 认证方式：已覆盖第一版常见认证。
- 发布、更新、删除：已覆盖。
- 健康检查：已覆盖。
- 响应映射：已覆盖。
- 日志与安全：已覆盖。
- 测试路径：已覆盖单元测试和 Feature 测试。

### 11.2 范围检查

方案没有引入新运行时、没有新增队列系统、没有重构现有 Agent/WordPress 分发。第一版可以独立上线，即使后续不做预设模板，也能通过通用 HTTP API 对接外部系统。

### 11.3 风险检查

最脆弱假设是：目标系统能够接收 GEOFlow 标准 JSON 或能通过 n8n/Make 等中间层转换。若该假设不成立，应新增原生 Connector，而不是继续扩大通用 API 的配置复杂度。

### 11.4 可回滚性

代码层可回滚；数据层第一版不新增表结构，仅使用已有 `channel_type` 和 `channel_config`。如果已创建 `generic_http_api` 渠道后回滚代码，这些渠道在旧代码中会被当作未知类型，建议回滚前暂停或删除通用 API 渠道。

## 12. 建议确认点

开发前建议确认以下 3 点：

1. 第一版是否只做“标准 JSON 通用 API”，不做任意字段转换模板。
2. 默认认证方式是否设为 Bearer Token。
3. 通用 API 渠道是否不提供目标站点包下载，只提供接口配置和示例 Payload。
