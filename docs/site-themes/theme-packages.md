# GEOFlow 标准模板包

超级管理员可以在「网站设置 → 站点模板」导出所选模板，或使用「导入模板」上传标准 ZIP。流程为：导出、上传检查、确认可信来源并安装、预览、单独启用。安装保存到持久化 storage，公开站点继续使用当前主题。

## 使用方式

1. 选择需要交付的模板，点击「导出所选模板」，核对模板信息后下载 ZIP。默认主题使用系统页面，需选择具有 manifest 的命名模板导出。
2. 在目标实例上传 ZIP，查看名称、版本、文件清单、SHA-256、自带与回退页面、环境依赖及客户私有备注。展开文件清单后，可逐个查看图片和转义后的源码；Blade、脚本和 SVG 以文本展示，查看时不执行。
3. 确认拥有使用授权且来源可信，再安装。相同标识、相同文件内容返回已有安装；同名不同内容或与本地模板重名时拒绝覆盖。
4. 查看首页、分类、文章详情、关于页面、归档总览及月度归档。使用已有已发布内容；缺少内容时显示说明。
5. 在站点模板列表单独选择并启用。出现兼容问题时可切回原模板。

仅迁移模板自身的视图、脚本、样式、字体和图片。文章、分类、账号、线索、表单定义、站点配置和首页模块配置保留在各实例。客户私有备注属于交付文件的一部分；交付者继续负责客户素材的使用和发布范围。

## 包格式 v1

```text
package.json
resources/views/theme/{theme_id}/manifest.json
resources/views/theme/{theme_id}/*.blade.php
resources/views/theme/{theme_id}/partials/...
public/themes/{theme_id}/...
```

`package.json` 的 `format` 为 `geoflow-theme-package`，`format_version` 为 `1`，包含：

- `theme`：`id`、`name`、`version`。
- `created_at`、`exported_with`：实际 GEOFlow、PHP、Laravel 版本，以及 `contracts`。
- `requires`：版本范围、`contracts: {"site-theme-view-resolver": 1}`，以及 `views`、`routes`、`assets` 运行依赖列表。
- `distribution`：原有分发范围和客户私有备注。
- `pages`：六类页面的 `provided` 和 `fallback` 列表。
- `files`：逐文件的逻辑 `path`、实际 `bytes` 和小写 `sha256`。
- `content_sha256`：将文件按 path 字典序排序，逐项拼接 `path + NUL + bytes + NUL + sha256 + LF` 后计算 SHA-256。

`manifest.json` 的原始内容保留。`base_template_id` 表示设计来源；实际引用其他内置模板文件时，依赖检查会验证这些文件存在。来自其他已安装包的依赖链暂不支持。公共视图和素材由目标实例提供，检查阶段核验它们是否存在。

导出默认锁定当前 GEOFlow 版本。包作者可以在 manifest 的 `requires` 中声明经过验证的版本范围，并列出动态引用的视图、路由与素材。无法确定且未声明的依赖会阻止导出或检查；已声明的动态依赖在检查报告中保留提示。

依赖扫描支持 `includeIsolated`，忽略 `@verbatim` 与 `@@` 转义指令。`includeIf` 的静态引用可以缺失；`includeFirst` 在每次检查时确认至少一个候选存在，导出不会将单个候选固定为必需依赖。manifest 或包内显式声明的依赖仍必须满足。

## 权限与验证

包管理路由位于配置化后台前缀下的 `site-settings/theme-packages`。上传、下载、检查、安装和预览要求超级管理员权限；导入模板的启用也要求此权限。令牌绑定管理员并在 60 分钟后过期。

ZIP 默认上限 10 MiB、500 个文件、单文件 5 MiB、解包总量 25 MiB，可在 `config/geoflow.php` 的 `theme_packages` 中调整。上传页会显示 PHP 与应用共同允许的有效 ZIP 上限。`package.json` 另限 1 MiB。

内部报告与安装收据以紧凑 JSON 保存并统一限制为 2 MiB；编码失败、深度超限或无法在限制内保存时会拒绝操作并清理暂存。调低导入限额只影响新的包操作，已安装模板继续按原安装收据加载。

校验包含真实解码大小、文件清单一致性、路径越界、重复及大小写冲突、加密、软链接和非普通文件。检查阶段不编译 Blade。安装前重检包与文件，使用独占暂存目录、同主题锁和原子目录重命名完成安装。下载流与过期清理共用锁。

检查文件查看入口复用管理员令牌与操作锁，并重新核对整个 ZIP 和目标文件的摘要。读取完成后仅将图片或转义文本返回页面，支持的图片以内嵌数据展示；字体等二进制文件显示路径、大小与校验值。

原生 Blade 可以在服务器执行 PHP。SHA-256 用于验证内容完整性，不能证明作者身份。应仅安装可信开发者交付的模板。管理员预览使用请求内主题上下文及受限制的 iframe，抑制阅读计数、分析代码和表单提交。已有 AI 复刻草稿的受限检查与静态预览继续独立工作。

## 持久化与生产部署

目录位于 local 磁盘的 `geoflow-site-themes` 下，默认路径为 `storage/app/private/geoflow-site-themes`：

- `installed/{theme_id}`：逻辑视图、素材和 `installation.json` 收据。
- `uploads/{admin_id}/{token}`、`exports/{admin_id}/{token}`：临时包与报告。
- `staging/{token}`：独占安装暂存目录。
- `locks`：安装、下载与清理锁。

只发现具有完整文件与合法收据的模板。机会清理处理过期临时目录，并跳过持锁操作和 installed。应用更新或容器重建时必须保留整个 storage。现有生产 Compose 已挂载此目录；多副本部署需共享同一文件系统。

生产 Nginx 对 `/themes/` 中允许的素材先读取 public 内置文件，找不到时交由应用解析已安装素材。应用仅返回清单中的允许文件，带 MIME、缓存和 `nosniff`。公开素材允许无凭据跨源读取，以支持沙箱预览中的字体和模块脚本；私有 storage 文件不开放此权限。托管站点继续使用已认证内置主题规则。不要把 private 目录直接映射成公开目录。

回滚本功能代码前，应先切换到旧代码可识别的内置模板，并保留 storage 中的包以便恢复。本功能无需新增数据库迁移。
