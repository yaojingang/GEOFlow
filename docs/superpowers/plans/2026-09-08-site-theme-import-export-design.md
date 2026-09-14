# GEOFlow 模板导入与导出设计

日期：2026-09-08

状态：设计完成，待实施

适用范围：GEOFlow Laravel 主站、后台「网站设置 → 网站模板」

## 1. 建议与交付目标

增加标准 ZIP 模板包，在现有网站模板模块完成「导出所选模板 → 客户实例上传检查 → 安装为未启用模板 → 预览 → 单独启用」。客户模板存入持久化 storage，官方内置模板继续随应用发布。

首版支持完整模板的跨实例交付，包含模板页面、样式、脚本和自带品牌素材。文章、分类、线索记录、账号、站点配置和首页模块配置继续留在目标实例管理。

首版采用新模板安装：相同标识、相同内容重复导入直接返回已有模板；相同标识、不同内容拒绝覆盖。完整版本更新、在线改名、删除、模板市场、远程 URL 安装、自动同步和公共 API/CLI 均不纳入首版。

## 2. 已核实的现状

以下结论来自当前代码和本地路由回读。早期设计文档中的功能设想不作为已实现能力。

| 能力 | 当前状态 | 代码依据 |
| --- | --- | --- |
| 模板识别与切换 | 扫描 `resources/views/theme`；通过 `active_theme` 选择模板 | `app/Support/Site/SiteThemeCatalog.php`、`SiteThemeViewResolver.php` |
| 复刻任务下载 ZIP | 已实现，依赖复刻任务、当前草稿版本、文件清单与检查结果 | `ThemeReplicationPackageService::createPackage()` |
| 任意已安装模板导出 | 尚无通用入口；手工制作的客户模板没有对应复刻任务 | `routes/web.php`、`SiteThemeReplicationController::downloadPackage()` |
| 复刻任务“发布” | 当前实现生成审查包，不安装进运行主题目录 | `ThemeReplicationPublishService::publish()` |
| 完整模板 ZIP 导入 | 当前路由中不存在 | 本地 `php artisan route:list --json` |
| 首页设计 JSON 导入 | 已实现，更新首页模块和样式 | `SiteSettingsController::importHomepageModuleDesign()` |
| 草稿预览 | 使用应用自带的静态内容，不执行草稿 Blade | `ThemePreviewRenderer`、`AdminSiteThemeReplicationTest` |
| 持久化目录 | local 磁盘实际根目录为 `storage/app/private`；生产 Compose 挂载整个 storage | `config/filesystems.php`、`docker-compose.prod.yml` |
| 现有主题资源入口 | `site.asset` 已覆盖 `/themes/...`，并检查托管站的模板归属与认证 | `routes/web.php`、`HostedAssetController` |
| 静态资源转发 | 开发 Nginx 可回退至应用；生产静态扩展匹配未命中时返回 404 | `docker/nginx/local.conf`、`default.conf.template` |

当前应用版本声明为 3.0.0，Composer 要求 PHP ^8.3、Laravel ^12.0。本地已具备 ZipArchive，zip 扩展版本 1.22.7。

现有复刻包的默认上限为 500 个文件、单文件 5 MiB、解包内容合计 25 MiB。现有安全规则还会拦截 `@php`，因此原生 Blade 客户模板需要单独的可信模板安装流程；保留 AI 草稿的原有检查与静态预览边界。

## 3. 用户操作

### 导出

在现有模板选择区域增加「导出所选模板」，操作对象明确显示模板名称和版本。无需先启用，也无需创建复刻任务。

确认页展示模板名称、版本、客户私有标记、页面清单、自带素材数量、包体积与所需 GEOFlow 环境。主按钮为「下载模板包」。下载是管理员主动的本地交付，不连接 GitHub 或第三方服务。

导出包仅覆盖模板所属文件。模板中写入的客户品牌文案和图片属于导出内容，确认页必须说明。`distribution.visibility`、客户备注和来源记录随包保留；私有属性不会阻止授权管理员下载自己的交付文件。

### 导入

模板模块增加「导入模板」入口。使用三步页面：

1. **上传模板包**：接收单个 ZIP，展示有效上传上限。
2. **检查与确认**：显示名称、版本、文件清单、支持页面、环境依赖、客户私有属性、同名检查结果；可查看包内提供的预览图片与转义后的源码文本。
3. **安装完成**：显示「已安装，尚未启用」，提供「预览模板」和「返回模板列表」。

上传检查阶段不执行、编译或包含包内 Blade。安装前必须由超级管理员确认来源可信，按钮旁说明「模板会参与网站运行，请确认来自您信任的开发者」。安装授权与网站启用分别操作。

### 安装后预览与启用

预览覆盖首页、分类、文章、关于页、归档首页、月度归档，使用目标实例的真实内容。没有分类或文章时，展示对应空态并说明无法预览的页面，不创建演示数据。

预览使用管理员专属路由和请求内主题上下文，不写入 `active_theme`。预览内部链接留在预览路由，表单不提交，文章阅读计数和分析代码不执行。页面外围明确显示「预览中」。

原有主题切换流程继续承担启用动作。安装完成不会改变前台访客看到的模板。首版新增导入模板的启用需要超级管理员，已有内置模板的权限规则保持现状。

### 主要状态与提示

| 情况 | 结果 |
| --- | --- |
| 新标识且检查通过 | 安装后进入模板列表，状态为未启用 |
| 同一标识且内容指纹相同 | 显示「此模板已安装」，不重复写入 |
| 同一标识且内容不同 | 显示「已有同标识模板，本版本不支持覆盖安装」；由开发者制作使用独立标识的交付包 |
| 与官方内置模板标识冲突 | 拒绝导入，避免遮蔽内置主题 |
| 包格式、依赖或路径检查失败 | 展示具体失败项，允许重新上传 |
| 上传记录过期或属于另一管理员 | 拒绝继续安装，要求重新上传 |
| storage 不可写、空间不足 | 停止安装，已有主题继续工作 |
| 安装后预览渲染失败 | 显示模板诊断信息，保留当前启用主题 |

首版不自动重命名包内主题：现有 Blade、CSS、JS 可能硬编码主题路径，简单字符串替换无法保证改名正确。

## 4. 模板包接口

格式名称为 `geoflow-theme-package`，格式版本为 `1`。ZIP 根目录增加 `package.json`，其余内容沿用现有复刻 ZIP 的两个前缀：

- `resources/views/theme/{theme_id}/`：manifest、Blade、tokens、mapping、可选文本说明。
- `public/themes/{theme_id}/`：CSS、JS、图片、字体和可选预览图片。

这是包内逻辑路径。安装器将其映射到持久化目录，不照着 ZIP 路径写入应用源码。

`package.json` 必须提供以下字段：

| 字段 | 定义 |
| --- | --- |
| `format`、`format_version` | 固定格式名与版本 1 |
| `theme.id`、`theme.name`、`theme.version` | 与主题 manifest 一致，主题版本作为显示字符串 |
| `created_at`、`exported_with` | 导出时间、实际 GEOFlow 版本、PHP/Laravel 主版本、模板契约版本 |
| `requires` | PHP/Laravel/GEOFlow 兼容范围，以及需要的公共视图、路由、资源 |
| `distribution` | 客户私有属性与交付备注；不携带管理员身份或服务器路径 |
| `pages` | 六类标准页面中自带与回退页面的列表 |
| `files` | 每个文件的包内路径、字节数、SHA-256 |
| `content_sha256` | 按路径排序后的文件清单、字节数与文件 SHA-256 形成的内容指纹 |

外层 ZIP 的生成时间、压缩实现差异不会改变模板内容指纹。`package.json` 不递归计算自身校验值；下载或上传记录另外保存完整 ZIP 的 SHA-256。

页面和模板自带素材使用原文件字节，不打包文章、分类、作者、线索表单定义或线索提交记录，不读取 `.env`、日志、上传总目录、数据库、AI 提示词和复刻任务日志。

公共视图、路由、`assets/css/style.css`、`assets/css/custom.css`、`js/lucide.min.js` 等由目标 GEOFlow 提供，包声明依赖并由导入检查核验。`base_template_id` 作为设计来源保留；只有真正引用其他主题文件时，才把该主题声明为运行依赖。

导出器读取 manifest 的显式依赖，并补充识别字面量视图、路由与资源引用；无法确定的动态依赖列为未核验项，要求开发者补齐依赖声明后重新导出。首版默认兼容约束锁定导出端 GEOFlow 版本，包作者可在验证后提供更宽范围。导入端还必须支持包声明的模板契约，不能仅凭相同版本号放行。

现有无 `package.json` 的复刻 ZIP 继续作为审查包下载。首版导入明确提示其缺少标准清单，不静默猜测兼容性；已通过人工审查的主题先安装到开发实例，再通过通用导出生成标准包。已有复刻 ZIP 的格式与安全测试保持兼容。

## 5. 持久化与运行时

使用 local 磁盘专属目录 `geoflow-site-themes`，当前实际位置为 `storage/app/private/geoflow-site-themes`：

- `uploads/{admin_id}/{token}/`：待检查 ZIP、检查结果、期限与归属信息。
- `exports/{admin_id}/{token}/`：私有下载文件与下载元数据。
- `staging/{token}/`：已验证文件的独占安装暂存目录。
- `installed/{theme_id}/`：完整安装包逻辑目录和服务器生成的安装收据。
- `locks/`：安装与清理使用的锁文件。

安装收据记录格式版本、模板内容指纹、安装时间、管理员 ID 与文件清单。无需新建数据库表；沿用现有管理员操作日志记录导出、检查、安装、失败及启用，不把文件正文、源码、令牌和敏感配置写入日志。

首版只增加新标识安装。先完成 staging，再在持有锁时重新确认标识无冲突，通过同一文件系统的目录 rename 一次性安装。Catalog 只识别 `installed` 中具备合法安装收据的完整目录，忽略上传与暂存区。重复请求返回已完成安装的收据。

扩展 `SiteThemeCatalog` 合并内置主题与已安装主题，返回来源、私有属性、已安装状态及可导出能力。继续使用同一个主题 ID 和 `theme.{id}.*` 视图名。首版已安装模板用于主站，`hostedCompatible()` 不自动收录已安装模板；托管渠道的认证与归属检查保留。

每个已安装主题的 `resources/views` 作为额外视图查找位置注册，保留现有 `theme.*.layout` composer。注册前按收据确认目录和主题 ID，禁止通过另一个目录覆盖内置主题或其他主题。请求使用固定的 Catalog 快照，避免处理中途发生目录切换。

扩展现有 `site.asset` 路由的 `HostedAssetController`：主站请求 `/themes/{themeId}/{assetPath}` 时，在现有内置资源未命中的情况下，从已安装模板的 `public/themes/{themeId}` 和收据允许文件中读取静态资源。无需新增第二条资源路由，保留现有 URL。禁止返回 Blade、JSON 清单、安装收据或下载包。响应使用明确 MIME、`nosniff`、内容 ETag 和合理缓存。托管站仍走原有认证和归属校验，首版不进入私有模板的资源回退。

生产 Nginx 为 `/themes/` 增加专属受控回退：优先读取已有内置资源，未命中时交给应用资源路由；其他静态路径仍沿用原规则。应用资源路由继续拦截 PHP、目录穿越及非允许扩展，不能因 Nginx 前缀优先级绕过检查。开发与生产配置分别验收。

生产 Compose 已持久化 storage，本次沿用该挂载。模板目录与其他 storage 数据一起备份，不进入 Git、公开源码包或构建上下文。现有客户源码目录不会在实施时自动搬迁或删除；迁移交付由独立操作完成。

## 6. 校验与可信来源边界

采用 PHP 自带 ZipArchive 和 Laravel 文件校验，不增加第三方解压包、外部服务、账户或 API Key。

首版默认上限：上传及导出 ZIP 10 MiB、500 个普通文件、单文件 5 MiB、实际解包内容合计 25 MiB。边读边计数，不信任 ZIP 头部声明值；具体数值集中在 `config/geoflow.php` 的 `theme_packages` 配置中，不新增环境变量。导出关闭 ZIP 后再次检查压缩包大小，超限不给出可下载成品。

当前 Docker PHP 单文件上传限制为 12 MiB、POST 为 64 MiB，Nginx 主站为 64 MiB，因此 10 MiB 默认值可沿用部署配置。UI 读取应用与 PHP 限额的较小值；自定义代理限额属于部署检查项，界面不声称能够自动识别所有上游限制。

复用现有路径守卫的方法，检查绝对路径、上级目录、反斜杠、控制字符、符号链接、非普通文件、重复条目、大小写冲突、加密条目、未知根目录和多个主题混装。新包格式允许的额外根文件仅有 `package.json`。文件类型限定为主题文档、Blade 与静态资源，拒绝独立 PHP、服务端入口、安装脚本、`.htaccess` 和可执行文件。

先枚举、核验清单与条目属性，再将允许文件流式写入独占 staging 并计算真实 SHA-256。所有条目与清单必须精确对应。导出同样先复制到独占快照，校验快照清单后打包，避免边编辑边导出产生混合版本。

检查通过表示包结构、完整性与已声明环境依赖通过。SHA-256 不证明作者身份，静态扫描也不构成 Blade 的服务端沙箱。导入仅面向管理员信任的开发者交付；来源确认发生在安装之前，预览只运行已安装的可信模板。现有复刻草稿的 `@php` 拦截与静态预览保持生效。

模板包中的私有标记用于后台展示和交付策略，不提供 DRM，也不会改变接收方设备上的 Git 配置。客户材料不得自动写入通用功能测试夹具或公开示例包。

管理路由使用 `admin.super`、现有登录校验、CSRF 和敏感操作限流。下载、检查报告和安装令牌均绑定管理员，默认 60 分钟过期。机会清理仅处理过期 uploads/exports/staging，获取同一目录操作锁后执行，保留进行中的任务与 installed。

## 7. 运行预览的实现范围

新增请求级 `SiteThemePreviewContext`，由管理员预览入口建立，主题解析器读取后仅影响当前请求。使用实际站点控制器的数据准备逻辑，不复制第二套文章查询或分页逻辑。

预览入口允许六种页面名及经过验证的分类、文章、年月参数。不存在的数据返回可理解的空态；禁止在预览请求中临时修改数据库站点设置。控制器直接调用对应 Home、Category、Article、About、Archive 控制器的数据流程，入口只复用 `site.locale`，不进入 `site.view_log`。

需要在 `Site/ArticleController.php` 的阅读计数入口、`SiteLayoutComposer` 的分析代码输出和线索表单上下文中接入预览标记。通过 Laravel 现有 URL path formatter，在预览上下文内将六个 `site.*` 页面路由映射到预览入口；保留分类/文章 slug、年月、查询参数和锚点，其他路由与资源不重写。`SiteUrlGenerator` 同样读取该上下文。原有 formatter 在渲染完成或异常时恢复，增加连续处理预览与普通请求的隔离测试。

返回前台视图前必须在预览上下文范围内完成渲染，防止延迟渲染在清理上下文后发生。模板硬编码的同源站内链接通过预览层拦截并映射，外站链接显式在新窗口打开；不把后台参数传给外站。普通访客无法借 query 参数选择未启用模板。

预览 iframe 限制表单提交、顶层导航和同源权限，响应禁用缓存。浏览器限制只负责预览交互隔离；模板来源可信仍是服务端执行的前提。

## 8. 拟新增接口与文件范围

管理接口统一置于配置化后台前缀的 `site-settings/theme-packages` 下，名称前缀为 `admin.site-settings.theme-packages.`。下表均为拟新增接口，当前不可调用。

| 方法与相对路径 | 用途 |
| --- | --- |
| `POST /exports` | 为选定 theme_id 创建导出快照，返回检查摘要和下载入口 |
| `GET /exports/{token}` | 校验管理员、期限后下载 ZIP |
| `GET /imports/create` | 上传页面 |
| `POST /imports` | 上传并检查，不执行主题 |
| `GET /imports/{token}` | 检查报告与可信来源确认 |
| `POST /imports/{token}/install` | 校验来源确认、令牌、指纹和冲突后安装 |
| `GET /installed/{themeId}/preview/{page}` | 预览已安装可信模板 |

公开资源接口沿用 `site.asset` 中的 `/themes/...`；主题启用继续使用现有 `admin.site-settings.theme`。

预计改动超过 8 个文件，新增 2 个业务服务和 1 个请求上下文类。完整首版需要一起交付，避免出现已导入而渲染或资源加载不可用的中间状态。

| 位置 | 工作 |
| --- | --- |
| `app/Services/Admin/SiteThemePackageService.php` | 新增：导出快照、标准清单、ZIP 检查、私有临时记录 |
| `app/Support/Site/InstalledSiteThemeRepository.php` | 新增：安装收据、目录归属、原子安装、锁和期限清理 |
| `app/Support/Site/SiteThemePreviewContext.php` | 新增：请求内主题与预览 URL 上下文 |
| `app/Http/Controllers/Admin/SiteThemePackageController.php` | 新增：管理端导出、上传、报告、安装 |
| `app/Http/Controllers/Admin/SiteThemePreviewController.php` | 新增：预览入口与六类页面适配 |
| `app/Http/Controllers/Site/HostedAssetController.php` | 扩展主站已安装模板的静态文件回退，保留托管站隔离 |
| `app/Support/Site/SiteThemeCatalog.php`、`SiteThemeViewResolver.php` | 合并主题来源、请求级解析 |
| `app/Providers/AppServiceProvider.php` | 注册已安装视图位置与 scoped 预览上下文 |
| `app/Http/Controllers/Admin/SiteSettingsController.php` | 导入模板的启用权限与状态校验 |
| `app/Http/Controllers/Site/ArticleController.php`、`app/Services/Site/SiteUrlGenerator.php` | 预览阅读计数抑制和 URL 适配；其余页面复用既有控制器 |
| `app/View/Composers/SiteLayoutComposer.php`、`resources/views/site/partials/lead-form.blade.php` | 预览分析代码抑制和只读表单展示 |
| `resources/views/admin/site-settings/index.blade.php` | 导入、导出与来源标记入口 |
| `resources/views/admin/site-theme-packages/` | 导出确认、上传、检查、安装结果、预览外壳 |
| `routes/web.php`、`config/geoflow.php` | 新路由、限额与期限配置 |
| `docker/nginx/local.conf`、`default.conf.template` | 静态资源回退与部署上传限额提示 |
| `lang/*/admin.php` | 当前支持语言的管理界面文案 |
| `tests/Feature/AdminSiteThemePackageTest.php` | 上传、导出、归属、权限、安装与冲突 |
| `tests/Feature/InstalledSiteThemeTest.php` | 渲染、公共 composer、资源路由、预览及启用 |
| `tests/Unit/SiteThemePackageGuardTest.php` | 包结构、校验值、路径、资源预算与损坏条目 |
| `tests/Unit/InstalledThemeDeploymentConfigurationTest.php` | 生产资源回退、持久化挂载契约 |

数据流保持单向：

```text
网站模板界面 → 管理控制器 → 包服务 → 已安装模板仓库
                              │            │
                         私有检查报告      ├→ Catalog / ViewResolver → 前台与管理员预览
                                           └→ 静态资源控制器 → 浏览器
```

只在服务端实际写入安装目录后回传成功。控制器不调用 Git、Composer、Artisan 安装命令或包内脚本。

## 9. 验收与失败恢复

通用自动化测试使用合成的 `customer-example` 模板，不使用客户姓名、Logo、原站图片和专属文案。

必须覆盖：

1. 导出未启用模板，ZIP 两个前缀完整，清单字节与 SHA-256 一致；公共依赖和私有属性保留。
2. 导出与回导前后模板内容指纹一致，目标实例的文章、分类、站点名称、首页模块、线索定义和 `active_theme` 不变。
3. 新实例导入成功，六类页面保留真实内容、SEO、公共 composer、表单及回退页面契约。
4. 重复原包返回已安装；同标识不同包、内置 ID 冲突、并发安装都不会覆盖已有主题。
5. 损坏 ZIP、错扩展、大小写重复路径、上级目录、符号链接、加密文件、清单外文件、少文件、校验值不符、超限实际解包均失败关闭。
6. 普通管理员、跨账号令牌、过期下载、缺少 CSRF、伪造来源确认均被拒绝。
7. 上传与检查期间恶意 Blade 不执行；安装阶段不主动渲染模板；现有复刻静态预览测试持续通过。
8. 预览不改变启用主题、不增加阅读计数、不运行统计、不接收线索提交；非管理员不能访问预览；搜索、分页和目录留在预览上下文。
9. 公共资源路由只返回允许的主题文件；Blade、收据、ZIP、跨主题路径不可读取，中文显示名称不影响路径校验。
10. 模拟磁盘写入失败、进程在 rename 前退出、安装后重复请求与清理并发，不出现半套可选模板，不误删 installed。
11. 在开发服务器及生产 Nginx/Compose 配置下分别打开 CSS、JS、图片和字体；重建应用容器后模板仍可选择和渲染。
12. 使用本地客户模板完成一次私有验收：源实例导出，干净客户测试实例导入，逐页比对。客户素材不进入公共测试目录。

实施后的验证命令：

```text
php artisan route:list --path=site-settings/theme-packages
php vendor/bin/phpunit tests/Feature/AdminSiteThemePackageTest.php tests/Feature/InstalledSiteThemeTest.php tests/Feature/AdminSiteThemeReplicationTest.php tests/Feature/AdminSiteThemeEditorTest.php tests/Unit/SiteThemePackageGuardTest.php tests/Unit/InstalledThemeDeploymentConfigurationTest.php
vendor/bin/pint --dirty --format agent
```

前端有脚本变更时执行对应 JavaScript 测试与现有构建；按项目 CI 补足必需检查。浏览器验证使用独立测试上下文，并检查移动端上传、检查报告和预览。

安装失败时仅清理本次独占暂存目录。已安装模板保持不变。启用后出现问题时，管理员切换回原模板；原模板无需重建。回滚本功能代码前，先切回仍受旧版本识别的内置主题，再回滚应用，保留 storage 中的客户包用于后续恢复。

## 10. 工作量、假设与交接

建议先交付「所选模板标准导出」，它独立可用于客户交付和人工安装；随后交付「完整导入、持久化解析、预览、启用权限、生产资源转发」，第二阶段一次完成。两阶段各自可合入且可使用，第一阶段不依赖第二阶段才能下载交付包。

估算：导出约 1 个工作日；导入、运行时适配和预览约 2–3 个工作日；跨实例与生产部署验收约 1 个工作日。以现有团队开发节奏和评审结果调整，优先验证生产静态资源链路。

最脆弱的假设是：交付模板仅依赖标准 GEOFlow 页面数据和已声明公共组件。原生 Blade 可以包含动态依赖，静态扫描无法证明所有运行条件。采用相同导出版本默认约束、显式依赖声明及目标实例预览共同缩小不确定性；跨业务插件或自定义后台逻辑的包由开发者补齐兼容声明与目标实例验证。

首版面向单个部署共享同一持久化文件系统的实例。多副本使用独立磁盘的拓扑不受支持，部署前需配置共享 storage；首版不加入跨节点文件同步。应用检查本节点的目录权限和原子 rename，跨节点共享由部署验收确认，不声称可以自动判断其他节点的磁盘。已有 Compose 属于共享 storage 方式，无需新增外部依赖。

最小交付选项为仅实现通用标准导出，客户由维护人员安装。完整推荐范围包含导入、持久化目录、安装后预览和单独启用，能够让客户在后台自行完成交付。

本轮仅记录客户模板私有交付约束并完成设计。没有开发上述接口，没有导出实际客户 ZIP，没有迁移运行目录，也没有触发提交、推送、部署或主题启用。

## 11. 已核验的官方能力

Laravel 12 的文件校验会检查实际文件内容，扩展名还需单独约束，参见 [Laravel 文件校验](https://laravel.com/docs/12.x/validation#validating-files)。并发控制沿用项目锁模式，并参考 [Laravel 原子锁](https://laravel.com/docs/12.x/cache#atomic-locks)。

ZipArchive 可按索引流式读取条目，并读取条目外部属性。导入器据此逐项检查和限额读取，参见 [getStreamIndex](https://www.php.net/manual/en/ziparchive.getstreamindex.php) 和 [getExternalAttributesIndex](https://www.php.net/manual/en/ziparchive.getexternalattributesindex.php)。
