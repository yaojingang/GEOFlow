# GEOFlow 文章批量导出 Markdown 升级方案

> 状态：已确认并完成实施
>
> 目标页面：`/admin/articles`
>
> 方案日期：2026-08-27
>
> 本文件已完成三轮深度 Review，并已按最终方案完成实现与验证。

## 0. Review 结论与修订记录

本轮按深度审查执行。原因是方案预计触及 10 个以上文件，并包含临时文件、签名下载、路径校验、资源限额和管理员权限边界。

审查确认功能方向成立，同时修正了以下问题。实施于 2026-08-27 完成，功能测试、前端测试、生产构建和真实登录态下载验收均已通过。

1. 原方案让前端通过 `fetch` 接收完整 ZIP，再转成 Blob。大压缩包会占用浏览器内存。最终版改为同步准备 ZIP，返回 10 分钟有效的相对签名下载地址，再由浏览器发起原生文件下载。
2. 原方案只有 500 篇数量限制。最终版增加 256 MiB 未压缩 Markdown 总字节预算，覆盖少量超大文章与遗留数据绕过写入校验的情况。
3. 原方案依靠前端禁用按钮和路由限流。最终版增加管理员级原子锁与全局构建容量锁，同一时间只执行一个同步 ZIP 构建，覆盖多标签页、直接构造请求和多管理员同时占满 PHP Worker 的情况。
4. 原方案在响应后立即删除 ZIP，自动下载被浏览器拦截时无法重试。最终版保留 10 分钟签名下载入口，并提供“再次下载”链接；过期文件由每小时计划任务和下次准备时的机会清理共同回收。
5. 原方案对 YAML 字符串转义描述较宽。最终版规定字符串使用 JSON 兼容双引号编码，该表示法符合 YAML 1.2，避免标题、换行、反斜杠和引号破坏 Front Matter。
6. 500 篇上限与当前单页 100 篇选择能力之间的差异继续作为明确产品边界保留，防止为导出功能扩大文章列表查询和 DOM 负担。
7. 原方案使用 `ZipArchive::addFromString()` 写入正文。最终版先把每篇 Markdown 写入独占构建目录，再通过 `addFile()` 归档，避免归档关闭前持有大量正文缓冲区，适配当前 128 MB PHP 内存限制。
8. 原方案可能对最多 500 个 ID 逐条执行存在性校验。最终版让请求层处理输入结构，服务层用集合查询核对文章存在性与软删除状态，控制数据库查询数量。
9. 深度复核发现相对签名地址在二级目录部署下缺少外部路径前缀。最终版在签名生成后通过 `AdminWeb::appPath()` 补全前缀，并用二级目录测试验证签名合同。
10. 深度复核发现下载响应会被 Symfony 标记为公开缓存。最终版显式调用 `setPrivate()`，响应保留 `private` 与 `no-store`，同时排除 `public`。
11. 深度复核发现多字节标题可能超过常见文件系统的 255 字节文件名上限。最终版使用固定短暂存文件名，并把 ZIP 条目名限制为最多 240 个 UTF-8 字节。
12. 深度复核发现多个管理员请求可能同时执行机会清理。最终版为整个清理过程增加共享原子锁，并让构建目录创建在清理竞态下自动恢复父目录。
13. 前端复核覆盖低高度视口、键盘顺序和模块加载时序。最终版让弹窗在动态视口高度内滚动，统一视觉与焦点顺序，允许成功或失败时点击遮罩关闭，并将导出模块静态注册到后台入口。
14. 实施后安全复核发现有效管理员可连续生成大 ZIP。最终版增加每个管理员最多保留 3 个归档、归档合计最多 512 MiB、磁盘安全余量检查和每小时 12 次的管理员准备限流。
15. 实施后架构复核发现不同管理员可同时占满 5 个 PHP-FPM Worker。最终版增加 900 秒全局构建容量锁，未获得容量时返回可恢复的 `409`。
16. 实施后安全复核发现签名链接在有效期内可高频重放。最终版增加令牌、管理员与 IP 三层下载限流，单个令牌 10 分钟最多下载 4 次。
17. 实施后安全复核发现超大 JSON 数组可能在 500 项规则生效前放大验证成本。最终版让超限数组跳过通配符规则展开，并在 Nginx 入口对准备路径设置 128 KiB 请求体上限。
18. 实施后架构复核发现前序分块中的文章可能在构建后被软删除。最终版在 ZIP 关闭后再次核对完整活动文章集合，集合变化时删除归档并返回 `422`。
19. 实施后前端复核补齐脚本未初始化时保持安全禁用、首次下载过期判断、弹窗滚动位置复位、遮罩边界判断，以及 `419`、`429`、HTML 错误页和网络失败的本地化处理。
20. 实施后部署复核把二级目录测试升级为直接请求外部返回路径，并补充生产 Nginx 配置语法检查与真实登录态浏览器下载验收。
21. 最终回归发现缓存服务异常时可能让已获取的管理员锁残留到租期结束。最终版把两把锁纳入统一生命周期，并让释放失败互不影响，锁服务恢复后无需等待另一把锁释放。

### 0.1 实施验证摘要

- 最终导出专项 PHP 测试：47 项通过，335 个断言；本机与 Docker 容器结果一致。
- 导出 JavaScript 模块测试：17 项通过，覆盖表单协作、过期签名、错误响应、本地化与弹窗状态；全量 JavaScript 测试为 78 项通过。
- 工作区差异格式检查、六语种语法检查、生产 Nginx 配置检查和 Vite 生产构建通过。
- 路由检查确认准备与签名下载两条路由；计划任务检查确认清理命令每小时执行。
- 真实管理员登录态完成 2 篇下载与重试，ZIP 内含 2 个独立 Markdown；脚本被阻断时导出动作保持禁用。验收使用的临时管理员及其临时归档已清理。
- 桌面、375×320 和 320×256 隔离浏览器视口完成交互复核；低高度窗口可滚动触达按钮，关闭后焦点回到“执行”，用户浏览器未保留尺寸覆盖。
- 最终全量 PHP 测试结果为 1680 项通过、7 项失败；失败集中在当前工作区其他未完成的 AI 质检语言包、路由登记、任务垃圾箱、UI 路由参数和任务生命周期变更，导出专项测试保持全绿。第二轮专项复核发现的导出资源边界、并发、竞态和前端恢复问题均已修复并加入回归测试。

## 1. 结论

推荐增加“导出 Markdown”批量操作。管理员勾选文章并执行后，页面发起准备请求并持续显示处理中弹窗，服务端在该请求内同步生成 ZIP。ZIP 准备完成后，服务端返回短时有效的相对签名下载地址，浏览器立即发起原生文件下载。服务端按磁盘临时文件方式构建压缩包，逐批读取文章，单次最多接收 500 个唯一、有效、未删除的文章 ID，未压缩 Markdown 总量最多 256 MiB。系统同一时间只执行一个 ZIP 构建，每个管理员最多保留 3 个归档且合计不超过 512 MiB。

本次不引入队列任务、导出记录表或轮询接口。当前数据规模与现有部署的 300 秒网关读取时限适合先采用同步方案，交互成本和维护成本较低。

## 2. 已确认的现状

### 2.1 页面与批量操作

- 路由位于 `routes/web.php`，文章列表路由名为 `admin.articles.index`。
- 页面由 `app/Http/Controllers/Admin/ArticleController.php` 和 `resources/views/admin/articles/index.blade.php` 共同负责。
- 当前批量操作包含发布状态、审核结果、软删除；前端根据所选动作切换提交地址。
- 批量勾选只覆盖当前分页中的文章，切页后不会保留勾选状态。
- 当前列表每页上限为 100。此限制同时存在于控制器和页面数字输入框中。
- 垃圾箱复用同一列表页面，但拥有独立的恢复和永久删除批量动作。

### 2.2 文章内容与运行环境

- `articles.content` 保存 Markdown 原文，站点展示时再渲染为 HTML，因此导出过程无需 HTML 转 Markdown。
- 文章正文当前写入校验上限为 200,000 字符，摘要上限为 10,000 字符。
- 文章使用软删除，导出需要显式排除 `deleted_at` 非空的记录。
- 当前 PHP 运行环境已安装 `ZipArchive`，PHP-FPM 内存上限配置为 128 MB。
- Nginx 应用请求读取时限为 300 秒。
- 当前 Docker 运行实例使用 Laravel 12.64.0、PHP 8.4.24、PostgreSQL、database cache 和独立 scheduler 容器，具备原子锁与计划清理的现成基础。
- 本轮只读统计显示当前有 50 篇活动文章，正文平均约 1,026 字节、最大约 1,473 字节、合计约 51,305 字节。现有实际数据距离同步方案的资源边界较远。
- 项目已有 ZIP 包构建与下载代码，可沿用磁盘临时文件、`ZipArchive`、下载后清理的技术习惯。
- 当前工作区存在大量与本需求无关的未提交改动。实施时必须按本方案文件清单做定向修改，保留其他改动。

## 3. 需求整理与验收定义

| 需求 | 方案定义 |
|---|---|
| 操作入口 | 普通文章列表的“选择操作”中新增“导出 Markdown” |
| 导出对象 | 当前勾选的有效、未删除文章 |
| 文件形态 | 每篇文章生成 1 个独立 `.md` 文件 |
| 批量文件 | 所有 `.md` 文件放入 1 个 ZIP，ZIP 根目录不增加其他清单文件 |
| 数量限制 | 1 至 500 篇，前后端同时校验，服务端限制不可绕过 |
| 体积限制 | 未压缩 Markdown 合计最多 256 MiB，超限时提示拆分导出 |
| 系统容量 | 全局最多同时构建 1 个 ZIP；每管理员最多保留 3 个归档，合计最多 512 MiB |
| 等待反馈 | 执行后立即显示“正在准备 Markdown 压缩包”弹窗和动态加载状态 |
| 下载方式 | ZIP 准备完成后返回 10 分钟有效的相对签名地址，浏览器自动触发原生下载 |
| 失败反馈 | 弹窗切换为失败状态，显示可理解的原因，并允许关闭后重试 |
| 数据影响 | 全流程只读，不更新文章、审核、发布或分发状态 |

完成标准：选中 1、50 或通过接口提交 500 篇文章时能获得结构正确的 ZIP；提交 0、重复、已删除、不存在、超过 500 篇、超过 256 MiB 或超过临时归档配额时得到明确错误；页面在生成期间持续显示状态，成功后自动下载并保留手动重试入口，失败后能恢复操作。

## 4. 范围与边界

### 4.1 本次范围

- 普通文章列表批量菜单和导出弹窗。
- 新增受后台登录、CSRF 和管理员操作日志保护的导出端点。
- 新增受后台登录、相对签名和有效期保护的临时下载端点。
- Markdown 文件编排、跨系统安全文件名、ZIP 构建和临时文件清理。
- 中文、英文、西班牙语、日语、葡萄牙语、俄语界面文案。
- 后端、前端脚本与 ZIP 内容自动化测试。

### 4.2 本次不包含

- 垃圾箱文章导出。
- 跨页累计勾选、按当前筛选条件一键导出全部结果。
- 图片、附件或远端资源的本地化打包；正文中的 Markdown 图片链接保持原样。
- CSV、Word、PDF、HTML 或单篇直接下载。
- 队列、进度百分比、导出历史、站内通知和后台长期保存。
- 调整现有每页最多 100 篇的列表限制。

当前界面正常操作时，一次最多能勾选当前页的 100 篇文章。服务端 500 篇硬限制为导出能力边界，也为后续跨页选择保留兼容空间。继续保持 100 篇列表上限，可以避免为一次导出同时渲染 500 行及其分发关联数据。

## 5. 推荐交互

### 5.1 操作流程

1. 管理员点击“批量操作”，勾选当前页文章。
2. 在“选择操作”中选择“导出 Markdown”。
3. 点击“执行”。
4. 前端先校验数量，随后打开原生 `<dialog>`：
   - 标题：`正在准备 Markdown 压缩包`
   - 主文案：`正在压缩已选择的 :count 篇文章，请稍候。`
   - 辅助文案：`请保持当前页面打开，完成后将自动开始下载。`
   - 状态图形：旋转加载图标配合低频呼吸点，`prefers-reduced-motion` 下停用装饰动画，状态文字持续可见。
5. 前端使用 `fetch` 提交 CSRF 保护的准备请求，等待 JSON 响应。
6. 服务端同步完成 ZIP 后返回 10 分钟有效的相对签名下载地址。
7. 前端校验下载地址为本站相对路径，创建临时 `<a>` 并触发浏览器原生下载，不把 ZIP 读入 JavaScript 内存。
8. 弹窗显示 `压缩完成，下载已开始。`，同时保留“再次下载”和“关闭”按钮；用户关闭后批量栏恢复可操作状态。
9. 请求失败、登录失效、CSRF 失效、并发冲突或响应结构异常时，弹窗显示错误说明和“关闭”按钮，批量选择保持不变，方便重试。

### 5.2 弹窗视觉与可访问性

- 复用 `resources/views/admin/tasks/index.blade.php` 中原生 `<dialog>` 的宽度、圆角、阴影、遮罩和响应式边界，保持 GEOFlow 后台现有视觉语言。
- 加载中拦截 Esc 和遮罩点击，避免请求仍在进行时界面误报结束；错误态和完成态允许关闭。
- 弹窗使用 `aria-modal="true"`、`aria-labelledby`、`aria-live="polite"` 和 `aria-busy`；各状态标题与实时区域提供当前进度说明。
- 打开弹窗后把焦点放到状态容器；关闭后把焦点还给“执行”按钮。
- 加载期间禁用“执行”、动作下拉框和批量开关，阻止同一页面重复提交。
- 使用不确定进度动画，不展示无法由服务端真实计算的百分比。
- 成功态不自动消失，避免浏览器拦截自动下载后失去手动入口；签名过期后需要重新执行导出。

## 6. 系统流程

```text
文章列表复选框
      |
      v
前端导出模块 + 加载弹窗
      |
      | POST prepare：article_ids[] + CSRF
      v
ExportArticlesMarkdownRequest
      |
      | 1..500、唯一、整数
      v
ArticleController::prepareMarkdownExport
      |
      | 管理员锁 + 全局构建容量锁
      v
ArticleMarkdownExportService
      |
      | 集合核对存在/未删除 -> 256 MiB 预算
      | 分块读取 -> Markdown 暂存文件 -> ZipArchive::addFile
      v
storage/app/tmp/article-exports/{adminId}/{token}.zip
      |
      | JSON：10 分钟相对签名下载地址
      v
浏览器原生 GET 下载
      |
      | 后台登录 + signed:relative + token 路径约束
      v
ArticleController::downloadMarkdownExport -> BinaryFileResponse
      |
      v
每小时计划清理 + 下次准备时机会清理
```

数据流单向、无数据库写入、无循环依赖。

## 7. HTTP 合同

### 7.1 准备路由

- 方法：`POST`
- URI：`/admin/articles/batch/export-markdown/prepare`
- 路由名：`admin.articles.batch.export-markdown.prepare`
- 控制器：`ArticleController::prepareMarkdownExport`
- 中间件：沿用当前后台文章路由组，并使用显式读取 `admin` Guard 的命名限流器；每管理员每小时最多 12 次，IP 维度每小时最多 120 次
- 请求体：生产 Nginx 对该准备路径设置 128 KiB 上限，包含 500 个最大整数 ID 的 multipart 请求保持在边界内
- 页面输出 URL 继续通过 `AdminWeb::routePath()` 生成，兼容子目录部署和配置域名与访问域名不同的场景。

### 7.2 准备请求

```text
article_ids[]=499
article_ids[]=498
...
```

校验规则：

- `article_ids`：`required|array|min:1|max:500`
- `article_ids.*`：`integer|min:1|distinct`
- 请求层只校验数组结构、数量、整数和去重，避免对 500 个元素逐条执行存在性查询
- 服务层使用集合查询一次核对全部 ID 必须存在且满足 `deleted_at IS NULL`，并在分块构建期间继续检查集合完整性，覆盖文章被删除的竞态情况

### 7.3 准备成功响应

- 状态码：`200`
- `Content-Type`：`application/json`
- `Cache-Control`：`no-store`

```json
{
  "data": {
    "count": 50,
    "filename": "geoflow-articles-20260827-213000.zip",
    "download_url": "/admin/articles/batch/export-markdown/download/40位随机令牌?owner=7&filename=geoflow-articles-20260827-213000.zip&expires=...&signature=...",
    "expires_at": "2026-08-27T21:40:00+08:00"
  }
}
```

`download_url` 使用 `URL::temporarySignedRoute(..., absolute: false)` 生成，随后通过 `AdminWeb::appPath()` 补全 `APP_URL` 的二级目录前缀。签名同时覆盖令牌、当前管理员 ID、ASCII 下载文件名和过期时间，兼容当前项目的子目录部署合同。

### 7.4 下载路由

- 方法：`GET`
- URI：`/admin/articles/batch/export-markdown/download/{exportToken}`
- 路由名：`admin.articles.batch.export-markdown.download`
- 控制器：`ArticleController::downloadMarkdownExport`
- 中间件：后台登录中间件与 `signed:relative`
- 下载限流：单令牌 10 分钟最多 4 次、单管理员 10 分钟最多 12 次、单 IP 10 分钟最多 30 次
- `exportToken`：40 位大小写字母和数字，路由与服务层双重校验
- 文件归属：签名中的 `owner` 必须等于当前管理员 ID，路径同时固定解析到当前管理员目录；其他管理员持有完整下载地址也无法下载
- 下载文件名：读取签名保护的 `filename` 参数，并再次匹配 `geoflow-articles-\d{8}-\d{6}\.zip` 白名单

### 7.5 下载成功响应

- 状态码：`200`
- `Content-Type`：`application/zip`
- `Content-Disposition`：附件下载
- ZIP 文件名：`geoflow-articles-YYYYMMDD-HHmmss.zip`
- `Cache-Control`：`private, no-store, max-age=0`
- 同一有效签名在 10 分钟内允许再次下载，方便处理浏览器自动下载被拦截的情况

ZIP 外层下载文件名保持 ASCII，符合 Laravel/Symfony 文件下载要求。ZIP 内部条目使用 UTF-8 文件名。

### 7.6 错误响应

- `409`：当前管理员已有准备请求，或系统正在执行另一个 ZIP 构建。
- `422`：无选择、重复 ID、无效 ID、已删除文章、超过 500 篇、超过 256 MiB、临时归档配额或磁盘安全余量不足、构建期间文章集合发生变化。
- `413`：准备请求体超过应用与 Nginx 的 128 KiB 安全边界。
- `429`：请求超过准备或下载专用限流。
- `500`：临时目录不可写、磁盘容量无法读取、文件写入失败、ZIP 扩展不可用、ZIP 创建或关闭失败。
- `403`：下载签名被篡改或已过期。
- `404`：令牌或下载文件名格式错误、文件不存在、文件归属不匹配或路径校验失败。
- 准备请求通过 `Accept: application/json` 与 `X-Requested-With: XMLHttpRequest` 固定 JSON 错误合同，登录失效返回 `401`，CSRF 失效返回 `419`。
- 错误信息使用本地化 JSON 响应，不包含磁盘路径、异常堆栈或文章正文。

## 8. Markdown 与文件名规范

### 8.1 ZIP 内部文件名

格式：`{顺序}-{文章ID}-{安全标题}.md`

示例：`001-499-GEOFlow-页面如何被-AI-更好理解.md`

规则：

- 顺序按用户在页面中的勾选顺序；服务端保留请求顺序。
- 文章 ID 保证唯一，即使安全标题相同也不会覆盖。
- 移除控制字符和 Windows/macOS/Linux 文件名禁用字符，连续空白转为 `-`。
- 完整 ZIP 条目名最长 240 个 UTF-8 字节；按顺序、文章 ID、扩展名占用动态计算安全标题的剩余字节预算，截断时不拆分多字节字符；为空时回退为 `article`。
- ZIP 条目名使用正斜杠和 UTF-8 标志，不接受用户提供的目录片段。

### 8.2 单篇 Markdown 内容

每个文件使用 UTF-8 无 BOM、LF 换行，结构固定为：

```markdown
---
id: 499
title: "GEOFlow 页面如何被 AI 更好理解？"
slug: "geoflow-ai-structured-data"
excerpt: "文章摘要"
category: "GEO 内容工程"
author: "GEOFlow"
original_keyword: "GEOFlow 结构化数据"
keywords: "GEOFlow,结构化数据"
meta_description: "SEO 描述"
status: "published"
review_status: "approved"
is_ai_generated: true
is_hot: false
is_featured: false
created_at: "2026-08-27T10:06:00+08:00"
updated_at: "2026-08-27T10:08:00+08:00"
published_at: "2026-08-27T10:08:00+08:00"
---

# GEOFlow 页面如何被 AI 更好理解？

这里开始原始 Markdown 正文。
```

字符串元数据统一通过 `json_encode()` 生成 JSON 兼容双引号字面量，并启用 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`。该字面量符合 YAML 1.2，能稳定表达引号、反斜杠、换行和 Unicode；空值输出 `null`，布尔值输出 `true` 或 `false`。`keywords` 保留数据库原始文本，关系缺失时 `category` 或 `author` 输出 `null`，日期统一输出带时区的 ISO 8601 字符串。正文中的 CRLF 和 CR 换行统一为 LF，其余 Markdown 语义保持原样，并统一 Front Matter 分隔空行和文件末尾的一个换行。

每篇文件完成编排后，以最终 UTF-8 Markdown 字节串的 `strlen()` 计入总量。累计值超过 `268435456` 字节时立即终止并删除半成品。Front Matter 字段集合固定，导出内容不包含软删除时间、浏览量、任务与来源标题 ID、内部风控结果、分发密钥或管理员数据。

## 9. 后端设计

### 9.1 请求校验类

新增 `app/Http/Requests/Admin/ExportArticlesMarkdownRequest.php`：

- 从导出服务读取 500 篇上限，避免请求层和服务层各自维护常量。
- 只负责数组结构、数量、整数和去重校验；文章存在性由服务层集合查询完成，避免产生最多 500 次逐元素查询。
- 提供顺序稳定、整数化后的 `articleIds()`，控制器不再重复整理输入。

### 9.2 导出服务

新增 `app/Services/GeoFlow/ArticleMarkdownExportService.php`：

- 公共常量 `MAX_ARTICLES = 500`、`MAX_UNCOMPRESSED_BYTES = 268435456`、`DOWNLOAD_TTL_MINUTES = 10`、`PRUNE_AFTER_MINUTES = 60` 作为服务端单一事实来源。
- 容量常量规定 900 秒构建锁、每管理员最多 3 个归档、归档合计最多 512 MiB，以及 512 MiB 最低磁盘安全余量。
- `prepare(int $adminId, array $articleIds)` 在 `storage/app/tmp/article-exports/{adminId}` 下创建 `{token}.zip` 和独占构建目录 `.{token}.building`；`token` 使用密码学安全随机源生成 40 位大小写字母和数字。
- 临时目录和文件在操作系统支持时分别采用 `0700` 与 `0600` 权限，路径组件只来自已认证管理员整数 ID 和服务端令牌。
- 每次准备前执行一次轻量机会清理，只扫描专用导出根目录内超过 60 分钟的常规 `.zip` 和 `.building` 项，不跟随符号链接，不扫描或删除其他目录；共享原子锁保证机会清理与计划清理不会同时遍历目录树。
- 先校验活动文章数量，再把请求 ID 数组按原顺序切成每批 25 个 ID。
- 每批通过 `whereIn` 读取所需字段及分类、作者关系，按 ID 建立索引后依照该批请求顺序写入，既保留用户勾选顺序，也避免最坏 500 篇正文同时进入 128 MB PHP 内存。
- ZIP 关闭后再次集合核对全部文章仍为活动状态，前序分块文章在构建期间被软删除时整体失败并删除已生成归档。
- 每次只生成当前文章的 Markdown 字符串，先累计最终 UTF-8 字节数并检查 256 MiB 预算，再写入构建目录中的固定短暂存文件，并通过带 `ZipArchive::FL_ENC_UTF_8` 标志的 `ZipArchive::addFile()` 加入 ZIP。暂存文件一直保留到 `close()` 完成，随后立即清理构建目录。
- 采用暂存文件可以避免 `addFromString()` 在归档关闭前持有大量正文缓冲区，符合当前 128 MB PHP 内存边界。磁盘峰值由数量与体积双上限约束。
- 检查目录创建、文件写入、`open`、每次 `addFile` 和 `close` 返回值。
- 生产环境使用 256 MiB 默认预算；服务构造器允许测试传入较小预算，以低成本覆盖临界值和超限分支。
- 任一步骤失败时关闭 ZIP 句柄并安全删除构建目录与半成品；成功时返回令牌、绝对路径、下载文件名、文章数和过期时间。
- `resolveDownload(int $adminId, string $token)` 只解析当前管理员目录中的常规 `.zip` 文件，并对令牌格式、路径归属、`realpath` 结果和符号链接逐项防护。
- `pruneExpired()` 只删除专用导出根目录中超过 60 分钟的常规 `.zip` 文件和遗留 `.building` 目录，并安全移除已空的管理员子目录。
- 构建收尾不主动删除管理员目录，目录回收统一交给带锁清理器；清理遍历容忍目录在枚举后消失。

### 9.3 控制器

在 `ArticleController` 注入导出服务并新增两个动作：

- `prepareMarkdownExport()` 接收校验后的 ID 列表，依次获取管理员锁与全局构建容量锁。两把锁的租期均为 900 秒，未获得任一锁时返回 `409`，所有已获得锁在退出路径中释放。
- 准备动作设置管理员活动日志摘要：动作 `export_markdown`，并把原始 `article_ids` 明确替换为仅含文章数量、首个 ID 和末个 ID 的紧凑对象，避免 500 个 ID 扩大审计日志。
- 准备动作调用服务后，通过 `URL::temporarySignedRoute(..., absolute: false)` 生成 10 分钟相对签名地址，再用 `AdminWeb::appPath()` 补全部署子目录，将管理员 ID 和下载文件名一并纳入签名，返回带 `no-store` 的 JSON。
- `downloadMarkdownExport()` 先核对签名参数中的管理员 ID 与 ASCII 文件名，再使用当前管理员 ID 和路由令牌解析文件，并返回 Laravel `response()->download(...)`；响应显式设为私有且禁止缓存，文件在有效期内保留，允许自动下载失败后手动重试。
- 两个动作都使用 ASCII 外层文件名；业务可解释错误返回本地化 JSON 或安全的 `404`，日志记录异常类型、管理员 ID 和文章数，不记录正文、签名或绝对路径。

### 9.4 计划清理

修改 `routes/console.php`，按项目现有惯例注册 `geoflow:prune-article-exports` 闭包命令，并安排每小时执行：

- 命令通过容器注入 `ArticleMarkdownExportService` 并调用 `pruneExpired()`，输出清理数量并返回成功状态。
- 计划任务使用命令名作为稳定标识，启用 `withoutOverlapping()` 和 `onOneServer()`。
- 只处理超过 60 分钟的专用 ZIP。结合 10 分钟签名有效期，为正在下载、网络重试和时钟微小偏差留出余量。
- scheduler 短时不可用时，下次导出准备仍会执行机会清理，防止临时文件无限积累。

## 10. 前端设计

### 10.1 Blade 页面

在 `resources/views/admin/articles/index.blade.php`：

- 普通列表动作下拉框新增 `export_markdown`；垃圾箱不显示。
- 导出选项由服务端默认输出为禁用状态，前端处理器完整注册后才启用，脚本加载失败时不会落入批量状态更新地址。
- 增加导出状态 `<dialog>`，结构沿用任务管理弹窗。
- 使用 `data-*` 输出导出 URL、上限和本地化文案。
- 继续沿用已有复选框与选中数量，不改变其他批量动作表单行为。

### 10.2 独立 JavaScript 模块

新增 `resources/js/admin/article-batch-export.js`，由 `resources/js/app.js` 静态导入；模块只在页面存在导出弹窗时初始化：

- 仅拦截 `export_markdown` 动作，其余批量动作保持现有表单提交路径。
- 使用 `FormData` 发送 `article_ids[]` 和 CSRF token。
- 请求头包含 `Accept: application/json` 和 `X-Requested-With: XMLHttpRequest`，固定准备请求的 JSON 错误合同。
- 显示弹窗、锁定控件并设置 `aria-busy`。
- 同时校验 `response.ok`、`Content-Type` 和 JSON 字段；会话失效返回的登录 HTML进入错误态。
- 要求原始 `download_url` 以单个 `/` 开头且不以 `//` 开头，再通过 `new URL(downloadUrl, window.location.origin)` 核对 `origin` 与当前页面一致；拒绝协议相对地址、绝对外站地址和缺失字段。
- 通过临时 `<a>` 发起浏览器原生 GET 下载；JavaScript 不读取 ZIP 二进制内容，也不创建 Blob。
- 成功态保留服务端返回的下载地址作为“再次下载”按钮目标，签名过期后提示用户重新执行导出。
- 在成功、失败和网络异常路径统一恢复控件，避免页面永久锁定。
- 首次下载和再次下载都会核对签名过期时间；`419` 与 `429` 使用当前语言文案，非 JSON 网关页和网络拒绝进入可恢复错误态。
- 保持已勾选文章，方便失败后直接重试。
- 在捕获阶段拦截导出提交，稳定优先于页面原有批量提交处理器；成功态和错误态支持 Esc、按钮及遮罩关闭。
- 弹窗使用动态视口最大高度和纵向滚动，低高度窗口与高倍缩放下仍能触达操作按钮。
- 状态切换时重置弹窗滚动位置；遮罩点击通过边界坐标识别，点击弹窗边框或滚动条不会误关闭。

## 11. 文件变更清单

最终涉及 26 个功能、验证与方案文件，其中 6 个为现有语言包的同构文案更新。文件数量增加来自安全容量控制、Nginx 请求边界与真实浏览器验收。

### 新增

1. `app/Http/Requests/Admin/ExportArticlesMarkdownRequest.php`
2. `app/Services/GeoFlow/ArticleMarkdownExportService.php`
3. `resources/js/admin/article-batch-export.js`
4. `tests/Unit/GeoFlow/ArticleMarkdownExportServiceTest.php`
5. `tests/JavaScript/article-batch-export.test.js`
6. `tests/Feature/AdminArticleMarkdownExportTest.php`
7. `tests/Unit/GeoFlow/ArticleMarkdownExportGatewayConfigurationTest.php`
8. `tests/Browser/article_markdown_export_smoke.py`
9. `app/Http/Middleware/LimitArticleMarkdownExportRequestSize.php`

### 修改

10. `routes/web.php`
11. `routes/console.php`
12. `app/Http/Controllers/Admin/ArticleController.php`
13. `app/Providers/AppServiceProvider.php`
14. `app/Support/AdminUiRegistry.php`
15. `bootstrap/app.php`
16. `docker/nginx/geoflow-app.conf`
17. `resources/views/admin/articles/index.blade.php`
18. `resources/js/app.js`
19. `tests/Feature/AdminUiV3FullPageSmokeTest.php`
20. `lang/zh_CN/admin.php`
21. `lang/en/admin.php`
22. `lang/es/admin.php`
23. `lang/ja/admin.php`
24. `lang/pt_BR/admin.php`
25. `lang/ru/admin.php`
26. `docs/superpowers/plans/2026-08-27-article-batch-markdown-export-plan.md`

不需要数据库迁移、环境变量、第三方服务、API 密钥、Composer 依赖或 npm 依赖。

## 12. 实施顺序

本功能作为一个原子、可独立合并的阶段实施。路由、服务、页面入口和下载交互需要一起交付，拆成多个发布阶段会产生不可用入口或无入口接口。

1. 编写导出服务单元测试和 HTTP 合同测试，固定 Markdown、ZIP、文件名、数量与体积边界。
2. 增加请求校验类、导出服务、准备与下载控制器动作、两条路由和临时文件计划清理。
3. 增加批量菜单项、原生弹窗和六语种文案。
4. 增加独立前端模块及 JavaScript 测试。
5. 运行格式化、聚焦测试、计划任务检查、构建和手工验收。

## 13. 自动化测试矩阵

### 13.1 服务测试

- 2 篇文章生成 2 个独立 Markdown 条目。
- Front Matter 字段、H1 标题和原始正文正确。
- 中文标题、引号、换行和特殊字符正确转义。
- JSON 兼容字符串可被 YAML 1.2 解析器读取，`null` 与布尔类型保持正确。
- 相同标题不会覆盖，危险路径字符无法生成目录穿越条目。
- 请求顺序与 ZIP 文件顺序一致。
- 使用测试预算覆盖“恰好等于上限时允许、超过 1 字节时整体失败”，测试过程不分配 256 MiB 内存。
- ZIP 创建、写入或关闭失败时半成品被删除。
- 构建期间前序分块文章被软删除时，最终集合复核失败并删除归档。
- 每管理员归档数量和累计字节配额不可绕过，磁盘空间不足时拒绝新构建。
- ZIP 构建完成后暂存 Markdown 与 `.building` 目录立即清理，下载 ZIP 保留。
- 60 分钟内文件不清理，超过 60 分钟的专用临时 ZIP 和遗留构建目录被清理，符号链接和专用目录外文件保持不变。
- 下载解析只能访问当前管理员目录，非法令牌、跨管理员令牌、缺失文件和路径逃逸均失败。
- 正文接近 200,000 字符时仍按分块路径构建。

### 13.2 HTTP 与页面测试

- 未登录准备请求返回现有后台 JSON `401` 合同；未登录下载进入现有后台登录流程。
- 普通文章列表显示“导出 Markdown”，垃圾箱不显示。
- 准备与下载路由使用相对路径，并保留配置的部署子目录。
- 1 篇、50 篇和恰好 500 篇请求成功。
- 0 篇、501 篇、重复 ID、不存在 ID、已删除 ID和超过体积预算返回 `422`。
- 同一管理员并发准备返回 `409`；全局构建容量占用时其他管理员也返回可恢复的 `409`。
- 准备限流按管理员隔离，共享 IP 的另一个管理员保持独立额度；签名下载重放受令牌、管理员和 IP 三层限流。
- 10,000 个 ID 的超限选择不展开逐项规则，生产 Nginx 对准备请求体设置 128 KiB 上限。
- 准备成功响应包含相对签名 URL、文件名、数量和过期时间，且禁止缓存。
- 有效签名下载返回 `application/zip`、正确下载文件名和正确 ZIP 条目数。
- 篡改签名、过期签名、跨管理员令牌、非法令牌和缺失文件不能下载。
- 同一有效签名可在 10 分钟内重复下载，文件随后由计划任务或机会清理回收。
- 导出不更改文章任何字段，也不触发分发任务。
- 管理员日志只保存数量摘要，不保存完整 ID 数组或正文。
- 计划任务已注册为每小时执行，并具备互斥保护。

### 13.3 JavaScript 测试

- 选择导出动作后拦截普通表单提交。
- 无选择和超限时不发请求并显示本地化错误。
- 加载中弹窗开启，控件禁用，重复提交被阻止。
- 准备 JSON 中的本站相对签名地址触发临时链接原生下载，全程不创建 Blob。
- 缺失、格式异常、协议相对或外站下载地址进入错误态。
- 成功态保留“再次下载”入口，关闭后焦点返回执行按钮。
- `401`、`409`、`419`、`422`、`429`、`500`、登录 HTML 和网络异常进入错误态。
- 失败后控件恢复、勾选保持，用户可以重试。
- 非导出批量动作完整放行原表单处理器；脚本未完成初始化时导出选项保持禁用。
- 状态切换重置滚动位置，弹窗边框点击不触发遮罩关闭。

## 14. 验证命令

```bash
php artisan route:list --path=articles --json
php artisan schedule:list
php artisan test --compact tests/Unit/GeoFlow/ArticleMarkdownExportServiceTest.php
php artisan test --compact tests/Unit/GeoFlow/ArticleMarkdownExportGatewayConfigurationTest.php
php artisan test --compact tests/Feature/AdminArticlesPageTest.php
node --test tests/JavaScript/article-batch-export.test.js
python3 tests/Browser/article_markdown_export_smoke.py
vendor/bin/pint --test app/Http/Middleware/LimitArticleMarkdownExportRequestSize.php app/Http/Requests/Admin/ExportArticlesMarkdownRequest.php app/Services/GeoFlow/ArticleMarkdownExportService.php app/Providers/AppServiceProvider.php routes/web.php routes/console.php tests/Unit/GeoFlow/ArticleMarkdownExportServiceTest.php tests/Unit/GeoFlow/ArticleMarkdownExportGatewayConfigurationTest.php tests/Feature/AdminArticleMarkdownExportTest.php
npm run build
```

手工验收页面：`http://localhost:18080/admin/articles`

手工检查状态：

- 桌面当前窗口：默认、打开批量栏、选中、加载、完成、失败。
- 375px 隔离浏览器：批量栏换行、弹窗边界、长语言文案、错误按钮可见。
- 键盘：Tab 焦点、执行后焦点进入弹窗、加载时 Esc 行为、结束后焦点返回。
- 动画减弱：系统开启 `prefers-reduced-motion` 后仍能理解当前状态。
- 下载结果：ZIP 可解压、文件数正确、中文文件名正常、任意 Markdown 阅读器可打开。

## 15. 风险与保护措施

| 风险 | 保护措施 |
|---|---|
| 500 篇大正文占用内存 | 每批 25 篇、逐篇写入暂存文件、`addFile` 归档、256 MiB 未压缩总量预算、浏览器原生下载 |
| ZIP 构建耗时 | 500 篇硬限制、256 MiB 预算、300 秒网关时限、加载弹窗 |
| 多次点击、多标签页或多管理员产生并发 | 前端锁定、管理员级锁、900 秒全局构建容量锁、命名路由限流 |
| 临时文件残留或连续导出耗尽磁盘 | 成功后清理构建目录、异常清理、每管理员 3 个归档与 512 MiB 配额、磁盘安全余量、每小时计划清理、机会清理 |
| 文件名冲突或目录穿越 | ID 前缀、禁用字符清理、长度限制、固定 ZIP 根目录 |
| 临时下载地址泄漏或重放 | 10 分钟相对签名、后台登录、当前管理员目录绑定、禁止缓存、单令牌 4 次下载限额 |
| 浏览器阻止自动下载 | 完成态保留 10 分钟有效的“再次下载”入口 |
| 会话失效时准备请求返回登录页 | 校验状态码、`Content-Type` 和 JSON 结构，HTML 响应进入错误态 |
| 文章在导出中被删除 | 构建前、分块读取时与 ZIP 关闭后三阶段集合复核，变化时整体失败并提示刷新 |
| scheduler 短时不可用 | 每次准备执行机会清理，恢复后每小时清理继续兜底 |
| 多管理员并发造成 CPU 或临时磁盘压力 | 全局构建容量锁、管理员与 IP 限流、归档配额、磁盘安全余量、数量与体积双上限；真实数据继续增长时升级为队列和对象存储 |

## 16. 方案假设、替代方案与回滚

### 16.1 关键假设

本方案假设生产环境提供 `ZipArchive`，应用请求读取时限不低于当前 300 秒，后台会话、应用签名密钥、database cache 与 scheduler 保持可用。服务端数量和体积限制覆盖遗留文章超过现行写入校验的情况。当前活动文章正文总量约 51 KB，同步路线拥有充足余量；生产数据显著增长或代理时限缩短时，再评估队列生成、完成通知和对象存储签名下载。

### 16.2 未采用的替代方案

- 队列异步导出：适合更大数据量、导出历史和对象存储，当前需求明确偏向页面内等待并自动下载，引入任务状态、轮询、文件保留和清理策略会扩大维护面。
- `fetch` 直接接收 ZIP 并创建 Blob：可以精确感知响应完成，但压缩包会进入 JavaScript 内存，500 篇边界下资源风险较高。
- 普通表单直接提交文件：实现量更小，页面难以可靠获知压缩阶段状态，也难以在同一弹窗中处理校验、会话失效和服务端错误。

### 16.3 最小方案

最小实现可以只增加同步文件 POST、ZIP 服务和提交前加载提示。推荐方案增加准备与下载分离、签名有效期、独立前端模块、管理员锁、双重资源限制和临时文件治理，这些能力直接服务于稳定性与可恢复性。

### 16.4 回滚

- 移除批量菜单项、弹窗、前端模块加载、两条导出路由、计划清理事件和服务注入即可回滚。
- 删除专用临时目录内的遗留 ZIP，不涉及用户数据。
- 无数据库结构、文章数据、配置或外部状态需要恢复。

公共实体变化：新增 1 个后台 POST 准备路由、1 个后台 GET 签名下载路由、1 个批量动作、1 个专用临时目录和 1 个计划清理事件。数据实体变化为 0。

## 17. 技术依据

- Laravel 12 文件下载响应：<https://laravel.com/framework/docs/12.x/responses#file-downloads>
- Laravel 12 临时签名 URL：<https://laravel.com/framework/docs/12.x/urls#signed-urls>
- Laravel 12 Cache 原子锁：<https://laravel.com/framework/docs/12.x/cache#atomic-locks>
- Laravel 12 数组、数量、整数和去重校验：<https://laravel.com/framework/docs/12.x/validation>
- PHP `ZipArchive::addFile()`：<https://www.php.net/manual/en/ziparchive.addfile.php>
- PHP `ZipArchive::close()`：<https://www.php.net/manual/en/ziparchive.close.php>

## 18. 确认项

确认本方案即表示接受以下产品决策：

1. 首版采用同步准备、10 分钟相对签名地址和浏览器原生下载，不建设队列导出中心。
2. 每次最多 500 篇且未压缩 Markdown 合计最多 256 MiB，正常界面仍按当前页勾选，当前每页最多 100 篇。
3. 每篇 Markdown 包含 YAML Front Matter、一级标题和原始正文，字符串使用兼容 YAML 1.2 的 JSON 双引号字面量。
4. ZIP 只包含 Markdown 文件，不打包图片与附件。
5. 成功态保留“再次下载”入口，签名 10 分钟后失效；临时 ZIP 由每小时任务和机会清理回收。
6. 垃圾箱、跨页累计选择和按筛选结果全量导出留待独立需求。

确认后可按本文件直接实施，无需重新选择技术路径。
